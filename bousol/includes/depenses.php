<?php
declare(strict_types=1);

/**
 * Module Depenses - le dossier, sa checklist de pieces et son cycle (CDC 4.2, 4.3).
 *
 * Le cycle comporte neuf etapes dont deux seulement sont bloquantes : l'imputation,
 * qui refuse d'aboutir si l'un des controles budgetaires echoue, et le reglement,
 * qui refuse de s'executer sans deux signatures valides de mandataires non
 * beneficiaires. Les etapes intermediaires varient selon le type de dossier, et
 * c'est la liste de pieces attachee au type qui commande ces variations, non le
 * processus lui-meme.
 *
 * Depenses n'ecrit ni dans le budget ni dans les comptes : il appelle Budget pour
 * imputer et Comptes pour faire produire un reglement. Le graphe des dependances
 * reste sans cycle (CDC 7.2).
 */

require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/comptes.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/documents.php';   // rendu des pieces que l'outil produit (annexe E)

const STATUTS_DOSSIER = [
    'brouillon'      => 'Ouvert',
    'impute'         => 'Imputé',
    'en_concurrence' => 'En mise en concurrence',
    'commande'       => 'Commandé',
    'receptionne'    => 'Réceptionné',
    'approuve'       => 'Approuvé',
    'regle'          => 'Réglé',
    'clos'           => 'Clos',
    'abandonne'      => 'Abandonné',
];

// ---------------------------------------------------------------------
// Ouverture du dossier et sa checklist
// ---------------------------------------------------------------------

/**
 * Le numero de dossier, attribue a l'ouverture, sert au suivi interne. A ne pas
 * confondre avec le numero de piece comptable, qui n'est attribue qu'au reglement
 * (CDC 5.2). Un dossier abandonne avant reglement ne consomme aucun numero de
 * piece, mais garde son numero de dossier.
 */
function dossier_numero_suivant(?int $projetId = null): string
{
    $st = db()->prepare("SELECT numero FROM dossiers WHERE projet_id = ? AND numero LIKE 'DOS-%' ORDER BY id DESC LIMIT 1");
    $st->execute([$projetId ?? projet_id()]);
    $dernier = (string)($st->fetchColumn() ?: 'DOS-0000');
    return sprintf('DOS-%04d', ((int)substr($dernier, 4)) + 1);
}

/**
 * Ouvre un dossier et cree sa checklist : une case vide par piece attendue du
 * type, avec le moment ou chacune est exigee (annexe D). La proforma n'est
 * obligatoire qu'au-dessus du seuil parametre ; en dessous, l'etape est traversee
 * sans production de proforma et la case nait sans objet.
 *
 * @return array{success: bool, id?: int, numero?: string, error?: string}
 */
