<?php
declare(strict_types=1);

/**
 * Module Budget - nomenclature du projet et les sept controles du CDC 2.3.
 *
 * Deux budgets coexistent en permanence (CDC 2.2). Le budget contractuel est celui
 * de l'annexe du contrat, fige, modifiable seulement par avenant signe, et seul a
 * alimenter la colonne budget du rapport financier : c'est la colonne `montant`.
 * Le budget de gestion est la vue interne que les reallocations et les mobilisations
 * de provision mettent a jour : ce sont les colonnes `montant_gestion` et
 * `quantite_gestion`. Il n'a pas de table propre, le journal d'audit conservant la
 * trace de chaque changement avec son motif et sa piece d'autorisation.
 *
 * Rien ici n'ecrit dans les imputations : le module Depenses les posera, et
 * appellera budget_controle_imputation() avant de le faire.
 */

require_once __DIR__ . '/calendrier.php';
require_once __DIR__ . '/referentiels.php';  // UNITES, PARAMETRES_REGISTRE
require_once __DIR__ . '/uploads.php';       // fichier() : la piece d'autorisation d'une reallocation
require_once __DIR__ . '/audit.php';

/**
 * Le controle de quantite ne vaut que pour les unites denombrables (CDC 2.3).
 * Une ligne au forfait ne se controle qu'en montant, faute de quoi un reglement
 * en deux temps, avance puis solde, serait refuse alors que l'enveloppe est
 * respectee.
 */
const UNITES_DENOMBRABLES = ['mois', 'jour', 'unite', 'personne'];

/** Les lignes du projet, indexees par code, dans l'ordre de la nomenclature. */
function budget_lignes(?int $projetId = null): array
{
    $st = db()->prepare('SELECT * FROM lignes_budgetaires WHERE projet_id = ? ORDER BY ordre');
    $st->execute([$projetId ?? projet_id()]);
    $lignes = [];
    foreach ($st->fetchAll() as $l) {
        $lignes[$l['code']] = $l;
    }
    return $lignes;
}

/** Une ligne par son code, dans le projet courant. */
function budget_ligne(string $code, ?int $projetId = null): ?array
{
    $st = db()->prepare('SELECT * FROM lignes_budgetaires WHERE projet_id = ? AND code = ?');
    $st->execute([$projetId ?? projet_id(), $code]);
    $l = $st->fetch();
    return $l === false ? null : $l;
}

/**
 * Consommation constatee, par identifiant de ligne : montant et quantite imputes.
 * Les imputations de nature 'memoire' ne consomment pas de budget (CDC 4.4).
 */
function budget_consomme(?int $projetId = null): array
{
    $st = db()->prepare(
        "SELECT ligne_id, SUM(montant) AS montant, SUM(quantite) AS quantite
           FROM imputations WHERE projet_id = ? AND nature = 'consommation'
          GROUP BY ligne_id"
    );
    $st->execute([$projetId ?? projet_id()]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['ligne_id']] = ['montant' => (float)$r['montant'], 'quantite' => (float)$r['quantite']];
    }
    return $out;
}

/** Consommation d'une seule ligne, montant et quantite. */
function budget_consomme_ligne(int $ligneId): array
{
    $st = db()->prepare(
        "SELECT COALESCE(SUM(montant),0) AS montant, COALESCE(SUM(quantite),0) AS quantite
           FROM imputations WHERE ligne_id = ? AND nature = 'consommation'"
    );
    $st->execute([$ligneId]);
    $r = $st->fetch();
    return ['montant' => (float)$r['montant'], 'quantite' => (float)$r['quantite']];
}

/**
 * La ligne qui porte la provision pour imprevus, et celle qui porte les couts
 * indirects. Leur code differe d'un bailleur a l'autre (ligne 10 et ligne 8 sur
 * KesKle, ligne 5.1 et ligne 6 sur Koule Ki Pale) : c'est donc un parametre du
 * projet et non une constante de l'outil (CDC 2.5, annexe F).
 */
