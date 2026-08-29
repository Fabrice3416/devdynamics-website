<?php
declare(strict_types=1);

/**
 * Module Comptes - partie double allegee, reglements et rapprochement (CDC 4.1 a 4.9).
 *
 * Six familles de comptes suffisent a couvrir la totalite des operations : la
 * tresorerie, les tiers, la dette envers la DGI, les avances a regulariser, les
 * charges - un compte par ligne budgetaire imputable - et les financements.
 *
 * Le reglement appartient a ce module et non a Depenses : le dossier demande
 * l'execution d'un reglement, Comptes le produit, l'enregistre et l'ecrit en partie
 * double (CDC 4.9). C'est pourquoi le reglement porte son beneficiaire, ce qui rend
 * la regle de conflit d'interets verifiable au moment de la signature.
 *
 * L'ecriture porte le module d'origine et l'identifiant de l'objet en valeurs, sans
 * cle etrangere : Comptes reste ignorant des modules qui l'appellent (CDC 8.3).
 */

require_once __DIR__ . '/calendrier.php';
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/documents.php';   // journal de caisse, rapprochement (annexe E)

const FAMILLES_COMPTES = [
    'banque'      => 'Trésorerie — banque',
    'caisse'      => 'Trésorerie — petite caisse',
    'tiers'       => 'Tiers, fournisseurs et prestataires',
    'dette_dgi'   => 'Dette envers la DGI',
    'avances'     => 'Avances à régulariser',
    'charge'      => 'Charges',
    'financement' => 'Financements',
    'produit'     => 'Produits financiers',
];

/** Les comptes dont le solde debiteur represente de l'argent detenu. */
const COMPTES_TRESORERIE = ['banque', 'caisse'];

const MODES_REGLEMENT = [
    'virement'       => 'Virement',
    'cheque'         => 'Chèque',
    'especes_caisse' => 'Espèces, par la petite caisse',
];

const NATURES_VALIDATION = [
    'autorisation_electronique' => 'Autorisation électronique interne',
    'signature_bancaire'        => 'Signature bancaire manuscrite',
];

const NATURES_LIGNE_RAPPROCHEMENT = [
    'encaissement_transit' => 'Encaissement en transit',
    'cheque_non_encaisse'  => 'Chèque émis non encaissé',
    'mouvement_etranger'   => 'Mouvement étranger au projet',
    'autre'                => 'Autre',
];

// ---------------------------------------------------------------------
// Plan de comptes et balance
// ---------------------------------------------------------------------