function dossier_ouvrir(array $d): array
{
    if (($refus = droit_ecriture('dossier_ouvrir')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    $type = (string)($d['type'] ?? '');
    $def = TYPES_DOSSIER[$type] ?? null;
    if ($def === null) {
        return ['success' => false, 'error' => 'Type de dossier inconnu.'];
    }
    if (empty($def['actif'])) {
        return ['success' => false, 'error' => 'Le type « ' . $def['libelle'] . ' » est désactivé sur ce projet.'];
    }
    $tiersId = (int)($d['tiers_id'] ?? 0);
    $st = db()->prepare('SELECT COUNT(*) FROM tiers WHERE id = ?');
    $st->execute([$tiersId]);
    if ((int)$st->fetchColumn() === 0) {
        return ['success' => false, 'error' => 'Bénéficiaire inconnu au référentiel.'];
    }
    $objet = trim((string)($d['objet'] ?? ''));
    if ($objet === '') {
        return ['success' => false, 'error' => 'L\'objet du dossier est obligatoire.'];
    }
    if (($ferme = creation_depense_fermee()) !== null) {
        return ['success' => false, 'error' => $ferme];
    }

    // Le seuil de mise en concurrence porte une devise et un perimetre, non un
    // simple montant : le guide REVIV l'exprime en euros et ne vise que les
    // equipements, le PAIESC en gourdes et vise tout achat (CDC 2.6).
    $montantPrevu = round((float)($d['montant_prevu'] ?? 0), 2);
    $concurrence = concurrence_requise($montantPrevu, $type);

    $pid = projet_id();
    $numero = dossier_numero_suivant($pid);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO dossiers (projet_id, numero, type, tiers_id, objet, periode_id, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$pid, $numero, $type, $tiersId, $objet,
                    periode_pour_date(date('Y-m-d'))['id'] ?? null, user_id()]);
        $id = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO pieces (projet_id, dossier_id, type, libelle, obligatoire, moment, statut, ordre)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $ordre = 0;
        foreach ($def['pieces'] as [$code, $libelle, $moment, $condition]) {
            $conditionnelle = $condition === 'seuil_proformas' && !$concurrence;
            $ins->execute([$pid, $id, $code, $libelle, $conditionnelle ? 0 : 1, $moment,
                           $conditionnelle ? 'sans_objet' : 'attendue', ++$ordre]);
        }
        audit_strict('depenses', 'dossier_ouvert', 'dossier', $id,
            $numero . ' · ' . $def['libelle'] . ' · ' . $objet
            . ($concurrence ? ' · mise en concurrence requise' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('dossier_ouvrir: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Ouverture impossible.'];
    }
    return ['success' => true, 'id' => $id, 'numero' => $numero, 'concurrence' => $concurrence];
}

/**
 * La mise en concurrence est conditionnelle : en dessous du seuil parametre,
 * l'etape est traversee sans production de proforma ; au-dessus, trois proformas
 * sont exiges (CDC 4.3).
 *
 * Sur Koule Ki Pale le seuil est la contre-valeur de 500 euros, figee une fois
 * pour toutes, et ne vise que les equipements et materiels ; sur KesKle il est en
 * gourdes et vise tout achat.
 */
function concurrence_requise(float $montant, string $typeDossier): bool
{
    $seuil = param('seuil_proformas');
    if ($seuil === null) {
        return false;
    }
    if (param('seuil_concurrence_perimetre', 'tout_achat') === 'equipements_materiels'
        && $typeDossier !== 'achat_bien') {
        return false;
    }
    return $montant >= (float)$seuil;
}

function dossier(int $id): ?array
{
    $st = db()->prepare(
        'SELECT d.*, t.nom AS tiers_nom, t.nif, u.tiers_id AS ouvreur_tiers_id, p.nom AS ouvreur_nom
           FROM dossiers d
           JOIN tiers t ON t.id = d.tiers_id
           JOIN utilisateurs u ON u.id = d.created_by
           JOIN tiers p ON p.id = u.tiers_id
          WHERE d.id = ? AND d.projet_id = ?'
    );
    $st->execute([$id, projet_id()]);
    $d = $st->fetch();
    return $d === false ? null : $d;
}

function dossiers(?string $statut = null, ?int $projetId = null): array
{
    $sql = 'SELECT d.*, t.nom AS tiers_nom, i.montant AS montant_impute, i.numero_piece,
                   l.code AS ligne_code, l.libelle AS ligne_libelle,
                   (SELECT COUNT(*) FROM pieces p WHERE p.dossier_id = d.id AND p.statut = \'attendue\') AS pieces_manquantes
              FROM dossiers d
              JOIN tiers t ON t.id = d.tiers_id
              LEFT JOIN imputations i ON i.dossier_id = d.id
              LEFT JOIN lignes_budgetaires l ON l.id = i.ligne_id
             WHERE d.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($statut !== null) {
        $sql .= ' AND d.statut = ?';
        $args[] = $statut;
    }
    $sql .= ' ORDER BY d.id DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Pieces de la checklist
// ---------------------------------------------------------------------

function pieces_dossier(int $dossierId): array
{
    $st = db()->prepare(
        'SELECT p.*, f.nom_genere, f.empreinte, d.fichier_id AS document_fichier_id, d.statut AS document_statut
           FROM pieces p
           LEFT JOIN fichiers f ON f.id = p.fichier_id
           LEFT JOIN documents d ON d.id = p.document_id
          WHERE p.dossier_id = ? ORDER BY p.ordre'
    );
    $st->execute([$dossierId]);
    return $st->fetchAll();
}

/**
 * Les pieces obligatoires encore attendues, pour un moment donne ou pour les deux.
 * « Le dossier ne peut etre clos tant qu'une piece obligatoire manque, et le
 * reglement ne peut etre execute tant que les pieces prealables au paiement ne
 * sont pas reunies » (CDC 4.2).
 *
 * @return string[] libelles des pieces manquantes
 */
function dossier_pieces_manquantes(int $dossierId, ?string $moment = null): array
{
    $sql = "SELECT libelle FROM pieces WHERE dossier_id = ? AND obligatoire = 1 AND statut = 'attendue'";
    $args = [$dossierId];
    if ($moment !== null) {
        $sql .= ' AND moment = ?';
        $args[] = $moment;
    }
    $st = db()->prepare($sql . ' ORDER BY ordre');
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Verse une piece numerisee. « Chaque piece est numerisee separement, une piece
 * donnant un fichier » (CDC 5.3), et la meme empreinte ne peut satisfaire deux
 * pieces distinctes du meme dossier, faute de quoi une checklist se completerait
 * en versant deux fois la meme image (CDC 7.3).
 *
 * @return array{success: bool, error?: string}
 */
function piece_verser(int $pieceId, array $fichier, ?string $datePiece = null): array
{
    if (($refus = droit_ecriture('televerser_scan')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    $st = db()->prepare(
        'SELECT p.*, d.numero, d.statut AS dossier_statut, d.periode_id AS periode_dossier FROM pieces p
           JOIN dossiers d ON d.id = p.dossier_id
          WHERE p.id = ? AND p.projet_id = ?'
    );
    $st->execute([$pieceId, projet_id()]);
    $piece = $st->fetch();
    if ($piece === false) {
        return ['success' => false, 'error' => 'Pièce inconnue dans ce projet.'];
    }
    if (in_array($piece['dossier_statut'], ['clos', 'abandonne'], true)) {
        return ['success' => false, 'error' => 'Ce dossier est ' . $piece['dossier_statut'] . ' : on n\'y verse plus de pièce.'];
    }
    // « Les pieces posterieures peuvent rejoindre leur dossier apres le figement »
    // (CDC 6.6) : elles ne modifient aucun montant. Les pieces prealables au
    // paiement, elles, sont arretees avec la periode.
    if ($piece['moment'] === 'avant' && periode_est_figee($piece['periode_dossier'] === null ? null : (int)$piece['periode_dossier'])) {
        return ['success' => false, 'error' => 'La période de ce dossier est figée : seules les pièces postérieures '
            . 'au paiement peuvent encore la rejoindre, puisqu\'elles ne modifient aucun montant.'];
    }
    $up = enregistrer_upload($fichier, 'scans',
        projet_code() . '-' . $piece['numero'] . '-' . strtoupper($piece['type']) . '.pdf',
        ALLOWED_DOCUMENT, false, $piece['fichier_id'] ? (int)$piece['fichier_id'] : null);
    if (!$up['success']) {
        return ['success' => false, 'error' => $up['error']];
    }
    [$deja, $motif] = empreinte_deja_utilisee((string)fichier((int)$up['id'])['empreinte'], (int)$up['id']);
    if ($deja) {
        return ['success' => false, 'error' => 'Ce fichier a déjà été versé : ' . $motif
            . '. Une pièce donne un fichier, et le même scan ne peut pas cocher deux cases.'];
    }
    db()->prepare("UPDATE pieces SET fichier_id = ?, statut = 'recue', date_piece = ? WHERE id = ?")
        ->execute([(int)$up['id'], $datePiece ?? date('Y-m-d'), $pieceId]);
    dossier_avancer_sur_piece((int)$piece['dossier_id'], (string)$piece['type']);
    audit('depenses', 'piece_versee', 'dossier', (int)$piece['dossier_id'],
        $piece['numero'] . ' · ' . $piece['libelle'] . ' · fichier #' . (int)$up['id']);
    return ['success' => true];
}

/**
 * Deux des neuf etapes du cycle ne se declarent pas : elles se constatent a
 * l'arrivee de leur piece. Le bon de commande fait passer le dossier a l'etat
 * commande, le bon de reception a l'etat receptionne. Les etats posterieurs -
 * approuve, regle, clos - ne redescendent jamais.
 */
function dossier_avancer_sur_piece(int $dossierId, string $typePiece): void
{
    $etape = match ($typePiece) {
        'bon_commande'  => 'commande',
        'bon_reception' => 'receptionne',
        default         => null,
    };
    if ($etape === null) {
        return;
    }
    $anterieurs = $etape === 'commande'
        ? ['brouillon', 'impute', 'en_concurrence']
        : ['brouillon', 'impute', 'en_concurrence', 'commande'];
    $in = implode(',', array_fill(0, count($anterieurs), '?'));
    db()->prepare("UPDATE dossiers SET statut = ? WHERE id = ? AND statut IN ($in)")
        ->execute([$etape, $dossierId, ...$anterieurs]);
}

/**
 * Certaines cases de la checklist ne recoivent pas un fichier mais une attestation :
 * « TCA incluse dans le prix final, aucun montant hors taxe » (annexe H) se verifie
 * en lisant les pieces, et ce qui doit rester dans l'outil est la trace de qui l'a
 * verifie et quand.
 *
 * @return array{success: bool, error?: string}
 */
function piece_attester(int $pieceId, string $mention): array
{
    $st = db()->prepare(
        'SELECT p.*, d.numero, d.statut AS dossier_statut FROM pieces p
           JOIN dossiers d ON d.id = p.dossier_id
          WHERE p.id = ? AND p.projet_id = ?'
    );
    $st->execute([$pieceId, projet_id()]);
    $piece = $st->fetch();
    if ($piece === false) {
        return ['success' => false, 'error' => 'Pièce inconnue dans ce projet.'];
    }
    if (!in_array($piece['type'], PIECES_ATTESTEES, true)) {
        return ['success' => false, 'error' => 'Cette pièce attend un fichier numérisé, pas une attestation.'];
    }
    if (in_array($piece['dossier_statut'], ['clos', 'abandonne'], true)) {
        return ['success' => false, 'error' => 'Ce dossier est ' . $piece['dossier_statut'] . '.'];
    }
    if (trim($mention) === '') {
        return ['success' => false, 'error' => 'L\'attestation porte la mention de ce qui a été vérifié.'];
    }
    db()->prepare("UPDATE pieces SET statut = 'recue', date_piece = CURDATE() WHERE id = ?")->execute([$pieceId]);
    audit('depenses', 'piece_attestee', 'dossier', (int)$piece['dossier_id'],
        $piece['numero'] . ' · ' . $piece['libelle'] . ' · ' . trim($mention));
    return ['success' => true];
}

/** Une piece que le type prevoit mais que ce dossier-ci n'appelle pas. */
function piece_sans_objet(int $pieceId, string $motif): array
{
    $st = db()->prepare('SELECT p.*, d.numero FROM pieces p JOIN dossiers d ON d.id = p.dossier_id
                          WHERE p.id = ? AND p.projet_id = ?');
    $st->execute([$pieceId, projet_id()]);
    $piece = $st->fetch();
    if ($piece === false) {
        return ['success' => false, 'error' => 'Pièce inconnue dans ce projet.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Écarter une pièce obligatoire demande un motif.'];
    }
    db()->prepare("UPDATE pieces SET statut = 'sans_objet' WHERE id = ?")->execute([$pieceId]);
    audit('depenses', 'piece_sans_objet', 'dossier', (int)$piece['dossier_id'],
        $piece['numero'] . ' · ' . $piece['libelle'] . ' · ' . $motif);
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Imputation - premiere des deux etapes bloquantes
// ---------------------------------------------------------------------

/**
 * Impute le dossier sur une ligne budgetaire. Les sept controles du CDC 2.3 sont
 * appliques par le module Budget : c'est ici que budget_controle_imputation()
 * trouve enfin son appelant.
 *
 * Un dossier porte une imputation et une seule - la base le garantit par
 * uk_imputation_dossier - donc une facture couvrant deux lignes se scinde en deux
 * dossiers et deux reglements distincts.
 *
 * @return array{success: bool, error?: string, alertes?: string[]}
 */
function dossier_imputer(int $dossierId, int $ligneId, float $quantite, float $valeurUnitaire,
                         string $unite, string $nature = 'consommation'): array
{
    if (($refus = droit_ecriture('imputer')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    if (in_array($d['statut'], ['clos', 'abandonne', 'regle'], true)) {
        return ['success' => false, 'error' => 'Un dossier ' . $d['statut'] . ' ne se réimpute pas.'];
    }
    if (($ferme = creation_depense_fermee()) !== null) {
        return ['success' => false, 'error' => $ferme];
    }
    if (periode_est_figee($d['periode_id'] === null ? null : (int)$d['periode_id'])) {
        return ['success' => false, 'error' => 'La période de ce dossier est figée par un rapport validé : '
            . 'ses dépenses ne se modifient plus. Une correction passe par la réouverture exceptionnelle.'];
    }
    if ($quantite <= 0 || $valeurUnitaire < 0) {
        return ['success' => false, 'error' => 'Quantité et valeur unitaire sont obligatoires.'];
    }
    if (!array_key_exists($unite, UNITES)) {
        return ['success' => false, 'error' => 'Unité hors liste.'];
    }
    if ($d['type'] === 'versement_dgi' && $nature !== 'memoire') {
        return ['success' => false, 'error' => 'Un versement à la DGI ne consomme aucune ligne budgétaire : '
            . 'l\'acompte est déjà compris dans le brut imputé à la prestation. Sa fiche d\'imputation '
            . 'existe à titre de mémoire.'];
    }
    $montant = round($quantite * $valeurUnitaire, 2);

    // La derogation ne leve que le controle de quantite, et sur motif ecrit
    // enregistre. Elle a ete accordee en amont par le Coordinateur : le RAF la
    // trouve posee sur le dossier, il ne se l'accorde pas a lui-meme.
    $derogation = trim((string)($d['derogation_quantite_motif'] ?? '')) !== '';

    $refus = budget_controle_imputation($ligneId, $montant, $quantite, $derogation);
    if ($refus) {
        return ['success' => false, 'error' => implode(' ', array_column($refus, 'message'))];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Une reimputation remplace la precedente : le dossier n'en porte qu'une.
        $pdo->prepare('DELETE FROM imputations WHERE dossier_id = ?')->execute([$dossierId]);
        $pdo->prepare(
            'INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire,
                                      montant, nature, date_imputation)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $dossierId, $ligneId, $unite, $quantite, $valeurUnitaire,
                    $montant, $nature, date('Y-m-d')]);
        if ($d['statut'] === 'brouillon') {
            $pdo->prepare("UPDATE dossiers SET statut = 'impute' WHERE id = ?")->execute([$dossierId]);
        }
        // Le montant prevu a l'ouverture n'etait qu'une estimation ; c'est
        // l'imputation qui donne le montant reel. Si elle franchit le seuil, la
        // mise en concurrence redevient exigible.
        if (concurrence_requise($montant, (string)$d['type'])) {
            $pdo->prepare("UPDATE pieces SET obligatoire = 1, statut = 'attendue'
                            WHERE dossier_id = ? AND type = 'proforma' AND statut = 'sans_objet'")
                ->execute([$dossierId]);
        }
        $ligne = budget_ligne_par_id($ligneId);
        audit_strict('depenses', 'dossier_impute', 'dossier', $dossierId,
            $d['numero'] . ' · ligne ' . ($ligne['code'] ?? $ligneId) . ' · ' . $quantite . ' ' . UNITES[$unite]
            . ' × ' . htg($valeurUnitaire) . ' = ' . htg($montant)
            . ($derogation ? ' · sous dérogation de quantité' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('dossier_imputer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Imputation impossible : ' . $e->getMessage()];
    }
    return ['success' => true];
}

/**
 * Le Coordinateur accorde la derogation au controle de quantite, sur motif ecrit
 * et enregistre (CDC 2.3, annexe H). C'est un acte distinct de l'imputation, qui
 * revient au RAF : celui qui leve le controle n'est pas celui qui en profite.
 */
function dossier_deroger_quantite(int $dossierId, string $motif): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'La dérogation au contrôle de quantité appartient au Coordinateur.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'La dérogation exige un motif écrit.'];
    }
    db()->prepare('UPDATE dossiers SET derogation_quantite_motif = ? WHERE id = ? AND projet_id = ?')
        ->execute([mb_substr(trim($motif), 0, 1000), $dossierId, projet_id()]);
    audit('depenses', 'derogation_quantite', 'dossier', $dossierId, $d['numero'] . ' · ' . trim($motif));
    return ['success' => true];
}

/** Une ligne budgetaire par son identifiant, pour les traces. */
function budget_ligne_par_id(int $ligneId): ?array
{
    $st = db()->prepare('SELECT * FROM lignes_budgetaires WHERE id = ?');
    $st->execute([$ligneId]);
    $l = $st->fetch();
    return $l === false ? null : $l;
}

function imputation_dossier(int $dossierId): ?array
{
    $st = db()->prepare(
        'SELECT i.*, l.code AS ligne_code, l.libelle AS ligne_libelle, l.rubrique
           FROM imputations i JOIN lignes_budgetaires l ON l.id = i.ligne_id
          WHERE i.dossier_id = ?'
    );
    $st->execute([$dossierId]);
    $i = $st->fetch();
    return $i === false ? null : $i;
}

// ---------------------------------------------------------------------
// Mise en concurrence
// ---------------------------------------------------------------------

function proformas_dossier(int $dossierId): array
{
    $st = db()->prepare(
        'SELECT p.*, t.nom AS fournisseur_nom FROM proformas p
           JOIN tiers t ON t.id = p.fournisseur_id
          WHERE p.dossier_id = ? ORDER BY p.montant'
    );
    $st->execute([$dossierId]);
    return $st->fetchAll();
}

function proforma_ajouter(int $dossierId, int $fournisseurId, float $montant, ?array $fichier = null): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    if ($montant <= 0) {
        return ['success' => false, 'error' => 'Le montant du proforma est obligatoire.'];
    }
    $fichierId = null;
    if (!empty($fichier['name'])) {
        $up = enregistrer_upload($fichier, 'scans',
            projet_code() . '-' . $d['numero'] . '-PROFORMA-' . $fournisseurId . '.pdf');
        if (!$up['success']) {
            return ['success' => false, 'error' => 'Proforma : ' . $up['error']];
        }
        $fichierId = (int)$up['id'];
    }
    db()->prepare('INSERT INTO proformas (projet_id, dossier_id, fournisseur_id, montant, fichier_id) VALUES (?,?,?,?,?)')
        ->execute([projet_id(), $dossierId, $fournisseurId, round($montant, 2), $fichierId]);
    db()->prepare("UPDATE dossiers SET statut = 'en_concurrence' WHERE id = ? AND statut IN ('brouillon','impute')")
        ->execute([$dossierId]);
    audit('depenses', 'proforma_verse', 'dossier', $dossierId, $d['numero'] . ' · ' . htg($montant));
    return ['success' => true];
}

/**
 * Retient une offre. « Le choix d'une offre autre que la moins-disante impose un
 * motif ecrit » (CDC 4.3) : c'est la seule chose que ce controle verifie, et il ne
 * se leve pas.
 *
 * @return array{success: bool, error?: string}
 */
function proforma_retenir(int $proformaId, string $motif = ''): array
{
    $st = db()->prepare(
        'SELECT p.*, d.numero, d.id AS dossier FROM proformas p JOIN dossiers d ON d.id = p.dossier_id
          WHERE p.id = ? AND p.projet_id = ?'
    );
    $st->execute([$proformaId, projet_id()]);
    $p = $st->fetch();
    if ($p === false) {
        return ['success' => false, 'error' => 'Proforma inconnu dans ce projet.'];
    }
    $sm = db()->prepare('SELECT MIN(montant) FROM proformas WHERE dossier_id = ?');
    $sm->execute([(int)$p['dossier_id']]);
    $moinsDisante = (float)$sm->fetchColumn();
    if (round((float)$p['montant'], 2) > round($moinsDisante, 2) + 0.005 && trim($motif) === '') {
        return ['success' => false, 'error' => sprintf(
            'Cette offre est à %s alors que la moins-disante est à %s : le choix impose un motif écrit.',
            htg((float)$p['montant']), htg($moinsDisante))];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE proformas SET retenu = 0, motif_choix = NULL WHERE dossier_id = ?')->execute([(int)$p['dossier_id']]);
        $pdo->prepare('UPDATE proformas SET retenu = 1, motif_choix = ? WHERE id = ?')
            ->execute([trim($motif) ?: null, $proformaId]);
        audit_strict('depenses', 'offre_retenue', 'dossier', (int)$p['dossier_id'],
            $p['numero'] . ' · ' . htg((float)$p['montant'])
            . (trim($motif) !== '' ? ' · non moins-disante : ' . trim($motif) : ' · moins-disante'));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Enregistrement impossible.'];
    }
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Approbation, reglement, cloture
// ---------------------------------------------------------------------

/**
 * L'approbation revient au Coordinateur (annexe B). « Un mandataire beneficiaire
 * est exclu du couple signataire » vaut aussi pour l'approbation : la suppleance
 * revient au membre du comite executif signataire de la delegation (annexe H).
 */
function dossier_approuver(int $dossierId): array
{
    if (($refus = droit_ecriture('dossier_approuver')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    $suppleant = trim((string)(param('suppleant_approbation') ?? ''));
    if ((int)user_tiers_id() === (int)$d['tiers_id']) {
        return ['success' => false, 'error' => 'Vous êtes le bénéficiaire de ce dossier : l\'approbation revient au suppléant, '
            . ($suppleant !== '' ? '« ' . $suppleant . ' »' : 'membre du comité exécutif signataire de la délégation')
            . '. Elle ne se donne pas à soi-même.'];
    }
    // « Approbation en conflit : suppleance par le membre du comite executif
    // signataire de la delegation » (annexe H). Quand le Coordinateur en titre est
    // le beneficiaire, l'approbation n'est recevable que du suppleant designe.
    $sc = db()->prepare(
        "SELECT u.tiers_id FROM affectations a JOIN utilisateurs u ON u.id = a.utilisateur_id
          WHERE a.projet_id = ? AND a.role = 'coordinateur'
            AND a.date_debut <= CURDATE() AND (a.date_fin IS NULL OR a.date_fin >= CURDATE())"
    );
    $sc->execute([projet_id()]);
    $coordinateurs = array_map('intval', $sc->fetchAll(PDO::FETCH_COLUMN));
    if (in_array((int)$d['tiers_id'], $coordinateurs, true)) {
        if ($suppleant === '') {
            return ['success' => false, 'error' => 'Ce dossier bénéficie au Coordinateur : son approbation revient au suppléant, '
                . 'et aucun suppléant n\'est désigné dans les paramètres du projet.'];
        }
        $sn = db()->prepare('SELECT nom FROM tiers WHERE id = ?');
        $sn->execute([(int)user_tiers_id()]);
        if (mb_strtolower(trim((string)$sn->fetchColumn())) !== mb_strtolower($suppleant)) {
            return ['success' => false, 'error' => 'Ce dossier bénéficie au Coordinateur : seul le suppléant désigné, '
                . '« ' . $suppleant .' », peut l\'approuver.'];
        }
    }
    if (imputation_dossier($dossierId) === null) {
        return ['success' => false, 'error' => 'Un dossier s\'impute avant d\'être approuvé.'];
    }
    if (in_array($d['statut'], ['clos', 'abandonne', 'regle', 'approuve'], true)) {
        return ['success' => false, 'error' => 'Un dossier ' . $d['statut'] . ' ne s\'approuve plus.'];
    }
    $imputation = imputation_dossier($dossierId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dossiers SET statut = 'approuve', approuve_par = ?, approuve_le = NOW() WHERE id = ?")
            ->execute([user_id(), $dossierId]);
        dossier_poser_ecriture($d, $imputation);
        audit_strict('depenses', 'dossier_approuve', 'dossier', $dossierId, $d['numero'] . ' · ' . $d['objet']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('dossier_approuver: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Approbation impossible : ' . $e->getMessage()];
    }
    return ['success' => true];
}

/**
 * Pose l'ecriture de la depense au moment de l'approbation.
 *
 * Sans elle, le reglement seul ecrivait - le tiers au debit, la banque au credit -
 * et les comptes de charge restaient a zero : la partie double tenait sans dire ce
 * que l'argent avait paye. La facture recue debite la charge de la ligne et credite
 * le fournisseur ; le reglement solde ensuite le fournisseur (CDC 4.8).
 *
 * Deux types n'ecrivent rien ici. Un service a un particulier a deja son ecriture
 * d'honoraires, posee par Remuneration avec sa retenue. Un versement a la DGI
 * n'impute que pour memoire et ne consomme aucune ligne.
 */
function dossier_poser_ecriture(array $d, ?array $imputation): void
{
    if ($imputation === null || $imputation['nature'] === 'memoire' || $d['type'] === 'service_particulier') {
        return;
    }
    $sd = db()->prepare("SELECT COUNT(*) FROM ecritures WHERE projet_id = ? AND origine_ref = ?");
    $sd->execute([projet_id(), 'dossier:' . $d['id']]);
    if ((int)$sd->fetchColumn() > 0) {
        return;   // deja posee, l'approbation a ete rejouee
    }
    $libelle = $d['numero'] . ' — ' . $d['objet'];
    if ($d['type'] === 'remboursement_frais') {
        ecriture_remboursement_frais((int)$imputation['ligne_id'], (int)$d['tiers_id'],
            (float)$imputation['montant'], date('Y-m-d'), $libelle, 'dossier:' . $d['id']);
        return;
    }
    ecriture_facture((int)$imputation['ligne_id'], (int)$d['tiers_id'],
        (float)$imputation['montant'], date('Y-m-d'), $libelle, 'dossier:' . $d['id']);
}

/**
 * Demande a Comptes de produire le reglement. Seconde etape bloquante du cycle.
 *
 * « Le recu du beneficiaire est signe a la main puis scanne avant tout mouvement
 * de fonds, le decalage entre la signature et le virement se comptant en minutes.
 * Cette regle ne souffre aucune derogation, et aucun role ne peut la lever »
 * (CDC 4.3). Toutes les pieces prealables au paiement sont donc exigees, le recu
 * comme les autres.
 *
 * @return array{success: bool, reglement_id?: int, numero?: string, error?: string, alertes?: string[]}
 */
function dossier_demander_reglement(int $dossierId, array $reglement): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    if ($d['statut'] !== 'approuve') {
        return ['success' => false, 'error' => 'Le règlement suit l\'approbation du dossier.'];
    }
    $imputation = imputation_dossier($dossierId);
    if ($imputation === null) {
        return ['success' => false, 'error' => 'Ce dossier n\'est pas imputé.'];
    }
    $manquantes = dossier_pieces_manquantes($dossierId, 'avant');
    if ($manquantes) {
        return ['success' => false, 'error' => 'Pièces préalables au paiement manquantes : '
            . implode(', ', $manquantes) . '. Aucun rôle ne peut lever cette règle.'];
    }
    $concurrence = concurrence_incomplete($dossierId, (float)$imputation['montant'], (string)$d['type']);
    if ($concurrence !== null) {
        return ['success' => false, 'error' => $concurrence];
    }

    // Le montant regle egale l'imputation, sauf sur les honoraires : la ligne est
    // consommee pour le brut, l'intervenant recoit le net, et la difference part a
    // la DGI (CDC 4.4). L'appelant peut donc reduire le montant, jamais l'augmenter.
    $montant = round((float)($reglement['montant'] ?? $imputation['montant']), 2);
    if ($montant > round((float)$imputation['montant'], 2) + 0.005) {
        return ['success' => false, 'error' => sprintf('Le règlement demandé (%s) dépasse le montant imputé (%s).',
            htg($montant), htg((float)$imputation['montant']))];
    }
    if ($montant <= 0) {
        return ['success' => false, 'error' => 'Le montant à régler doit être strictement positif.'];
    }

    $res = reglement_creer([
        'mode'            => (string)($reglement['mode'] ?? param('mode_reglement_defaut', 'virement')),
        'numero_cheque'   => (string)($reglement['numero_cheque'] ?? ''),
        'beneficiaire_id' => (int)($reglement['beneficiaire_id'] ?? $d['tiers_id']),
        'compte_id'       => (int)($reglement['compte_id'] ?? 0),
        'montant'         => $montant,
        'devise'          => (string)($reglement['devise'] ?? 'HTG'),
        'montant_devise'  => $reglement['montant_devise'] ?? null,
        'taux_change'     => $reglement['taux_change'] ?? null,
        'preuve_taux_fichier_id' => $reglement['preuve_taux_fichier_id'] ?? null,
        'objet'           => $d['numero'] . ' — ' . $d['objet'],
        'origine_module'  => 'depenses',
        'origine_ref'     => ($d['type'] === 'remboursement_frais' ? 'dossier_avance:' : 'dossier:') . $dossierId,
    ]);
    if (empty($res['success'])) {
        return $res;
    }
    db()->prepare('UPDATE dossiers SET reglement_ref = ? WHERE id = ?')->execute([$res['numero'], $dossierId]);
    audit('depenses', 'reglement_demande', 'dossier', $dossierId, $d['numero'] . ' · règlement ' . $res['numero']);
    return $res + ['alertes' => ecart_recu_reglement($dossierId, date('Y-m-d'))];
}

/**
 * « Au-dessus du seuil, trois proformas sont exiges, et le choix d'une offre autre
 * que la moins-disante impose un motif ecrit » (CDC 4.3). Le motif se verifie a la
 * selection ; le nombre d'offres, lui, ne peut se verifier qu'au moment de payer,
 * puisque les offres arrivent une a une.
 *
 * @return string|null le motif du refus, ou null si la concurrence est en regle
 */
function concurrence_incomplete(int $dossierId, float $montant, string $typeDossier): ?string
{
    if (!concurrence_requise($montant, $typeDossier)) {
        return null;
    }
    $offres = proformas_dossier($dossierId);
    if (count($offres) < 3) {
        return sprintf('Ce dossier dépasse le seuil de mise en concurrence : trois proformas sont exigés, %d versé(s).',
            count($offres));
    }
    $retenues = array_filter($offres, fn($o) => (int)$o['retenu'] === 1);
    if (!$retenues) {
        return 'Aucune offre n\'est retenue : le choix doit être arrêté avant le règlement.';
    }
    return null;
}

/**
 * « Bousol controle l'ecart entre la date du recu et celle du reglement et alerte
 * au-dela du delai parametre » (CDC 4.3). L'ecart alerte, il ne bloque pas : c'est
 * l'absence du recu qui bloque, pas son anciennete.
 *
 * @return string[] alertes
 */
function ecart_recu_reglement(int $dossierId, string $dateReglement): array
{
    $st = db()->prepare("SELECT date_piece FROM pieces WHERE dossier_id = ? AND type = 'recu_beneficiaire' AND statut = 'recue'");
    $st->execute([$dossierId]);
    $dateRecu = $st->fetchColumn();
    if ($dateRecu === false || $dateRecu === null) {
        return [];
    }
    $delai = param('ecart_recu_reglement_jours');
    if ($delai === null) {
        return [];
    }
    $ecart = (int)(new DateTimeImmutable((string)$dateRecu))->diff(new DateTimeImmutable($dateReglement))->format('%r%a');
    if ($ecart > (int)$delai) {
        return [sprintf('Le reçu est daté du %s et le règlement du %s, soit %d jour(s) d\'écart pour un délai toléré de %d.',
            date_fr((string)$dateRecu), date_fr($dateReglement), $ecart, (int)$delai)];
    }
    if ($ecart < 0) {
        return [sprintf('Le reçu est daté du %s, postérieur au règlement du %s : le reçu se signe avant le mouvement de fonds.',
            date_fr((string)$dateRecu), date_fr($dateReglement))];
    }
    return [];
}

/**
 * Clot le dossier. « Le dossier ne peut etre clos tant qu'une piece obligatoire
 * manque » (CDC 4.2) - y compris les pieces posterieures au paiement, preuve de
 * virement, copie de cheque et recu de la DGI. Elles n'empechent pas le figement
 * de la periode puisqu'elles ne modifient aucun montant, mais elles empechent la
 * cloture du dossier.
 */
function dossier_clore(int $dossierId): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    if ($d['statut'] === 'clos') {
        return ['success' => false, 'error' => 'Ce dossier est déjà clos.'];
    }
    if ($d['statut'] !== 'regle') {
        return ['success' => false, 'error' => 'Un dossier se clôt après son règlement.'];
    }
    $manquantes = dossier_pieces_manquantes($dossierId);
    if ($manquantes) {
        return ['success' => false, 'error' => 'Pièces obligatoires manquantes : ' . implode(', ', $manquantes) . '.'];
    }
    db()->prepare("UPDATE dossiers SET statut = 'clos' WHERE id = ?")->execute([$dossierId]);
    audit('depenses', 'dossier_clos', 'dossier', $dossierId, $d['numero'] . ' · ' . $d['objet']);
    return ['success' => true];
}

/** Un dossier abandonne avant reglement ne consomme aucun numero de piece (CDC 4.3). */
function dossier_abandonner(int $dossierId, string $motif): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    if (in_array($d['statut'], ['regle', 'clos'], true)) {
        return ['success' => false, 'error' => 'Un dossier réglé ne s\'abandonne pas : il se corrige par une écriture inverse.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif d\'abandon est obligatoire.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM imputations WHERE dossier_id = ?')->execute([$dossierId]);
        $pdo->prepare("UPDATE dossiers SET statut = 'abandonne' WHERE id = ?")->execute([$dossierId]);
        audit_strict('depenses', 'dossier_abandonne', 'dossier', $dossierId, $d['numero'] . ' · ' . trim($motif));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Abandon impossible.'];
    }
    return ['success' => true];
}

/**
 * En regime electronique, une case de checklist est satisfaite quand toutes les
 * appositions attendues sont posees sur son document : il n'y a rien a scanner,
 * la signature est dans l'outil. En regime papier la fonction ne fait rien, et
 * c'est le scan qui satisfait la case.
 */
function dossier_constater_signatures(int $dossierId): void
{
    foreach (pieces_dossier($dossierId) as $p) {
        if ($p['statut'] !== 'attendue' || empty($p['document_id'])) {
            continue;
        }
        $doc = document_de((string)$p['type'], 'dossier', $dossierId);
        if ($doc !== null && document_signe($doc)) {
            db()->prepare("UPDATE pieces SET statut = 'recue', fichier_id = ?, date_piece = CURDATE() WHERE id = ?")
                ->execute([$doc['fichier_id'], (int)$p['id']]);
            audit('depenses', 'piece_signee', 'dossier', $dossierId,
                $p['libelle'] . ' · toutes les appositions attendues sont posées');
        }
    }
}

/** Passe le dossier a l'etat regle quand son reglement a ete execute par Comptes. */
function dossier_constater_reglement(int $dossierId): void
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM reglements WHERE projet_id = ? AND origine_ref IN (?, ?) AND statut = 'execute'"
    );
    $st->execute([projet_id(), 'dossier:' . $dossierId, 'dossier_avance:' . $dossierId]);
    if ((int)$st->fetchColumn() > 0) {
        db()->prepare("UPDATE dossiers SET statut = 'regle' WHERE id = ? AND statut = 'approuve'")->execute([$dossierId]);
    }
}

// ---------------------------------------------------------------------
// Rendu documentaire des pieces que l'outil produit lui-meme
// ---------------------------------------------------------------------

/**
 * Genere la piece d'un dossier quand l'outil sait la produire.
 *
 * Six des huit cases d'un dossier d'achat sortent de Bousol : la fiche
 * d'imputation, le bon de commande, le bon de decaissement, le bon de reception,
 * l'ordre de mission et la fiche de calcul. Les deux autres - la facture et le
 * recu - sont etablies par un tiers et reviennent numerisees. « L'outil ne les
 * genere pas et ne pourrait pas les generer sans les fabriquer. »
 *
 * En regime papier, le document produit s'imprime, se signe a la main et revient
 * scanne : la case reste attendue jusqu'a ce scan. En regime electronique, il
 * entre dans la file de signature.
 *
 * @return array{success: bool, document_id?: int, error?: string}
 */
function dossier_generer_piece(int $pieceId): array
{
    $st = db()->prepare('SELECT * FROM pieces WHERE id = ? AND projet_id = ?');
    $st->execute([$pieceId, projet_id()]);
    $piece = $st->fetch();
    if ($piece === false) {
        return ['success' => false, 'error' => 'Pièce inconnue dans ce projet.'];
    }
    if (!piece_generable((string)$piece['type'])) {
        return ['success' => false, 'error' => 'Cette pièce est établie par un tiers : l\'outil la reçoit numérisée, il ne la produit pas.'];
    }
    $d = dossier((int)$piece['dossier_id']);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu.'];
    }
    $imputation = imputation_dossier((int)$d['id']);
    if ($imputation === null && $piece['type'] !== 'ordre_mission') {
        return ['success' => false, 'error' => 'Le dossier doit être imputé avant de produire cette pièce.'];
    }

    $sb = db()->prepare('SELECT nom, nif, adresse, fonction FROM tiers WHERE id = ?');
    $sb->execute([(int)$d['tiers_id']]);
    $tiers = $sb->fetch() ?: ['nom' => '', 'nif' => null, 'adresse' => null, 'fonction' => null];

    $donnees = ['dossier' => $d, 'imputation' => $imputation];
    switch ($piece['type']) {
        case 'fiche_imputation':
            $ligne = budget_ligne_par_id((int)$imputation['ligne_id']);
            $consomme = budget_consomme_ligne((int)$imputation['ligne_id']);
            $donnees += [
                'solde_avant' => (float)($ligne['montant_gestion'] ?? 0),
                'consomme'    => $consomme['montant'],
                'solde_apres' => round((float)($ligne['montant_gestion'] ?? 0) - $consomme['montant'], 2),
                'derogation'  => $d['derogation_quantite_motif'],
            ];
            break;
        case 'bon_commande':
            $offres = proformas_dossier((int)$d['id']);
            $retenue = null;
            foreach ($offres as $o) {
                if ((int)$o['retenu'] === 1) {
                    $retenue = $o;
                }
            }
            $donnees += ['fournisseur' => $tiers, 'offres' => $offres, 'offre_retenue' => $retenue];
            break;
        case 'bon_reception':
            $donnees += ['fournisseur' => $tiers];
            break;
        case 'ordre_mission':
        case 'fiche_calcul':
            $donnees += ['missionnaire' => $tiers];
            break;
        case 'bon_decaissement':
            $sr = db()->prepare("SELECT r.mode, c.code, c.libelle FROM reglements r JOIN comptes c ON c.id = r.compte_id
                                  WHERE r.projet_id = ? AND r.origine_ref IN (?, ?) AND r.statut <> 'annule' ORDER BY r.id DESC LIMIT 1");
            $sr->execute([projet_id(), 'dossier:' . $d['id'], 'dossier_avance:' . $d['id']]);
            $reglement = $sr->fetch();
            $donnees += [
                'beneficiaire' => $tiers,
                'montant'      => (float)$imputation['montant'],
                'mode'         => MODES_REGLEMENT[$reglement['mode'] ?? param('mode_reglement_defaut', 'virement')]
                                  ?? 'Virement',
                'compte'       => $reglement ? $reglement['code'] . ' — ' . $reglement['libelle'] : '—',
            ];
            break;
    }

    $res = document_generer((string)$piece['type'], $donnees, 'dossier', (int)$d['id'], 'depenses');
    if (empty($res['success'])) {
        return $res;
    }
    db()->prepare('UPDATE pieces SET document_id = ? WHERE id = ?')->execute([(int)$res['document_id'], $pieceId]);
    return $res;
}