function budget_ligne_provision(?int $projetId = null): ?array
{
    $code = param('ligne_provision_code', null, $projetId);
    return $code === null ? null : budget_ligne($code, $projetId);
}

function budget_ligne_couts_indirects(?int $projetId = null): ?array
{
    $code = param('ligne_couts_indirects_code', null, $projetId);
    return $code === null ? null : budget_ligne($code, $projetId);
}

/**
 * Couts directs contractuels : la somme des rubriques de premier niveau.
 * On ne somme pas les lignes imputables, dont le detail n'est pas toujours
 * communique par le bailleur - le budget approuve de Koule Ki Pale ne donne
 * que les six sous-totaux de rubrique.
 */
function budget_couts_directs_contractuels(?int $projetId = null): float
{
    $st = db()->prepare(
        "SELECT COALESCE(SUM(montant),0) FROM lignes_budgetaires
          WHERE projet_id = ? AND nature = 'rubrique' AND niveau = 1"
    );
    $st->execute([$projetId ?? projet_id()]);
    return (float)$st->fetchColumn();
}

/**
 * Taux des couts indirects, deduit du budget contractuel plutot que fige a 7 % :
 * les deux bailleurs retiennent le meme taux sous deux noms, mais rien ne dit
 * qu'un troisieme le ferait.
 */
function budget_taux_indirect(?int $projetId = null): float
{
    $ligne = budget_ligne_couts_indirects($projetId);
    $directs = budget_couts_directs_contractuels($projetId);
    if ($ligne === null || $ligne['montant'] === null || $directs <= 0) {
        return 0.07;
    }
    return round((float)$ligne['montant'] / $directs, 6);
}

/**
 * Controle 5 - couts indirects. Sept pour cent des couts directs constates,
 * recalcule en continu et affiche comme enveloppe theorique. Ne bloque rien,
 * et se fige a la cloture.
 */