function comptes_plan(?int $projetId = null): array
{
    $st = db()->prepare(
        "SELECT c.*, l.code AS ligne_code, l.libelle AS ligne_libelle
           FROM comptes c LEFT JOIN lignes_budgetaires l ON l.id = c.ligne_id
          WHERE c.projet_id = ? ORDER BY FIELD(c.type, 'banque','caisse','tiers','dette_dgi','avances','financement','produit','charge'), c.code"
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function compte_par_code(string $code, ?int $projetId = null): ?array
{
    $st = db()->prepare('SELECT * FROM comptes WHERE projet_id = ? AND code = ?');
    $st->execute([$projetId ?? projet_id(), $code]);
    $c = $st->fetch();
    return $c === false ? null : $c;
}

/** Le compte de charge d'une ligne budgetaire imputable. */
function compte_charge_de_ligne(int $ligneId, ?int $projetId = null): ?array
{
    $st = db()->prepare("SELECT * FROM comptes WHERE projet_id = ? AND type = 'charge' AND ligne_id = ?");
    $st->execute([$projetId ?? projet_id(), $ligneId]);
    $c = $st->fetch();
    return $c === false ? null : $c;
}

/**
 * Balance de tous les comptes du projet. Le solde est signe selon la famille :
 * debiteur pour la tresorerie et les charges, crediteur pour les tiers, la dette
 * et les financements, de sorte qu'un solde positif ait partout le meme sens
 * - « ce que le compte represente reellement ».
 */
function balance(?int $projetId = null, ?string $jusquAu = null): array
{
    $pid = $projetId ?? projet_id();
    $sql = "SELECT m.compte_id,
                   COALESCE(SUM(CASE WHEN m.sens = 'D' THEN m.montant ELSE 0 END), 0) AS debit,
                   COALESCE(SUM(CASE WHEN m.sens = 'C' THEN m.montant ELSE 0 END), 0) AS credit
              FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
             WHERE m.projet_id = ?";
    $args = [$pid];
    if ($jusquAu !== null) {
        $sql .= ' AND e.date <= ?';
        $args[] = $jusquAu;
    }
    $sql .= ' GROUP BY m.compte_id';
    $st = db()->prepare($sql);
    $st->execute($args);
    $par = [];
    foreach ($st->fetchAll() as $r) {
        $par[(int)$r['compte_id']] = ['debit' => (float)$r['debit'], 'credit' => (float)$r['credit']];
    }

    $lignes = [];
    foreach (comptes_plan($pid) as $c) {
        $d = $par[(int)$c['id']]['debit'] ?? 0.0;
        $cr = $par[(int)$c['id']]['credit'] ?? 0.0;
        $debiteur = in_array($c['type'], ['banque', 'caisse', 'charge', 'avances'], true);
        $lignes[] = $c + [
            'debit'  => $d,
            'credit' => $cr,
            'solde'  => round($debiteur ? $d - $cr : $cr - $d, 2),
            'sens_naturel' => $debiteur ? 'D' : 'C',
        ];
    }
    return $lignes;
}

function solde_compte(int $compteId, ?string $jusquAu = null): float
{
    $sql = "SELECT COALESCE(SUM(CASE WHEN m.sens = 'D' THEN m.montant ELSE -m.montant END), 0)
              FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
             WHERE m.compte_id = ?";
    $args = [$compteId];
    if ($jusquAu !== null) {
        $sql .= ' AND e.date <= ?';
        $args[] = $jusquAu;
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return round((float)$st->fetchColumn(), 2);
}

// ---------------------------------------------------------------------
// Ecritures
// ---------------------------------------------------------------------

/**
 * Pose une ecriture et ses mouvements. Refuse tout ce qui ne tient pas debout :
 * une ecriture desequilibree, un compte d'un autre projet, un montant nul.
 *
 * @param array $entete      date, libelle, type, origine_module, origine_ref, reglement_id?
 * @param array $mouvements  [['compte_id'=>, 'sens'=>'D'|'C', 'montant'=>, 'tiers_id'=>?, 'depense_reportee'=>?, 'observation'=>?], ...]
 * @return int identifiant de l'ecriture
 */
function ecriture_poser(array $entete, array $mouvements): int
{
    $pid = projet_id();
    if (count($mouvements) < 2) {
        throw new RuntimeException('Une écriture compte au moins deux mouvements.');
    }
    $debit = $credit = 0.0;
    foreach ($mouvements as $m) {
        if (!in_array($m['sens'] ?? '', ['D', 'C'], true)) {
            throw new RuntimeException('Sens de mouvement invalide.');
        }
        $montant = round((float)($m['montant'] ?? 0), 2);
        if ($montant <= 0) {
            throw new RuntimeException('Un mouvement porte un montant strictement positif.');
        }
        $m['sens'] === 'D' ? $debit += $montant : $credit += $montant;

        // Cloisonnement : une ecriture ne touche que les comptes de son projet.
        $st = db()->prepare('SELECT projet_id FROM comptes WHERE id = ?');
        $st->execute([(int)($m['compte_id'] ?? 0)]);
        $cp = $st->fetchColumn();
        if ($cp === false) {
            throw new RuntimeException('Compte inconnu.');
        }
        if ((int)$cp !== $pid) {
            throw new RuntimeException('Compte appartenant à un autre projet.');
        }
    }
    if (abs(round($debit - $credit, 2)) >= 0.01) {
        throw new RuntimeException(sprintf('Écriture déséquilibrée : %s au débit, %s au crédit.', htg($debit), htg($credit)));
    }

    $date = (string)($entete['date'] ?? date('Y-m-d'));
    $periode = periode_pour_date($date);

    $pdo = db();
    // MySQL ne connait pas les transactions imbriquees : si l'appelant en a deja
    // ouvert une - c'est le cas de l'execution d'un reglement -, l'ecriture s'y
    // inscrit au lieu d'en ouvrir une seconde qui validerait trop tot.
    $transactionExterne = $pdo->inTransaction();
    if (!$transactionExterne) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare(
            'INSERT INTO ecritures (projet_id, date, periode_id, libelle, type, origine_module, origine_ref, reglement_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$pid, $date, $periode['id'] ?? null, (string)$entete['libelle'], (string)$entete['type'],
                    (string)$entete['origine_module'], (string)$entete['origine_ref'],
                    $entete['reglement_id'] ?? null, user_id()]);
        $ecritureId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO mouvements (projet_id, ecriture_id, compte_id, tiers_id, sens, montant, depense_reportee, observation)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($mouvements as $m) {
            $ins->execute([$pid, $ecritureId, (int)$m['compte_id'], $m['tiers_id'] ?? null, $m['sens'],
                           round((float)$m['montant'], 2), !empty($m['depense_reportee']) ? 1 : 0,
                           $m['observation'] ?? null]);
        }

        // La trace accompagne le mouvement d'argent ou rien ne bouge.
        audit_strict('comptes', 'ecriture_posee', 'ecriture', $ecritureId,
            $entete['type'] . ' · ' . $entete['libelle'] . ' · ' . htg($debit) . ' · origine ' . $entete['origine_module'] . ':' . $entete['origine_ref']);
        if (!$transactionExterne) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$transactionExterne) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $ecritureId;
}

/** Les ecritures d'un projet, de la plus recente a la plus ancienne. */
function ecritures(?int $projetId = null, int $limit = 200): array
{
    $st = db()->prepare(
        'SELECT e.*, r.numero AS reglement_numero
           FROM ecritures e LEFT JOIN reglements r ON r.id = e.reglement_id
          WHERE e.projet_id = ? ORDER BY e.date DESC, e.id DESC LIMIT ' . (int)$limit
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function mouvements_ecriture(int $ecritureId): array
{
    $st = db()->prepare(
        'SELECT m.*, c.code, c.libelle, c.type, t.nom AS tiers_nom
           FROM mouvements m JOIN comptes c ON c.id = m.compte_id
           LEFT JOIN tiers t ON t.id = m.tiers_id
          WHERE m.ecriture_id = ? ORDER BY m.sens DESC, m.id'
    );
    $st->execute([$ecritureId]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Les six ecritures types du CDC 4.8
// Elles couvrent la totalite des operations : tout le reste s'y ramene.
// ---------------------------------------------------------------------

/** 1. L'encaissement d'une tranche debite la banque et credite le financement. */
function ecriture_encaissement_tranche(int $compteBanqueId, int $compteFinancementId, float $montant, string $date, string $libelle, string $origineRef): int
{
    return ecriture_poser(
        ['date' => $date, 'libelle' => $libelle, 'type' => 'encaissement_tranche',
         'origine_module' => 'financement', 'origine_ref' => $origineRef],
        [['compte_id' => $compteBanqueId, 'sens' => 'D', 'montant' => $montant],
         ['compte_id' => $compteFinancementId, 'sens' => 'C', 'montant' => $montant]]
    );
}

/** 2. La facture recue debite la charge de la ligne et credite le fournisseur. */
function ecriture_facture(int $ligneId, int $fournisseurId, float $montant, string $date, string $libelle, string $origineRef): int
{
    $charge = compte_charge_de_ligne($ligneId);
    $tiers  = compte_par_code('TI');
    if ($charge === null || $tiers === null) {
        throw new RuntimeException('Plan de comptes incomplet : compte de charge ou compte de tiers absent.');
    }
    return ecriture_poser(
        ['date' => $date, 'libelle' => $libelle, 'type' => 'facture',
         'origine_module' => 'depenses', 'origine_ref' => $origineRef],
        [['compte_id' => (int)$charge['id'], 'sens' => 'D', 'montant' => $montant],
         ['compte_id' => (int)$tiers['id'], 'sens' => 'C', 'montant' => $montant, 'tiers_id' => $fournisseurId]]
    );
}

/**
 * 3. Le reglement debite le fournisseur et credite la tresorerie.
 *
 * Une exception : le renflouement de la petite caisse ne paie personne, il
 * deplace de l'argent d'un compte de tresorerie a l'autre. Le cheque est bien
 * emis au nom du detenteur du fonds - jamais au porteur - mais celui-ci n'est pas
 * un beneficiaire au sens comptable, et la contrepartie est la caisse.
 */
function ecriture_reglement(array $reglement): int
{
    $origine = (string)$reglement['origine_ref'];
    if (str_starts_with($origine, 'renflouement:')) {
        $type = 'caisse';
        $debit = ['compte_id' => (int)substr($origine, 13), 'sens' => 'D', 'montant' => (float)$reglement['montant']];
    } elseif (str_starts_with($origine, 'versement_dgi:')) {
        // « Le versement a la DGI debite la dette et credite la banque » (CDC 4.8) :
        // c'est l'ecriture type, a laquelle on delegue plutot que de la refaire ici.
        return ecriture_versement_dgi((int)$reglement['compte_id'], (float)$reglement['montant'],
            (string)$reglement['date_reglement'],
            'Règlement ' . $reglement['numero'] . ' — ' . $reglement['objet'],
            'reglement:' . $reglement['id'], (int)$reglement['id']);
    } elseif (str_starts_with($origine, 'dossier_avance:')) {
        // « Le remboursement de frais avances debite la charge et credite le compte
        // d'avances, que le reglement solde ensuite » (CDC 4.8). Le reglement est ce
        // solde : il debite les avances et jamais un compte de tiers, l'avanceur
        // etant un membre de l'equipe et non un fournisseur.
        $avances = compte_par_code('AV');
        if ($avances === null) {
            throw new RuntimeException('Plan de comptes incomplet : compte d\'avances absent.');
        }
        $type = 'remboursement_frais';
        $debit = ['compte_id' => (int)$avances['id'], 'sens' => 'D', 'montant' => (float)$reglement['montant'],
                  'tiers_id' => (int)$reglement['beneficiaire_id']];
    } else {
        $tiers = compte_par_code('TI');
        if ($tiers === null) {
            throw new RuntimeException('Plan de comptes incomplet : compte de tiers absent.');
        }
        $type = 'reglement';
        $debit = ['compte_id' => (int)$tiers['id'], 'sens' => 'D', 'montant' => (float)$reglement['montant'],
                  'tiers_id' => (int)$reglement['beneficiaire_id']];
    }
    return ecriture_poser(
        ['date' => (string)$reglement['date_reglement'], 'libelle' => 'Règlement ' . $reglement['numero'] . ' — ' . $reglement['objet'],
         'type' => $type, 'origine_module' => 'comptes', 'origine_ref' => 'reglement:' . $reglement['id'],
         'reglement_id' => (int)$reglement['id']],
        [$debit,
         ['compte_id' => (int)$reglement['compte_id'], 'sens' => 'C', 'montant' => (float)$reglement['montant']]]
    );
}

/**
 * Recette du projet. « Les recettes, notamment les interets crediteurs du compte,
 * sont enregistrees et declarees, une recette non communiquee figurant parmi les
 * causes d'inegibilite » (CDC 4.1). Elle debite la tresorerie et credite le
 * compte de produits.
 */
function ecriture_recette(int $compteTresorerieId, float $montant, string $date, string $libelle, string $origineRef): int
{
    $produit = compte_par_code('PROD');
    if ($produit === null) {
        throw new RuntimeException('Plan de comptes incomplet : compte de produits financiers absent.');
    }
    return ecriture_poser(
        ['date' => $date, 'libelle' => $libelle, 'type' => 'produit',
         'origine_module' => 'comptes', 'origine_ref' => $origineRef],
        [['compte_id' => $compteTresorerieId, 'sens' => 'D', 'montant' => $montant],
         ['compte_id' => (int)$produit['id'], 'sens' => 'C', 'montant' => $montant]]
    );
}

/**
 * 4. Les honoraires debitent la charge pour le brut, et creditent le prestataire
 * pour le net et la DGI pour l'acompte retenu.
 */
function ecriture_honoraires(int $ligneId, int $prestataireId, float $brut, float $acompte, string $date, string $libelle, string $origineRef): int
{
    $charge = compte_charge_de_ligne($ligneId);
    $tiers  = compte_par_code('TI');
    $dgi    = compte_par_code('DGI');
    if ($charge === null || $tiers === null || $dgi === null) {
        throw new RuntimeException('Plan de comptes incomplet pour les honoraires.');
    }
    $net = round($brut - $acompte, 2);
    return ecriture_poser(
        ['date' => $date, 'libelle' => $libelle, 'type' => 'honoraires',
         'origine_module' => 'remuneration', 'origine_ref' => $origineRef],
        [['compte_id' => (int)$charge['id'], 'sens' => 'D', 'montant' => $brut],
         ['compte_id' => (int)$tiers['id'], 'sens' => 'C', 'montant' => $net, 'tiers_id' => $prestataireId],
         ['compte_id' => (int)$dgi['id'], 'sens' => 'C', 'montant' => $acompte]]
    );
}

/** 5. Le versement a la DGI debite la dette et credite la banque. */
function ecriture_versement_dgi(int $compteBanqueId, float $montant, string $date, string $libelle,
                                string $origineRef, ?int $reglementId = null): int
{
    $dgi = compte_par_code('DGI');
    if ($dgi === null) {
        throw new RuntimeException('Plan de comptes incomplet : compte de dette DGI absent.');
    }
    return ecriture_poser(
        ['date' => $date, 'libelle' => $libelle, 'type' => 'versement_dgi',
         'origine_module' => 'remuneration', 'origine_ref' => $origineRef, 'reglement_id' => $reglementId],
        [['compte_id' => (int)$dgi['id'], 'sens' => 'D', 'montant' => $montant],
         ['compte_id' => $compteBanqueId, 'sens' => 'C', 'montant' => $montant]]
    );
}

/**
 * 6. Le remboursement de frais avances debite la charge et credite le compte
 * d'avances, que le reglement solde ensuite.
 */
function ecriture_remboursement_frais(int $ligneId, int $avanceurId, float $montant, string $date, string $libelle, string $origineRef): int
{
    $charge  = compte_charge_de_ligne($ligneId);
    $avances = compte_par_code('AV');
    if ($charge === null || $avances === null) {
        throw new RuntimeException('Plan de comptes incomplet : compte de charge ou compte d\'avances absent.');
    }
    return ecriture_poser(
        ['date' => $date, 'libelle' => $libelle, 'type' => 'remboursement_frais',
         'origine_module' => 'depenses', 'origine_ref' => $origineRef],
        [['compte_id' => (int)$charge['id'], 'sens' => 'D', 'montant' => $montant],
         ['compte_id' => (int)$avances['id'], 'sens' => 'C', 'montant' => $montant, 'tiers_id' => $avanceurId]]
    );
}

// ---------------------------------------------------------------------
// Reglements
// ---------------------------------------------------------------------

/**
 * Numero interne du reglement, propre au projet et strictement croissant. Un
 * reglement annule garde le sien : la sequence ne se rejoue pas, sans quoi deux
 * pieces porteraient le meme numero a deux dates differentes (CDC 5.2).
 */
function reglement_numero_suivant(?int $projetId = null): string
{
    $st = db()->prepare("SELECT numero FROM reglements WHERE projet_id = ? AND numero LIKE 'REG-%' ORDER BY id DESC LIMIT 1");
    $st->execute([$projetId ?? projet_id()]);
    $dernier = (string)($st->fetchColumn() ?: 'REG-0000');
    return sprintf('REG-%04d', ((int)substr($dernier, 4)) + 1);
}

/**
 * Numero de piece comptable : numero de rubrique et sequence propre a cette
 * rubrique, par exemple 03-014 pour la quatorzieme piece du Bureau local. La
 * sequence appartient au couple projet et rubrique, de sorte que la piece 03-014
 * de KesKle et celle de Koule Ki Pale coexistent sans ambiguite (CDC 5.2).
 */
function numero_piece_suivant(int $rubrique, ?int $projetId = null): string
{
    $prefixe = sprintf('%02d-', $rubrique);
    $st = db()->prepare(
        'SELECT numero_piece FROM imputations WHERE projet_id = ? AND numero_piece LIKE ?
          ORDER BY numero_piece DESC LIMIT 1'
    );
    $st->execute([$projetId ?? projet_id(), $prefixe . '%']);
    $dernier = (string)($st->fetchColumn() ?: ($prefixe . '000'));
    return $prefixe . sprintf('%03d', ((int)substr($dernier, 3)) + 1);
}

/**
 * Cree une demande de reglement. Les six regles de decaissement du CDC 4.1 sont
 * des contraintes, pas des recommandations : elles s'appliquent ici sans exception.
 *
 * @return array{success: bool, id?: int, numero?: string, error?: string}
 */
function reglement_creer(array $d): array
{
    $pid = projet_id();
    $mode = (string)($d['mode'] ?? 'virement');
    $montant = round((float)($d['montant'] ?? 0), 2);
    $devise = strtoupper((string)($d['devise'] ?? 'HTG'));

    if (!array_key_exists($mode, MODES_REGLEMENT)) {
        return ['success' => false, 'error' => 'Mode de règlement hors liste.'];
    }
    if ($montant <= 0) {
        return ['success' => false, 'error' => 'Le montant doit être strictement positif.'];
    }

    $st = db()->prepare('SELECT * FROM comptes WHERE id = ? AND projet_id = ?');
    $st->execute([(int)($d['compte_id'] ?? 0), $pid]);
    $compte = $st->fetch();
    if ($compte === false) {
        return ['success' => false, 'error' => 'Compte de trésorerie inconnu dans ce projet.'];
    }
    if (!in_array($compte['type'], COMPTES_TRESORERIE, true)) {
        return ['success' => false, 'error' => 'Un règlement sort d\'un compte de trésorerie, banque ou petite caisse.'];
    }

    // « L'espece n'est admise que par la petite caisse » (CDC 4.1).
    if ($mode === 'especes_caisse' && $compte['type'] !== 'caisse') {
        return ['success' => false, 'error' => 'Un règlement en espèces ne sort que de la petite caisse.'];
    }
    if ($mode !== 'especes_caisse' && $compte['type'] === 'caisse') {
        return ['success' => false, 'error' => 'La petite caisse ne règle qu\'en espèces.'];
    }
    if ($mode === 'cheque' && trim((string)($d['numero_cheque'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Le numéro du chèque est obligatoire.'];
    }
    if ($mode === 'especes_caisse') {
        $plafond = param('plafond_depense_especes');
        if ($plafond !== null && $montant > (float)$plafond) {
            return ['success' => false, 'error' => sprintf('Dépense en espèces plafonnée à %s.', htg((float)$plafond))];
        }
        $disponible = solde_compte((int)$compte['id']);
        if ($montant > $disponible) {
            return ['success' => false, 'error' => sprintf('Solde de caisse insuffisant : %s disponible.', htg($disponible))];
        }
    }

    // « Tous les paiements se font en gourdes » : une devise d'origine se documente,
    // elle ne devient jamais l'unite de compte (CDC 4.2 et 4.7).
    if ($devise !== 'HTG') {
        if (empty($d['taux_change']) || empty($d['montant_devise'])) {
            return ['success' => false, 'error' => 'Une opération en devise porte son montant, son taux et son montant en gourdes.'];
        }
        if (empty($d['preuve_taux_fichier_id'])) {
            return ['success' => false, 'error' => 'La preuve du taux est une pièce obligatoire : l\'usage incorrect d\'un taux est une cause d\'inéligibilité.'];
        }
    }

    $benef = (int)($d['beneficiaire_id'] ?? 0);
    $sb = db()->prepare('SELECT COUNT(*) FROM tiers WHERE id = ?');
    $sb->execute([$benef]);
    if ((int)$sb->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Bénéficiaire inconnu au référentiel.'];
    }

    // « Un reglement ne peut porter que sur une seule ligne budgetaire » (CDC 4.1).
    // Cette regle-la est tenue par la base, ou uk_imputation_dossier interdit une
    // seconde imputation sur le meme dossier : il n'y a rien a verifier ici.
    //
    // Ce qui reste a verifier, c'est le montant. Une ligne au forfait accepte un
    // reglement en deux temps, avance puis solde, mais la somme des reglements
    // d'un dossier ne depasse pas ce qui y a ete impute.
    $origineRef = (string)($d['origine_ref'] ?? '');
    $dossierId = reglement_dossier_id($origineRef);
    if ($dossierId !== null) {
        $si = db()->prepare('SELECT montant FROM imputations WHERE dossier_id = ? AND projet_id = ?');
        $si->execute([$dossierId, $pid]);
        $impute = $si->fetchColumn();
        if ($impute !== false) {
            $sr = db()->prepare(
                "SELECT COALESCE(SUM(montant), 0) FROM reglements
                  WHERE projet_id = ? AND origine_ref = ? AND statut <> 'annule'"
            );
            $sr->execute([$pid, $origineRef]);
            $deja = (float)$sr->fetchColumn();
            $reste = round((float)$impute - $deja, 2);
            if ($montant > $reste + 0.005) {
                return ['success' => false, 'error' => sprintf(
                    'Ce dossier a été imputé de %s, dont %s déjà réglés : il reste %s à régler.',
                    htg((float)$impute), htg($deja), htg($reste))];
            }
        }
    }

    $numero = reglement_numero_suivant($pid);
    try {
        db()->prepare(
            'INSERT INTO reglements (projet_id, numero, mode, numero_cheque, beneficiaire_id, compte_id,
                                     compte_bancaire_id, montant, devise, montant_devise, taux_change,
                                     preuve_taux_fichier_id, objet, origine_module, origine_ref, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$pid, $numero, $mode, trim((string)($d['numero_cheque'] ?? '')) ?: null, $benef,
                    (int)$compte['id'], $compte['compte_bancaire_id'], $montant, $devise,
                    $d['montant_devise'] ?? null, $d['taux_change'] ?? null,
                    $d['preuve_taux_fichier_id'] ?? null, (string)($d['objet'] ?? ''),
                    (string)($d['origine_module'] ?? 'comptes'), $origineRef, user_id()]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('reglement_creer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Enregistrement impossible.'];
    }
    audit('comptes', 'reglement_demande', 'reglement', $id,
        $numero . ' · ' . MODES_REGLEMENT[$mode] . ' · ' . htg($montant) . ' · ' . ($d['objet'] ?? ''));
    return ['success' => true, 'id' => $id, 'numero' => $numero];
}

/**
 * L'identifiant du dossier qui a demande ce reglement, quelle que soit la forme de
 * son origine. Un remboursement de frais avances porte un prefixe distinct, parce
 * que son ecriture debite les avances et non un compte de tiers.
 */
function reglement_dossier_id(string $origineRef): ?int
{
    foreach (['dossier:', 'dossier_avance:'] as $prefixe) {
        if (str_starts_with($origineRef, $prefixe)) {
            return (int)substr($origineRef, strlen($prefixe));
        }
    }
    return null;
}

function reglement(int $id): ?array
{
    $st = db()->prepare(
        'SELECT r.*, t.nom AS beneficiaire_nom, t.est_mandataire AS beneficiaire_mandataire,
                c.code AS compte_code, c.libelle AS compte_libelle, c.type AS compte_type,
                u.tiers_id AS preparateur_tiers_id, p.nom AS preparateur_nom
           FROM reglements r
           JOIN tiers t ON t.id = r.beneficiaire_id
           JOIN comptes c ON c.id = r.compte_id
           JOIN utilisateurs u ON u.id = r.created_by
           JOIN tiers p ON p.id = u.tiers_id
          WHERE r.id = ? AND r.projet_id = ?'
    );
    $st->execute([$id, projet_id()]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function validations_reglement(int $reglementId): array
{
    $st = db()->prepare(
        'SELECT v.*, t.nom AS mandataire_nom FROM validations_reglement v
           JOIN tiers t ON t.id = v.mandataire_id WHERE v.reglement_id = ? ORDER BY v.id'
    );
    $st->execute([$reglementId]);
    return $st->fetchAll();
}

/**
 * Qui peut signer ce reglement, et pourquoi pas.
 *
 * Trois exclusions, toutes du CDC 4.1 et de l'annexe H : il faut etre mandataire,
 * on ne signe pas un reglement dont on est le beneficiaire - « un mandataire
 * beneficiaire est exclu du couple signataire » - et on ne signe pas ce que l'on a
 * soi-meme libelle, l'autorisation etant donnee par deux personnes distinctes de
 * celle qui prepare.
 *
 * @return string[] les motifs de refus ; vide, la signature est recevable
 */
function reglement_controle_signature(array $reglement, int $mandataireTiersId, string $nature): array
{
    $refus = [];
    $st = db()->prepare('SELECT nom, est_mandataire FROM tiers WHERE id = ?');
    $st->execute([$mandataireTiersId]);
    $t = $st->fetch();
    if ($t === false) {
        return ['Signataire inconnu au référentiel.'];
    }
    if (!$t['est_mandataire']) {
        $refus[] = $t['nom'] . ' n\'est pas mandataire du compte.';
    }
    if ($mandataireTiersId === (int)$reglement['beneficiaire_id']) {
        $refus[] = 'Un mandataire bénéficiaire est exclu du couple signataire : ' . $t['nom'] . ' est le bénéficiaire de ce règlement.';
    }
    if ($mandataireTiersId === (int)$reglement['preparateur_tiers_id']) {
        $refus[] = 'Les deux autorisations sont données par des personnes distinctes de celle qui a libellé le règlement.';
    }
    if (!array_key_exists($nature, NATURES_VALIDATION)) {
        $refus[] = 'Nature de validation hors liste.';
    }
    foreach (validations_reglement((int)$reglement['id']) as $v) {
        if ((int)$v['mandataire_id'] === $mandataireTiersId && $v['nature'] === $nature) {
            $refus[] = $t['nom'] . ' a déjà donné cette validation.';
        }
    }
    if (!in_array($reglement['statut'], ['demande', 'autorise'], true)) {
        $refus[] = 'Un règlement ' . $reglement['statut'] . ' ne se signe plus.';
    }
    return $refus;
}

/**
 * Enregistre une validation. Deux mandataires distincts ayant autorise, le
 * reglement passe autorise : « toute sortie de fonds est autorisee par deux
 * mandataires » (CDC 4.1).
 *
 * @return array{success: bool, error?: string, autorise?: bool}
 */
function reglement_valider(int $reglementId, int $mandataireTiersId, string $nature, ?int $appositionId = null, ?string $date = null): array
{
    $r = reglement($reglementId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Règlement inconnu dans ce projet.'];
    }
    $refus = reglement_controle_signature($r, $mandataireTiersId, $nature);
    if ($refus) {
        return ['success' => false, 'error' => implode(' ', $refus)];
    }
    try {
        db()->prepare(
            'INSERT INTO validations_reglement (projet_id, reglement_id, mandataire_id, nature, apposition_id, date, saisi_par)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([projet_id(), $reglementId, $mandataireTiersId, $nature, $appositionId, $date ?? date('Y-m-d'), user_id()]);
    } catch (Throwable $e) {
        error_log('reglement_valider: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Validation impossible.'];
    }

    $mandataires = [];
    foreach (validations_reglement($reglementId) as $v) {
        $mandataires[(int)$v['mandataire_id']] = true;
    }
    $autorise = count($mandataires) >= 2;
    if ($autorise && $r['statut'] === 'demande') {
        db()->prepare("UPDATE reglements SET statut = 'autorise' WHERE id = ?")->execute([$reglementId]);
    }
    audit('comptes', 'reglement_valide', 'reglement', $reglementId,
        $r['numero'] . ' · ' . NATURES_VALIDATION[$nature] . ' · mandataire ' . $mandataireTiersId
        . ($autorise ? ' · deux autorisations réunies' : ''));
    return ['success' => true, 'autorise' => $autorise];
}

/**
 * L'execution produit exactement une ecriture, et une seule (CDC 8.3), et
 * attribue le numero de piece comptable - « le numero de piece est attribue au
 * reglement, conformement au guide qui le definit comme le numero de la preuve de
 * paiement » (CDC 4.3).
 *
 * Le changement de statut, l'ecriture et la numerotation tiennent dans une seule
 * transaction : un reglement marque execute sans son ecriture serait de l'argent
 * sorti sans trace comptable.
 */
function reglement_executer(int $reglementId, ?string $date = null): array
{
    $r = reglement($reglementId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Règlement inconnu dans ce projet.'];
    }
    if ($r['statut'] !== 'autorise') {
        return ['success' => false, 'error' => 'Seul un règlement autorisé par deux mandataires s\'exécute.'];
    }
    $date = $date ?? date('Y-m-d');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE reglements SET statut = 'execute', date_reglement = ? WHERE id = ?")->execute([$date, $reglementId]);
        $r['statut'] = 'execute';
        $r['date_reglement'] = $date;
        $ecriture = ecriture_reglement($r);
        $piece = reglement_numeroter_piece($r);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('reglement_executer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Exécution impossible : ' . $e->getMessage()];
    }
    audit('comptes', 'reglement_execute', 'reglement', $reglementId,
        $r['numero'] . ' · écriture ' . $ecriture . ($piece !== null ? ' · pièce ' . $piece : ''));
    return ['success' => true, 'ecriture_id' => $ecriture, 'numero_piece' => $piece];
}

/**
 * Attribue le numero de piece a l'imputation du dossier regle. Un dossier
 * abandonne avant reglement ne consomme aucun numero, et un reglement annule
 * apres coup conserve le sien, qui reste inutilise : une sequence trouee et
 * documentee vaut mieux qu'une renumerotation (CDC 4.3).
 *
 * Rend null quand le reglement n'a pas de dossier d'origine - un versement a la
 * DGI ou un renflouement de caisse n'imputent rien.
 */
function reglement_numeroter_piece(array $reglement): ?string
{
    $dossierId = reglement_dossier_id((string)$reglement['origine_ref']);
    if ($dossierId === null) {
        return null;
    }
    $st = db()->prepare(
        'SELECT i.id, i.numero_piece, l.rubrique
           FROM imputations i JOIN lignes_budgetaires l ON l.id = i.ligne_id
          WHERE i.dossier_id = ? AND i.projet_id = ?'
    );
    $st->execute([$dossierId, (int)$reglement['projet_id']]);
    $imputation = $st->fetch();
    if ($imputation === false || $imputation['rubrique'] === null) {
        return null;
    }
    if ($imputation['numero_piece'] !== null) {
        return (string)$imputation['numero_piece'];   // un reglement en deux temps ne renumerote pas
    }
    $numero = numero_piece_suivant((int)$imputation['rubrique'], (int)$reglement['projet_id']);
    db()->prepare('UPDATE imputations SET numero_piece = ? WHERE id = ?')->execute([$numero, (int)$imputation['id']]);
    return $numero;
}

/**
 * « Les cheques annules sont enregistres au journal avec leur numero et leur
 * motif, conserves agrafes au chequier, et ne generent aucune ecriture » (CDC 4.1).
 * Le numero reste attribue : la sequence ne se rejoue pas.
 */
function reglement_annuler(int $reglementId, string $motif): array
{
    $r = reglement($reglementId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Règlement inconnu dans ce projet.'];
    }
    if ($r['statut'] === 'execute') {
        return ['success' => false, 'error' => 'Un règlement exécuté a produit son écriture : il se corrige par une écriture inverse, pas par une annulation.'];
    }
    if ($r['statut'] === 'annule') {
        return ['success' => false, 'error' => 'Ce règlement est déjà annulé.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif d\'annulation est obligatoire.'];
    }
    db()->prepare("UPDATE reglements SET statut = 'annule', motif_annulation = ? WHERE id = ?")
        ->execute([mb_substr($motif, 0, 255), $reglementId]);
    audit('comptes', 'reglement_annule', 'reglement', $reglementId,
        $r['numero'] . ($r['numero_cheque'] ? ' · chèque ' . $r['numero_cheque'] : '') . ' · ' . $motif);
    return ['success' => true];
}

function reglements(?int $projetId = null, ?string $statut = null): array
{
    $sql = 'SELECT r.*, t.nom AS beneficiaire_nom, c.code AS compte_code,
                   (SELECT COUNT(DISTINCT mandataire_id) FROM validations_reglement v WHERE v.reglement_id = r.id) AS nb_mandataires
              FROM reglements r JOIN tiers t ON t.id = r.beneficiaire_id JOIN comptes c ON c.id = r.compte_id
             WHERE r.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($statut !== null) {
        $sql .= ' AND r.statut = ?';
        $args[] = $statut;
    }
    $sql .= ' ORDER BY r.id DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Rapprochement bancaire
//
// Un compte bancaire peut servir plusieurs projets, et c'est le cas nominal : le
// compte SOGEBANK porte les mouvements des deux. Le rapprochement ne peut donc
// plus se produire projet par projet de maniere autonome. Bousol produit un
// rapprochement par compte, ventile par projet, dont chaque projet extrait la
// partie qui le concerne (CDC 4.9).
//
// Lecture du modele : la ligne stockee est l'extrait d'un projet. Le rapprochement
// consolide - celui qui se confronte au releve - se calcule sur tous les projets
// rattaches au compte. C'est la seule construction qui ne laisse aucun ecart
// inexplique, et c'est ce que la validation verifie.
// ---------------------------------------------------------------------

/** Les projets rattaches a un compte bancaire du referentiel partage. */
function projets_du_compte_bancaire(int $compteBancaireId): array
{
    $st = db()->prepare(
        "SELECT p.id, p.code, p.intitule, pc.dedie
           FROM projets_comptes pc JOIN projets p ON p.id = pc.projet_id
          WHERE pc.compte_bancaire_id = ? AND p.statut <> 'archive' ORDER BY p.code"
    );
    $st->execute([$compteBancaireId]);
    return $st->fetchAll();
}

/**
 * Solde reconstitue du compte bancaire, ventile par projet, a une date donnee.
 * Chaque mouvement porte son projet : la ventilation est donc exacte et non
 * estimee.
 */
function solde_reconstitue_par_projet(int $compteBancaireId, string $jusquAu): array
{
    $st = db()->prepare(
        "SELECT m.projet_id, p.code, p.intitule,
                COALESCE(SUM(CASE WHEN m.sens = 'D' THEN m.montant ELSE -m.montant END), 0) AS solde
           FROM mouvements m
           JOIN ecritures e ON e.id = m.ecriture_id
           JOIN comptes c ON c.id = m.compte_id
           JOIN projets p ON p.id = m.projet_id
          WHERE c.compte_bancaire_id = ? AND e.date <= ?
          GROUP BY m.projet_id, p.code, p.intitule ORDER BY p.code"
    );
    $st->execute([$compteBancaireId, $jusquAu]);
    $par = [];
    foreach ($st->fetchAll() as $r) {
        $par[(int)$r['projet_id']] = ['code' => $r['code'], 'intitule' => $r['intitule'], 'solde' => round((float)$r['solde'], 2)];
    }
    // Un projet rattache sans mouvement figure quand meme, a zero : c'est ce qui
    // rend la ventilation lisible et prouve qu'il n'a pas ete oublie.
    foreach (projets_du_compte_bancaire($compteBancaireId) as $p) {
        if (!isset($par[(int)$p['id']])) {
            $par[(int)$p['id']] = ['code' => $p['code'], 'intitule' => $p['intitule'], 'solde' => 0.0];
        }
    }
    return $par;
}

/** Dernier jour du mois calendaire d'une date, le rapprochement s'y arretant. */
function fin_de_mois(string $date): string
{
    return (new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
}

/**
 * L'etat consolide oppose au releve : soldes par projet, ajustements de
 * rapprochement et ecart residuel.
 */
function rapprochement_consolide(int $compteBancaireId, string $dateReleve, float $soldeReleve, array $lignes = []): array
{
    $parProjet = solde_reconstitue_par_projet($compteBancaireId, $dateReleve);
    $reconstitue = 0.0;
    foreach ($parProjet as $p) {
        $reconstitue += $p['solde'];
    }
    $ajustements = 0.0;
    foreach ($lignes as $l) {
        $ajustements += ($l['sens'] === 'plus' ? 1 : -1) * round((float)$l['montant'], 2);
    }
    $ajuste = round($reconstitue + $ajustements, 2);
    return [
        'par_projet'   => $parProjet,
        'reconstitue'  => round($reconstitue, 2),
        'ajustements'  => round($ajustements, 2),
        'solde_ajuste' => $ajuste,
        'solde_releve' => round($soldeReleve, 2),
        'ecart'        => round(round($soldeReleve, 2) - $ajuste, 2),
    ];
}

function lignes_rapprochement(int $rapprochementId): array
{
    $st = db()->prepare('SELECT * FROM lignes_rapprochement WHERE rapprochement_id = ? ORDER BY id');
    $st->execute([$rapprochementId]);
    return $st->fetchAll();
}

/**
 * Valide l'extrait d'un projet. Deux refus, tous deux de l'annexe G.
 *
 * Le premier : un ecart residuel non commente. « Tout ecart non resolu exige un
 * commentaire avant validation. »
 *
 * Le second, propre au compte partage : tant qu'un projet rattache n'a pas produit
 * son extrait pour le meme releve, l'ecart n'est pas ventile - il est seulement
 * cache dans le projet qui manque. C'est le cas « clore un rapprochement de compte
 * partage laissant un ecart non ventile ».
 *
 * @return array{success: bool, error?: string}
 */
function rapprochement_valider(int $rapprochementId): array
{
    $st = db()->prepare('SELECT * FROM rapprochements WHERE id = ? AND projet_id = ?');
    $st->execute([$rapprochementId, projet_id()]);
    $r = $st->fetch();
    if ($r === false) {
        return ['success' => false, 'error' => 'Rapprochement inconnu dans ce projet.'];
    }
    if ($r['statut'] === 'valide') {
        return ['success' => false, 'error' => 'Ce rapprochement est déjà validé.'];
    }

    $sc = db()->prepare('SELECT compte_bancaire_id FROM comptes WHERE id = ?');
    $sc->execute([(int)$r['compte_id']]);
    $compteBancaireId = $sc->fetchColumn();
    if ($compteBancaireId === false || $compteBancaireId === null) {
        return ['success' => false, 'error' => 'Ce compte n\'est rattaché à aucun compte bancaire du référentiel.'];
    }
    $compteBancaireId = (int)$compteBancaireId;

    $etat = rapprochement_consolide($compteBancaireId, (string)$r['date_releve'], (float)$r['solde_releve'],
        lignes_rapprochement($rapprochementId));

    if (abs($etat['ecart']) >= 0.01 && trim((string)($r['commentaire_ecart'] ?? '')) === '') {
        return ['success' => false, 'error' => sprintf(
            'Écart de %s non résolu : le ventiler en lignes de rapprochement, ou le commenter avant validation.',
            htg($etat['ecart']))];
    }

    // Compte partage : chaque projet rattache doit avoir produit son extrait.
    $rattaches = projets_du_compte_bancaire($compteBancaireId);
    if (count($rattaches) > 1) {
        $manquants = [];
        foreach ($rattaches as $p) {
            if ((int)$p['id'] === (int)$r['projet_id']) {
                continue;
            }
            $sq = db()->prepare(
                'SELECT COUNT(*) FROM rapprochements ra JOIN comptes c ON c.id = ra.compte_id
                  WHERE ra.projet_id = ? AND c.compte_bancaire_id = ? AND ra.date_releve = ?'
            );
            $sq->execute([(int)$p['id'], $compteBancaireId, (string)$r['date_releve']]);
            if ((int)$sq->fetchColumn() === 0) {
                $manquants[] = $p['code'];
            }
        }
        if ($manquants) {
            return ['success' => false, 'error' => sprintf(
                'Compte partagé : %s n\'a pas encore produit son extrait au %s. '
                . 'Tant qu\'il manque, l\'écart n\'est pas ventilé, il est seulement caché.',
                implode(', ', $manquants), date_fr((string)$r['date_releve']))];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE rapprochements SET statut = 'valide', solde_reconstitue = ?, ecart = ? WHERE id = ?")
            ->execute([$etat['solde_ajuste'], $etat['ecart'], $rapprochementId]);
        audit_strict('comptes', 'rapprochement_valide', 'rapprochement', $rapprochementId,
            'Relevé ' . date_fr((string)$r['date_releve']) . ' · relevé ' . htg((float)$r['solde_releve'])
            . ' · reconstitué ' . htg($etat['solde_ajuste']) . ' · écart ' . htg($etat['ecart']));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('rapprochement_valider: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Validation impossible.'];
    }
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Petite caisse
//
// La caisse n'est pas une table a part, c'est un compte de type caisse (CDC 8.3).
// Son journal est la suite de ses mouvements, et son arrete periodique est le seul
// objet propre.
// ---------------------------------------------------------------------

function compte_caisse(?int $projetId = null): ?array
{
    $st = db()->prepare("SELECT * FROM comptes WHERE projet_id = ? AND type = 'caisse' AND actif = 1 LIMIT 1");
    $st->execute([$projetId ?? projet_id()]);
    $c = $st->fetch();
    return $c === false ? null : $c;
}

/**
 * Journal de caisse au format du formulaire transmis par le PAIESC : solde
 * initial, entrees, sorties, balance courante, mention des depenses reportees et
 * observations (CDC 4.6).
 */
function caisse_journal(int $compteId, string $debut, string $fin): array
{
    $veille = (new DateTimeImmutable($debut))->modify('-1 day')->format('Y-m-d');
    $st = db()->prepare(
        "SELECT m.*, e.date, e.libelle, e.type, t.nom AS tiers_nom
           FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
           LEFT JOIN tiers t ON t.id = m.tiers_id
          WHERE m.compte_id = ? AND e.date BETWEEN ? AND ?
          ORDER BY e.date, m.id"
    );
    $st->execute([$compteId, $debut, $fin]);
    $solde = solde_compte($compteId, $veille);
    $lignes = [];
    foreach ($st->fetchAll() as $m) {
        $entree = $m['sens'] === 'D' ? (float)$m['montant'] : 0.0;
        $sortie = $m['sens'] === 'C' ? (float)$m['montant'] : 0.0;
        $solde = round($solde + $entree - $sortie, 2);
        $lignes[] = $m + ['entree' => $entree, 'sortie' => $sortie, 'balance' => $solde];
    }
    return ['solde_initial' => solde_compte($compteId, $veille), 'lignes' => $lignes, 'solde_final' => $solde];
}

/** Le dernier arrete de caisse, celui qui conditionne le renflouement. */
function dernier_arrete_caisse(int $compteId): ?array
{
    $st = db()->prepare('SELECT * FROM arretes_caisse WHERE compte_id = ? ORDER BY date DESC, id DESC LIMIT 1');
    $st->execute([$compteId]);
    $a = $st->fetch();
    return $a === false ? null : $a;
}

/**
 * Arrete de caisse date, confrontant le solde theorique au solde compte en
 * especes. Un ecart doit etre commente : c'est la piece qui autorise ensuite le
 * renflouement.
 *
 * @return array{success: bool, id?: int, error?: string}
 */
function arrete_caisse_creer(int $compteId, string $date, float $soldeConstate, ?int $detenteurId, string $commentaire): array
{
    $st = db()->prepare("SELECT * FROM comptes WHERE id = ? AND projet_id = ? AND type = 'caisse'");
    $st->execute([$compteId, projet_id()]);
    if ($st->fetch() === false) {
        return ['success' => false, 'error' => 'Compte de caisse inconnu dans ce projet.'];
    }
    $theorique = solde_compte($compteId, $date);
    $ecart = round($soldeConstate - $theorique, 2);
    if (abs($ecart) >= 0.01 && trim($commentaire) === '') {
        return ['success' => false, 'error' => sprintf(
            'Écart de %s entre le solde théorique et les espèces comptées : il doit être expliqué.', htg($ecart))];
    }
    try {
        db()->prepare(
            'INSERT INTO arretes_caisse (projet_id, compte_id, date, solde_theorique, solde_constate, ecart, commentaire, detenteur_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $compteId, $date, $theorique, round($soldeConstate, 2), $ecart,
                    trim($commentaire) ?: null, $detenteurId, user_id()]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('arrete_caisse_creer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Enregistrement impossible.'];
    }
    audit('comptes', 'arrete_caisse', 'arrete_caisse', $id,
        date_fr($date) . ' · théorique ' . htg($theorique) . ' · constaté ' . htg($soldeConstate) . ' · écart ' . htg($ecart));
    return ['success' => true, 'id' => $id];
}

/**
 * « Le renflouement n'est possible qu'apres justification des depenses anterieures
 * et arrete de caisse date et signe » (CDC 4.6). Le fonds fixe est plafonne : on ne
 * renfloue jamais au-dela.
 *
 * @return array{ok: bool, motif?: string, montant_max?: float}
 */
function caisse_renflouement_possible(int $compteId, float $montant): array
{
    $arrete = dernier_arrete_caisse($compteId);
    if ($arrete === null) {
        return ['ok' => false, 'motif' => 'Aucun arrêté de caisse : le renflouement suppose la justification des dépenses antérieures.'];
    }
    // Un arrete posterieur au dernier renflouement, sinon on renfloue sur la foi
    // d'un arrete deja consomme.
    $st = db()->prepare(
        "SELECT MAX(e.date) FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id
          WHERE m.compte_id = ? AND m.sens = 'D' AND e.type = 'caisse'"
    );
    $st->execute([$compteId]);
    $dernierRenflouement = $st->fetchColumn();
    if ($dernierRenflouement !== false && $dernierRenflouement !== null && (string)$arrete['date'] < (string)$dernierRenflouement) {
        return ['ok' => false, 'motif' => 'Le dernier arrêté est antérieur au dernier renflouement : en produire un nouveau.'];
    }

    $plafond = param('plafond_petite_caisse');
    if ($plafond !== null) {
        $solde = solde_compte($compteId);
        $max = round((float)$plafond - $solde, 2);
        if ($montant > $max + 0.005) {
            return ['ok' => false, 'montant_max' => $max, 'motif' => sprintf(
                'Fonds fixe plafonné à %s : le solde étant de %s, le renflouement ne peut dépasser %s.',
                htg((float)$plafond), htg($solde), htg($max))];
        }
    }
    return ['ok' => true];
}

function arretes_caisse(int $compteId, int $limit = 24): array
{
    $st = db()->prepare(
        'SELECT a.*, t.nom AS detenteur_nom FROM arretes_caisse a
           LEFT JOIN tiers t ON t.id = a.detenteur_id
          WHERE a.compte_id = ? ORDER BY a.date DESC, a.id DESC LIMIT ' . (int)$limit
    );
    $st->execute([$compteId]);
    return $st->fetchAll();
}

/**
 * Renfloue la petite caisse. « L'approvisionnement se fait par cheque emis au nom
 * d'une personne intermediaire nommement designee, jamais par un cheque au
 * porteur. Le renflouement n'est possible qu'apres justification des depenses
 * anterieures et arrete de caisse date et signe » (CDC 4.6).
 *
 * Le renflouement est une sortie de fonds de la banque : il suit donc le circuit
 * normal du reglement, deux mandataires compris. Son execution debite la caisse
 * au lieu d'un tiers.
 *
 * @return array{success: bool, id?: int, numero?: string, error?: string}
 */
function caisse_renflouer(int $compteCaisseId, float $montant, int $detenteurId, string $numeroCheque): array
{
    $st = db()->prepare("SELECT * FROM comptes WHERE id = ? AND projet_id = ? AND type = 'caisse'");
    $st->execute([$compteCaisseId, projet_id()]);
    $caisse = $st->fetch();
    if ($caisse === false) {
        return ['success' => false, 'error' => 'Compte de caisse inconnu dans ce projet.'];
    }
    $sd = db()->prepare("SELECT nom FROM tiers WHERE id = ? AND type = 'personne'");
    $sd->execute([$detenteurId]);
    $detenteur = $sd->fetchColumn();
    if ($detenteur === false) {
        return ['success' => false, 'error' => 'Le chèque d\'approvisionnement est émis au nom d\'une personne nommément désignée, jamais au porteur.'];
    }
    $possible = caisse_renflouement_possible($compteCaisseId, $montant);
    if (!$possible['ok']) {
        return ['success' => false, 'error' => $possible['motif']];
    }
    $banque = compte_par_code('BQ');
    if ($banque === null) {
        return ['success' => false, 'error' => 'Plan de comptes incomplet : compte bancaire absent.'];
    }
    $r = reglement_creer([
        'mode'            => 'cheque',
        'numero_cheque'   => $numeroCheque,
        'beneficiaire_id' => $detenteurId,
        'compte_id'       => (int)$banque['id'],
        'montant'         => $montant,
        'objet'           => 'Renflouement de la petite caisse — ' . $detenteur,
        'origine_module'  => 'comptes',
        'origine_ref'     => 'renflouement:' . $compteCaisseId,
    ]);
    if (!empty($r['success'])) {
        audit('comptes', 'renflouement_demande', 'reglement', (int)$r['id'],
            $r['numero'] . ' · ' . htg($montant) . ' · chèque ' . $numeroCheque . ' au nom de ' . $detenteur);
    }
    return $r;
}

// ---------------------------------------------------------------------
// Rendu documentaire (annexe E)
// ---------------------------------------------------------------------

/** Le journal de caisse au format du formulaire PAIESC, avec son arrete du moment. */
function document_journal_caisse(int $compteId, string $debut, string $fin): array
{
    $journal = caisse_journal($compteId, $debut, $fin);
    $arrete = dernier_arrete_caisse($compteId);
    $detenteur = null;
    if ($arrete !== null && $arrete['detenteur_id'] !== null) {
        $st = db()->prepare('SELECT nom FROM tiers WHERE id = ?');
        $st->execute([(int)$arrete['detenteur_id']]);
        $detenteur = $st->fetchColumn() ?: null;
    }
    return document_generer('journal_caisse',
        ['debut' => $debut, 'fin' => $fin, 'journal' => $journal, 'arrete' => $arrete, 'detenteur' => $detenteur],
        'compte', $compteId, 'comptes');
}

/** L'extrait d'un projet, avec l'etat consolide du compte qui le porte. */
function document_rapprochement(int $rapprochementId): array
{
    $st = db()->prepare('SELECT r.*, c.libelle AS compte_libelle, c.compte_bancaire_id
                           FROM rapprochements r JOIN comptes c ON c.id = r.compte_id
                          WHERE r.id = ? AND r.projet_id = ?');
    $st->execute([$rapprochementId, projet_id()]);
    $r = $st->fetch();
    if ($r === false) {
        return ['success' => false, 'error' => 'Rapprochement inconnu dans ce projet.'];
    }
    $lignes = lignes_rapprochement($rapprochementId);
    $etat = rapprochement_consolide((int)$r['compte_bancaire_id'], (string)$r['date_releve'],
        (float)$r['solde_releve'], $lignes);
    $res = document_generer('rapprochement', [
        'compte'       => $r['compte_libelle'],
        'date_releve'  => $r['date_releve'],
        'etat'         => $etat,
        'lignes'       => $lignes,
        'commentaire'  => $r['commentaire_ecart'],
        'partage'      => count(projets_du_compte_bancaire((int)$r['compte_bancaire_id'])) > 1,
    ], 'rapprochement', $rapprochementId, 'comptes');
    if (!empty($res['success'])) {
        db()->prepare('UPDATE rapprochements SET document_id = ? WHERE id = ?')
            ->execute([(int)$res['document_id'], $rapprochementId]);
    }
    return $res;
}