function budget_couts_indirects_constates(?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $provision = budget_ligne_provision($pid);
    $indirect  = budget_ligne_couts_indirects($pid);

    // Les couts indirects ne s'assoient pas sur eux-memes. La provision, en
    // revanche, ne sort de l'assiette que lorsqu'elle a sa ligne dediee : sur
    // Koule Ki Pale elle partage sa ligne avec les frais bancaires, qui sont une
    // charge directe et reelle - les 910 800 gourdes de couts directs du contrat
    // incluent bien ces 40 000.
    $exclus = [];
    if ($indirect !== null) {
        $exclus[] = (int)$indirect['id'];
    }
    if ($provision !== null && $provision['nature'] !== 'imputable') {
        $exclus[] = (int)$provision['id'];
    }

    $sql = "SELECT COALESCE(SUM(i.montant),0) FROM imputations i
             WHERE i.projet_id = ? AND i.nature = 'consommation'";
    $args = [$pid];
    if ($exclus) {
        $sql .= ' AND i.ligne_id NOT IN (' . implode(',', array_fill(0, count($exclus), '?')) . ')';
        $args = array_merge($args, array_map('intval', array_values($exclus)));
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    $directs = (float)$st->fetchColumn();
    $taux = budget_taux_indirect($pid);

    return [
        'directs_constates' => $directs,
        'taux'              => $taux,
        'enveloppe'         => round($directs * $taux, 2),
        'enveloppe_contractuelle' => $indirect === null ? null : (float)($indirect['montant_gestion'] ?? $indirect['montant']),
    ];
}

/**
 * Total du budget de gestion, oppose au plafond contractuel (controle 7).
 * On somme les lignes imputables, puis les deux enveloppes qui s'y ajoutent sans
 * etre imputables. Les lignes 7, 9 et 11 de l'annexe A sont des sous-totaux :
 * les additionner compterait trois fois le meme argent.
 */
function budget_total(string $colonne = 'montant_gestion', ?int $projetId = null, array $deltas = []): float
{
    $pid = $projetId ?? projet_id();
    $lignes = budget_lignes($pid);
    $provision = budget_ligne_provision($pid);
    $indirect  = budget_ligne_couts_indirects($pid);
    $enveloppes = [];
    foreach ([$provision, $indirect] as $l) {
        if ($l !== null && $l['nature'] !== 'imputable') {
            $enveloppes[$l['code']] = true;
        }
    }

    $detail = [];        // rubrique => somme des lignes, colonne demandee
    $contractuel = [];   // rubrique => somme des lignes au contrat, pour juger de la ventilation
    $total = 0.0;
    foreach ($lignes as $code => $l) {
        if ($l['nature'] === 'imputable') {
            $r = (string)($l['rubrique'] ?? '?');
            $detail[$r] = ($detail[$r] ?? 0.0) + (float)($l[$colonne] ?? 0) + (float)($deltas[$code] ?? 0);
            $contractuel[$r] = ($contractuel[$r] ?? 0.0) + (float)($l['montant'] ?? 0);
        } elseif (isset($enveloppes[$code])) {
            // Une enveloppe qui n'est pas une ligne imputable s'ajoute telle quelle.
            $total += (float)($l[$colonne] ?? 0) + (float)($deltas[$code] ?? 0);
        }
    }

    // Une rubrique dont le detail n'est pas encore saisi vaut son sous-total au
    // contrat, et non zero : le budget approuve de Koule Ki Pale ne communique que
    // les six sous-totaux, et compter ses onze lignes vides ramenerait le projet a
    // 103 756 gourdes pour un plafond de 974 556. Le controle du plafond serait
    // alors sans effet, et la part des ressources humaines afficherait 231 %.
    $rubriques = [];
    foreach ($lignes as $l) {
        if ($l['nature'] !== 'rubrique' || (int)$l['niveau'] !== 1) {
            continue;
        }
        $r = (string)($l['rubrique'] ?? '?');
        $rubriques[$r] = true;
        $sousTotal = $l['montant'] === null ? null : (float)$l['montant'];
        $ventilee = $sousTotal === null || abs($sousTotal - ($contractuel[$r] ?? 0.0)) < 0.01;
        $total += $ventilee ? ($detail[$r] ?? 0.0) : $sousTotal;
    }
    // Une ligne imputable sans rubrique de premier niveau compte pour elle-meme.
    foreach ($detail as $r => $montant) {
        if (empty($rubriques[$r])) {
            $total += $montant;
        }
    }
    return round($total, 2);
}

function budget_total_gestion(?int $projetId = null, array $deltas = []): float
{
    return budget_total('montant_gestion', $projetId, $deltas);
}

/** Le meme total, sur la colonne contractuelle : 5 599 889,14 pour KesKle. */
function budget_total_contractuel(?int $projetId = null): float
{
    return budget_total('montant', $projetId);
}

/**
 * Ecart entre le budget de gestion et le budget contractuel, a la granularite
 * retenue par le projet (controle 3). Sur KesKle la variation s'apprecie entre
 * rubriques principales, sur Koule Ki Pale entre lignes budgetaires, la FOKAL
 * retenant la lecture la plus restrictive.
 *
 * Les mouvements internes a un meme groupe ne comptent pas dans la variation :
 * c'est la consequence du regroupement, pas une regle a coder en plus.
 */
function budget_variations(?int $projetId = null, array $deltas = []): array
{
    $pid = $projetId ?? projet_id();
    $granularite = param('granularite_variation', 'rubrique', $pid);
    $groupes = [];

    foreach (budget_lignes($pid) as $code => $l) {
        if ($l['nature'] !== 'imputable') {
            continue;
        }
        if ($granularite === 'ligne') {
            $cle = $code;
            $lib = $code . ' ' . $l['libelle'];
        } else {
            $cle = 'R' . ($l['rubrique'] ?? '?');
            $lib = 'Rubrique ' . ($l['rubrique'] ?? '?');
        }
        if (!isset($groupes[$cle])) {
            $groupes[$cle] = ['libelle' => $lib, 'contractuel' => 0.0, 'gestion' => 0.0];
        }
        $groupes[$cle]['contractuel'] += (float)($l['montant'] ?? 0);
        $groupes[$cle]['gestion']     += (float)($l['montant_gestion'] ?? 0) + (float)($deltas[$code] ?? 0);
        $groupes[$cle]['mouvement']    = ($groupes[$cle]['mouvement'] ?? 0.0) + (float)($deltas[$code] ?? 0);
    }

    // A la granularite rubrique, la reference est le sous-total du contrat lui-meme :
    // le detail par ligne n'est pas toujours communique (Koule Ki Pale). Tant qu'il
    // ne l'est pas, la rubrique vaut son sous-total des deux cotes, sinon un budget
    // non ventile afficherait 100 % de variation et bloquerait tout mouvement.
    if ($granularite !== 'ligne') {
        foreach (budget_lignes($pid) as $l) {
            if ($l['nature'] !== 'rubrique' || (int)$l['niveau'] !== 1 || $l['montant'] === null) {
                continue;
            }
            $cle = 'R' . ($l['rubrique'] ?? '?');
            if (!isset($groupes[$cle])) {
                continue;
            }
            $ventilee = abs((float)$l['montant'] - $groupes[$cle]['contractuel']) < 0.01;
            $groupes[$cle]['libelle'] = $l['code'] . ' ' . $l['libelle'];
            if (!$ventilee) {
                $groupes[$cle]['gestion'] = (float)$l['montant'] + ($groupes[$cle]['mouvement'] ?? 0.0);
            }
            $groupes[$cle]['contractuel'] = (float)$l['montant'];
        }
    }

    foreach ($groupes as $cle => $g) {
        $groupes[$cle]['ecart'] = round($g['gestion'] - $g['contractuel'], 2);
        $groupes[$cle]['variation_pct'] = $g['contractuel'] > 0
            ? round(abs($g['gestion'] - $g['contractuel']) / $g['contractuel'] * 100, 2)
            : null;
    }
    return $groupes;
}

/**
 * Coherence du detail : une rubrique dont les lignes imputables ne totalisent pas
 * son sous-total contractuel n'est pas encore saisie. Le budget approuve de
 * Koule Ki Pale ne communique que les sous-totaux, le detail restant a saisir.
 */
function budget_detail_manquant(?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $lignes = budget_lignes($pid);
    $sommes = [];
    foreach ($lignes as $l) {
        if ($l['nature'] === 'imputable') {
            $r = (string)($l['rubrique'] ?? '?');
            $sommes[$r] = ($sommes[$r] ?? 0.0) + (float)($l['montant'] ?? 0);
        }
    }
    $manque = [];
    foreach ($lignes as $l) {
        if ($l['nature'] !== 'rubrique' || (int)$l['niveau'] !== 1 || $l['montant'] === null) {
            continue;
        }
        $r = (string)($l['rubrique'] ?? '?');
        $ecart = round((float)$l['montant'] - ($sommes[$r] ?? 0.0), 2);
        if (abs($ecart) >= 0.01) {
            $manque[$l['code']] = ['libelle' => $l['libelle'], 'sous_total' => (float)$l['montant'],
                                   'detail' => $sommes[$r] ?? 0.0, 'ecart' => $ecart];
        }
    }
    return $manque;
}

/**
 * Part des ressources humaines de l'association dans le total. Inscrite a 24,29 %
 * dans l'annexe B, c'est un choix de depot de DevDynamics et non une contrainte du
 * bailleur : indicateur du tableau de bord, sans seuil ni alerte, il ne conditionne
 * aucune imputation (CDC 2.3, annexe H).
 */
function budget_part_rh(?int $projetId = null): ?float
{
    $pid = $projetId ?? projet_id();
    $st = db()->prepare(
        "SELECT COALESCE(SUM(montant),0) FROM lignes_budgetaires
          WHERE projet_id = ? AND nature = 'rubrique' AND niveau = 1 AND rubrique = 1"
    );
    $st->execute([$pid]);
    $rh = (float)$st->fetchColumn();
    $total = budget_total_contractuel($pid);
    return $total > 0 ? round($rh / $total * 100, 2) : null;
}

// ---------------------------------------------------------------------
// Les sept controles du CDC 2.3
// Trois se declenchent a l'imputation, trois a la reallocation, et le
// septieme - les couts indirects - est un recalcul permanent qui ne
// bloque rien : il est rendu par budget_couts_indirects_constates().
// ---------------------------------------------------------------------

/**
 * Controles a l'imputation (1 disponibilite en montant, 2 disponibilite en
 * quantite, 6 provision). Retourne la liste des refus ; vide, l'imputation passe.
 * Le module Depenses appelle cette fonction avant d'ecrire dans `imputations`.
 *
 * @return array<int, array{regle: string, message: string, derogeable: bool}>
 */
function budget_controle_imputation(int $ligneId, float $montant, float $quantite, bool $derogationQuantite = false): array
{
    $refus = [];
    $st = db()->prepare('SELECT * FROM lignes_budgetaires WHERE id = ?');
    $st->execute([$ligneId]);
    $l = $st->fetch();

    if ($l === false) {
        return [['regle' => 'ligne', 'message' => 'Ligne budgétaire inconnue.', 'derogeable' => false]];
    }
    // Cloisonnement : une depense ne s'impute jamais sur la ligne d'un autre projet (CDC 7.3).
    if ((int)$l['projet_id'] !== projet_id()) {
        return [['regle' => 'cloisonnement', 'message' => 'Cette ligne appartient à un autre projet.', 'derogeable' => false]];
    }
    if ($l['nature'] !== 'imputable') {
        return [['regle' => 'nature', 'message' => 'Ligne non imputable : ' . $l['code'] . ' est une ' . $l['nature'] . '.', 'derogeable' => false]];
    }

    // Controle 6 - provision. Toute imputation directe est interdite quand la
    // provision a sa ligne dediee. Quand elle partage sa ligne avec les frais
    // bancaires, la ligne reste imputable pour ces seuls frais, tout autre emploi
    // exigeant une autorisation ecrite du bailleur versee au dossier (CDC 2.6).
    $provision = budget_ligne_provision();
    if ($provision !== null && (int)$provision['id'] === $ligneId && param('regime_provision') === 'ligne_dediee') {
        $refus[] = ['regle' => 'provision', 'derogeable' => false,
                    'message' => 'Imputation directe interdite sur la provision pour imprévus : elle se mobilise sur autorisation téléversée.'];
    }

    $consomme = budget_consomme_ligne($ligneId);

    // Controle 1 - disponibilite en montant.
    $disponible = round((float)($l['montant_gestion'] ?? 0) - $consomme['montant'], 2);
    if (round($montant, 2) > $disponible) {
        $refus[] = ['regle' => 'montant', 'derogeable' => false,
                    'message' => sprintf('Solde insuffisant sur %s : %s disponible, %s demandé.',
                        $l['code'], htg($disponible), htg($montant))];
    }

    // Controle 2 - disponibilite en quantite, sur les seules unites denombrables.
    if (in_array((string)$l['unite'], UNITES_DENOMBRABLES, true) && $l['quantite_gestion'] !== null) {
        $reste = round((float)$l['quantite_gestion'] - $consomme['quantite'], 2);
        if (round($quantite, 2) > $reste) {
            $refus[] = ['regle' => 'quantite', 'derogeable' => true,
                        'message' => sprintf('Quantité budgétée dépassée sur %s : %s %s restant(s), %s demandé(s).',
                            $l['code'], rtrim(rtrim(number_format($reste, 2, ',', ' '), '0'), ','),
                            UNITES[$l['unite']] ?? $l['unite'],
                            rtrim(rtrim(number_format($quantite, 2, ',', ' '), '0'), ','))];
        }
    }

    // La derogation du Coordinateur ne leve que le controle de quantite, et sur
    // motif ecrit enregistre (CDC 2.3, annexe H).
    if ($derogationQuantite) {
        $refus = array_values(array_filter($refus, fn($r) => $r['regle'] !== 'quantite'));
    }
    return $refus;
}

/**
 * Controles a la reallocation (4 plancher, 3 variation, 7 plafond contractuel),
 * plus le volet mobilisation du controle 6.
 *
 * @param array<string, float> $deltasMontant  code de ligne => variation signee du montant de gestion
 * @param array<string, float> $deltasQuantite code de ligne => variation signee de la quantite de gestion
 * @param array{variation?: ?int, provision?: ?int} $autorisations fichiers d'autorisation televerses
 * @return array{refus: array, alertes: array}
 */
function budget_controle_reallocation(array $deltasMontant, array $deltasQuantite = [], array $autorisations = []): array
{
    $refus = [];
    $alertes = [];
    $lignes = budget_lignes();
    $consomme = budget_consomme();

    foreach ($deltasMontant as $code => $delta) {
        if (!isset($lignes[$code])) {
            $refus[] = ['regle' => 'ligne', 'message' => 'Ligne inconnue dans ce projet : ' . $code . '.'];
        }
    }
    if ($refus) {
        return ['refus' => $refus, 'alertes' => []];
    }

    // Controle 4 - plancher de reallocation. Sans lui, une ligne se retrouverait en
    // depassement par simple manipulation du budget, sans qu'aucune depense nouvelle
    // ait ete engagee.
    foreach ($deltasMontant as $code => $delta) {
        $l = $lignes[$code];
        $deja = $consomme[(int)$l['id']] ?? ['montant' => 0.0, 'quantite' => 0.0];
        $apres = round((float)($l['montant_gestion'] ?? 0) + $delta, 2);
        if ($apres < $deja['montant']) {
            $refus[] = ['regle' => 'plancher',
                        'message' => sprintf('%s : le budget de gestion tomberait à %s alors que %s sont déjà consommés.',
                            $code, htg($apres), htg($deja['montant']))];
        }
    }
    foreach ($deltasQuantite as $code => $dq) {
        $l = $lignes[$code] ?? null;
        if ($l === null || $l['quantite_gestion'] === null) {
            continue;
        }
        $deja = $consomme[(int)$l['id']] ?? ['montant' => 0.0, 'quantite' => 0.0];
        $apres = round((float)$l['quantite_gestion'] + $dq, 2);
        if ($apres < $deja['quantite']) {
            $refus[] = ['regle' => 'plancher',
                        'message' => sprintf('%s : la quantité de gestion tomberait à %s alors que %s sont déjà imputées.',
                            $code, $apres, $deja['quantite'])];
        }
    }

    // « Celle qui libere la provision ne vaut pas accord sur la reallocation »
    // (CDC 2.3) : deux autorisations exigees separement, donc deux pieces
    // distinctes. Sans ce controle, televerser deux fois le meme document
    // suffirait a lever les deux verrous d'un coup.
    if (!empty($autorisations['provision']) && !empty($autorisations['variation'])) {
        $a = fichier((int)$autorisations['provision']);
        $b = fichier((int)$autorisations['variation']);
        if ($a !== null && $b !== null && $a['empreinte'] === $b['empreinte']) {
            $refus[] = ['regle' => 'autorisations',
                        'message' => 'La même pièce est versée pour la mobilisation et pour la variation : '
                                   . 'les deux autorisations sont exigées séparément.'];
        }
    }

    // Controle 6 - mobilisation de la provision. Elle exige sa propre autorisation,
    // et si elle fait par ailleurs franchir le seuil de variation, les deux
    // autorisations sont exigees separement : celle qui libere la provision ne vaut
    // pas accord sur la reallocation.
    $provision = budget_ligne_provision();
    $mobilisation = $provision !== null && isset($deltasMontant[$provision['code']]) && $deltasMontant[$provision['code']] < 0;
    if ($mobilisation && empty($autorisations['provision'])) {
        $refus[] = ['regle' => 'provision',
                    'message' => 'Mobiliser la provision exige le téléversement de l\'autorisation qui la libère.'];
    }

    // Controle 3 - variation par rubrique ou par ligne, selon la granularite du projet.
    $alerte  = (int)(param('seuil_alerte_variation_pct', '20') ?? 20);
    $blocage = (int)(param('seuil_blocage_variation_pct', '25') ?? 25);
    foreach (budget_variations(null, $deltasMontant) as $g) {
        if ($g['variation_pct'] === null) {
            continue;
        }
        if ($g['variation_pct'] >= $blocage) {
            if (empty($autorisations['variation'])) {
                $refus[] = ['regle' => 'variation',
                            'message' => sprintf('%s : variation de %s %% (seuil de blocage %d %%). Une autorisation écrite doit être téléversée.',
                                $g['libelle'], number_format($g['variation_pct'], 2, ',', ' '), $blocage)];
            } else {
                $alertes[] = sprintf('%s : variation de %s %% levée par autorisation.',
                    $g['libelle'], number_format($g['variation_pct'], 2, ',', ' '));
            }
        } elseif ($g['variation_pct'] >= $alerte) {
            $alertes[] = sprintf('%s : variation de %s %% (seuil d\'alerte %d %%).',
                $g['libelle'], number_format($g['variation_pct'], 2, ',', ' '), $alerte);
        }
    }

    // Controle 7 - plafond contractuel. Il porte sur les reallocations et sur les
    // mobilisations de provision, non sur les imputations, qu'aucune ligne ne laisse
    // deborder.
    $plafond = plafond_contractuel();
    if ($plafond !== null) {
        $total = budget_total_gestion(null, $deltasMontant);
        if ($total > $plafond + 0.005) {
            $refus[] = ['regle' => 'plafond',
                        'message' => sprintf('Le budget de gestion atteindrait %s, au-delà du plafond contractuel de %s.',
                            htg($total), htg($plafond))];
        }
    }

    return ['refus' => $refus, 'alertes' => $alertes];
}

/**
 * Applique une reallocation deja controlee. Chaque ligne touchee laisse au journal
 * d'audit son montant avant et apres, son motif, son auteur et, le cas echeant, la
 * reference de la piece d'autorisation : le journal etant en ajout seul, cette trace
 * suffit a etablir qui a realloue quoi et quand, sans conserver de versions
 * successives du budget (CDC 2.2).
 */
function budget_appliquer_reallocation(array $deltasMontant, array $deltasQuantite, string $motif, array $autorisations = []): void
{
    $lignes = budget_lignes();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maj = $pdo->prepare(
            'UPDATE lignes_budgetaires SET montant_gestion = ?, quantite_gestion = ? WHERE id = ? AND projet_id = ?'
        );
        $trace = [];
        foreach ($deltasMontant as $code => $delta) {
            $l = $lignes[$code];
            $avantM = (float)($l['montant_gestion'] ?? 0);
            $avantQ = $l['quantite_gestion'] === null ? null : (float)$l['quantite_gestion'];
            $apresM = round($avantM + $delta, 2);
            $apresQ = $avantQ === null ? null : round($avantQ + (float)($deltasQuantite[$code] ?? 0), 2);
            $maj->execute([$apresM, $apresQ, (int)$l['id'], projet_id()]);
            $trace[] = sprintf('%s %s -> %s', $code, htg($avantM), htg($apresM));
        }

        $piece = [];
        foreach (['provision' => 'autorisation de mobilisation', 'variation' => 'autorisation de variation'] as $k => $lib) {
            if (!empty($autorisations[$k])) {
                $f = fichier((int)$autorisations[$k]);
                $piece[] = $lib . ' fichier #' . (int)$autorisations[$k] . ($f ? ' empreinte ' . substr($f['empreinte'], 0, 12) : '');
            }
        }
        $provision = budget_ligne_provision();
        $mobilisation = $provision !== null && ($deltasMontant[$provision['code']] ?? 0) < 0;

        // La trace est dans la transaction : le budget ne bouge pas sans elle,
        // puisque c'est elle qui tient lieu d'historique (CDC 2.2).
        audit_strict('budget', $mobilisation ? 'provision_mobilisee' : 'budget_realloue', 'budget', projet_id(),
            implode(' ; ', $trace) . ' · ' . $motif . ($piece ? ' · ' . implode(' ; ', $piece) : ''));

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
