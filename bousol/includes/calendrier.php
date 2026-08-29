<?php
declare(strict_types=1);

/**
 * Parametres (annexe F, historises) et calendrier relatif (CDC 1.4).
 * Aucune date n'est ecrite en dur : tout derive de `date_debut_execution`,
 * sauf la seconde borne (avril 2028), absolue.
 */

require_once __DIR__ . '/auth.php';   // projet_id() : les parametres et le calendrier suivent le projet courant
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

/**
 * Valeur en vigueur d'un parametre, pour un projet donne (le projet courant par defaut).
 * Il n'existe aucun parametre global (CDC 2.5).
 */
function &param_cache(): array
{
    static $cache = [];
    return $cache;
}

function param(string $cle, ?string $default = null, ?int $projetId = null): ?string
{
    $cache = &param_cache();
    $pid = $projetId ?? projet_id();
    if ($pid === null) {
        return $default;
    }
    $k = $pid . '|' . $cle;
    if (array_key_exists($k, $cache)) {
        return $cache[$k] ?? $default;
    }
    $stmt = db()->prepare(
        'SELECT valeur FROM parametres WHERE projet_id = ? AND cle = ? AND date_effet <= CURDATE()
          ORDER BY date_effet DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$pid, $cle]);
    $v = $stmt->fetchColumn();
    $cache[$k] = ($v === false || $v === null || $v === '') ? null : (string)$v;
    return $cache[$k] ?? $default;
}

/** Vide le cache des parametres : au changement de projet, et apres toute nouvelle version. */
function param_oublier(): void
{
    $cache = &param_cache();
    $cache = [];
}

/** Historique complet d'un parametre, dans un projet. */
function param_historique(string $cle, ?int $projetId = null): array
{
    $stmt = db()->prepare('SELECT * FROM parametres WHERE projet_id = ? AND cle = ? ORDER BY date_effet DESC, id DESC');
    $stmt->execute([$projetId ?? projet_id(), $cle]);
    return $stmt->fetchAll();
}

/** Nouvelle version d'un parametre (jamais d'ecrasement). Reserve au Coordinateur du projet. */
function param_set(string $cle, ?string $valeur, string $motif, ?string $dateEffet = null, ?int $projetId = null): void
{
    $pid = $projetId ?? projet_id();
    $stmt = db()->prepare(
        'INSERT INTO parametres (projet_id, cle, valeur, date_effet, motif, auteur_id) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$pid, $cle, $valeur, $dateEffet ?? date('Y-m-d'), $motif, $_SESSION['user_id'] ?? null]);
    param_oublier();
    audit('noyau', 'parametre_modifie', 'parametre', $cle, 'Nouvelle valeur: ' . ($valeur ?? '(vide)') . ' - ' . $motif);
}

function date_debut(): ?string
{
    return param('date_debut_execution');
}

function duree_mois(): int
{
    return (int)param('duree_execution_mois', '8');
}

/** Dernier jour de l'execution : debut + duree - 1 jour. */
function date_fin(): ?string
{
    $d = date_debut();
    if (!$d) {
        return null;
    }
    $dt = new DateTimeImmutable($d);
    return $dt->modify('+' . duree_mois() . ' months')->modify('-1 day')->format('Y-m-d');
}

/** Bornes [debut, fin] du mois de projet n (1..duree). */
function periode_mois(int $n): ?array
{
    $d = date_debut();
    if (!$d || $n < 1 || $n > duree_mois()) {
        return null;
    }
    $dt = new DateTimeImmutable($d);
    $debut = $dt->modify('+' . ($n - 1) . ' months');
    $fin   = $dt->modify('+' . $n . ' months')->modify('-1 day');
    return ['numero' => $n, 'debut' => $debut->format('Y-m-d'), 'fin' => $fin->format('Y-m-d')];
}

/** Numero du mois de projet contenant une date, ou null hors execution. */
function mois_projet(?string $date = null): ?int
{
    $d = date_debut();
    if (!$d) {
        return null;
    }
    $t = new DateTimeImmutable($date ?? 'today');
    for ($n = 1; $n <= duree_mois(); $n++) {
        $p = periode_mois($n);
        if ($t >= new DateTimeImmutable($p['debut']) && $t <= new DateTimeImmutable($p['fin'])) {
            return $n;
        }
    }
    return null;
}

/** Periode du rapport intermediaire : mois 1 a 4 (art. 4.3). */
function periode_intermediaire(): ?array
{
    $p1 = periode_mois(1);
    $p4 = periode_mois(4);
    return ($p1 && $p4) ? ['debut' => $p1['debut'], 'fin' => $p4['fin']] : null;
}

/** Seconde borne absolue : fin du programme PAIESC. */
function seconde_borne(): ?string
{
    return param('seconde_borne');
}

/** La double temporalite s'active projet par projet (CDC 1.7). */
function suivi_post_cloture(): bool
{
    return param('suivi_post_cloture', '0') === '1';
}

/** Duree residuelle de la phase 2 (en mois) : de la fin d'execution a la seconde borne. */
function duree_residuelle_phase2(): ?int
{
    $fin = date_fin();
    if (!$fin || !suivi_post_cloture() || !seconde_borne()) {
        return null;
    }
    $a = new DateTimeImmutable($fin);
    $b = new DateTimeImmutable(seconde_borne());
    if ($b <= $a) {
        return 0;
    }
    $diff = $a->diff($b);
    return $diff->y * 12 + $diff->m;
}

/** La date de debut n'est modifiable que tant qu'aucune ecriture n'existe. */
/** La date d'ancrage et la nomenclature restent modifiables tant que le projet n'a aucune ecriture. */
function calendrier_verrouille(?int $projetId = null): bool
{
    $pid = $projetId ?? projet_id();
    if ($pid === null) {
        return false;
    }
    $stmt = db()->prepare('SELECT (SELECT COUNT(*) FROM ecritures WHERE projet_id = ?) + (SELECT COUNT(*) FROM dossiers WHERE projet_id = ?)');
    $stmt->execute([$pid, $pid]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Une ecriture ne se pose que pendant l'execution du projet.
 *
 * L'annexe B ferme en phase 2 tout ce qui touche au budget - imputer, mobiliser la
 * provision - et l'annexe G exige qu'une imputation creee pendant la periode de
 * regularisation echoue. La regle de phase prime toujours sur l'interrupteur de
 * module (CDC 7.2) : le module peut etre actif, la phase decide.
 *
 * Un projet sans phase enregistree n'est pas un projet clos : on le laisse ouvert.
 */
function require_phase_execution(string $operation = 'Cette opération'): void
{
    $phase = phase_code();
    if ($phase === null || $phase === 'projet_actif') {
        return;
    }
    $lib = $phase === 'regularisation' ? 'la période de régularisation' : 'la phase de suivi post-clôture';
    http_response_code(403);
    exit('<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Phase close</title></head>'
       . '<body style="font-family:Candara,sans-serif;padding:3rem;color:#2a2a28">'
       . '<h1 style="font-family:Georgia,serif;color:#4c5a47">Opération fermée par la phase du projet</h1>'
       . '<p>' . e($operation) . ' n\'est plus possible pendant ' . e($lib) . '.</p>'
       . '<p><a href="' . e(base_path('dashboard.php')) . '">Retour au tableau de bord</a></p></body></html>');
}

/** Phase courante : projet_actif | regularisation | post_cloture (ou null avant initialisation). */
function phase_courante(?int $projetId = null): ?array
{
    $pid = $projetId ?? projet_id();
    if ($pid === null) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM phases WHERE projet_id = ? AND statut = 'en_cours' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pid]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function phase_code(): ?string
{
    $p = phase_courante();
    return $p['code'] ?? null;
}

function initialisation_complete(): bool
{
    return date_debut() !== null && param('numero_contrat') !== null;
}

/** Plafond contractuel du projet : la somme des lignes de gestion ne peut le depasser (CDC 2.3). */
function plafond_contractuel(): ?float
{
    $v = param('plafond_contractuel');
    return $v === null ? null : (float)$v;
}

/** Validation d'une valeur de parametre selon le registre (annexe F). Retourne un message d'erreur ou null. */
function valider_param(string $cle, ?string $valeur): ?string
{
    $def = PARAMETRES_REGISTRE[$cle] ?? null;
    if ($def === null) {
        return 'Paramètre inconnu.';
    }
    if ($def[3] === false) {
        return 'Ce paramètre n\'est pas modifiable.';
    }
    if ($def[3] === 'avant_ecriture' && calendrier_verrouille()) {
        return 'Modifiable uniquement tant qu\'aucune écriture n\'a été enregistrée.';
    }
    if ($valeur === null || $valeur === '') {
        return null;
    }
    switch ($def[1]) {
        case 'date':
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $valeur);
            return ($d && $d->format('Y-m-d') === $valeur) ? null : 'Date invalide (AAAA-MM-JJ).';
        case 'int':
            return preg_match('/^\d{1,6}$/', $valeur) ? null : 'Nombre entier attendu.';
        case 'decimal':
            return preg_match('/^\d{1,12}([.,]\d{1,2})?$/', $valeur) ? null : 'Montant attendu (ex. 30000 ou 30000.00).';
        case 'choix':
            return array_key_exists($valeur, $def[2] ?? []) ? null : 'Valeur hors liste.';
        default:
            return mb_strlen($valeur) <= 255 ? null : 'Texte trop long.';
    }
}

/**
 * Une periode figee ne se modifie plus : « un rapport valide fige sa periode, les
 * depenses de la periode ne sont plus modifiables » (CDC 6.7).
 *
 * Le controle vit dans le socle et non dans Restitution, pour que Depenses puisse
 * l'appeler sans dependre du module qui fige - le graphe des dependances va de
 * Restitution vers Depenses, jamais l'inverse (CDC 7.2).
 */
function periode_est_figee(?int $periodeId): bool
{
    if ($periodeId === null) {
        return false;
    }
    $st = db()->prepare("SELECT statut FROM periodes WHERE id = ?");
    $st->execute([$periodeId]);
    return $st->fetchColumn() === 'figee';
}

/** La periode qui couvre une date est-elle figee ? */
function date_dans_periode_figee(string $date): bool
{
    $p = periode_pour_date($date);
    return $p !== null && $p['statut'] === 'figee';
}

/** Les periodes (mois de projet) generees depuis la date de debut. */
function periodes(?int $projetId = null): array
{
    $stmt = db()->prepare('SELECT * FROM periodes WHERE projet_id = ? ORDER BY numero');
    $stmt->execute([$projetId ?? projet_id()]);
    return $stmt->fetchAll();
}

/**
 * (Re)genere les periodes depuis le calendrier relatif.
 * Refuse si une periode est figee ou si des ecritures existent.
 */
function generer_periodes(): bool
{
    if (!date_debut() || calendrier_verrouille()) {
        return false;
    }
    $pid = projet_id();
    $st = db()->prepare("SELECT COUNT(*) FROM periodes WHERE projet_id = ? AND statut <> 'ouverte'");
    $st->execute([$pid]);
    if ((int)$st->fetchColumn() > 0) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM periodes WHERE projet_id = ?')->execute([$pid]);
        $ins = $pdo->prepare('INSERT INTO periodes (projet_id, numero, date_debut, date_fin) VALUES (?,?,?,?)');
        for ($n = 1; $n <= duree_mois(); $n++) {
            $p = periode_mois($n);
            $ins->execute([$pid, $n, $p['debut'], $p['fin']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('generer_periodes: ' . $e->getMessage());
        return false;
    }
    audit('noyau', 'periodes_generees', 'projet', $pid, duree_mois() . ' périodes depuis le ' . date_debut());
    return true;
}

/** Periode contenant une date (ou null). */
function periode_pour_date(?string $date = null): ?array
{
    $stmt = db()->prepare('SELECT * FROM periodes WHERE projet_id = ? AND ? BETWEEN date_debut AND date_fin LIMIT 1');
    $stmt->execute([projet_id(), $date ?? date('Y-m-d')]);
    $p = $stmt->fetch();
    return $p ?: null;
}

/** Etat d'un module (interrupteur de maintenance, CDC 7.2). */
function module_etat(string $module): ?array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM module_etats')->fetchAll() as $m) {
            $cache[$m['module']] = $m;
        }
    }
    return $cache[$module] ?? null;
}

/** Bloque l'acces a un module en maintenance (503). Le Noyau ne se ferme jamais. */
function require_module(string $module): void
{
    $m = module_etat($module);
    if ($module !== 'noyau' && $m && $m['interrupteur'] === 'maintenance') {
        http_response_code(503);
        $lib = MODULES[$module][0] ?? $module;
        exit('<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Module en maintenance</title></head>'
           . '<body style="font-family:Candara,sans-serif;padding:3rem;color:#2a2a28"><h1 style="font-family:Georgia,serif;color:#4c5a47">Module ' . e($lib) . ' en maintenance</h1>'
           . '<p>' . e($m['motif'] ?? '') . '</p><p><a href="' . e(base_path('dashboard.php')) . '">Retour au tableau de bord</a></p></body></html>');
    }
}

/** Date du dernier export de sauvegarde telecharge et retard eventuel (CDC 7.6). */
function sauvegarde_etat(): array
{
    $dernier = param('dernier_export_le');
    $delai = (int)(param('delai_alerte_sauvegarde_jours') ?? 0);
    $retard = false;
    if ($delai > 0) {
        $ref = $dernier ? new DateTimeImmutable($dernier) : new DateTimeImmutable('2000-01-01');
        $retard = $ref->modify('+' . $delai . ' days') < new DateTimeImmutable('today');
    }
    return ['dernier' => $dernier, 'delai' => $delai, 'retard' => $retard];
}

/**
 * Controle d'integrite de la base : presence des triggers d'immuabilite (CDC 7.5).
 * Sur un hebergement mutualise, leur creation peut avoir ete refusee silencieusement
 * (erreur 1419) ; l'outil ne peut pas les installer lui-meme, mais il rend leur absence visible.
 *
 * @return array{attendus:int, presents:int, manquants:string[], ok:bool}
 */
function integrite_triggers(): array
{
    $attendus = [
        'trg_audit_no_update'       => 'Journal d\'audit non modifiable',
        'trg_audit_no_delete'       => 'Journal d\'audit non supprimable',
        'trg_parametres_no_update'  => 'Paramètres historisés (pas d\'écrasement)',
        'trg_parametres_no_delete'  => 'Paramètres non supprimables',
        'trg_appositions_no_update' => 'Apposition de signature non modifiable',
        'trg_appositions_no_delete' => 'Apposition de signature non supprimable',
        'trg_fichiers_no_delete'    => 'Fichier remplacé, jamais supprimé',
        'trg_imputations_ligne'     => 'Imputation refusée sur une ligne non imputable',
        'trg_tiers_nif_insert'      => 'NIF unique dans le référentiel des tiers',
        'trg_tiers_nif_update'      => 'NIF unique dans le référentiel des tiers (modification)',
    ];
    try {
        $presents = db()->query(
            'SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('integrite_triggers: ' . $e->getMessage());
        $presents = [];
    }
    $manquants = [];
    foreach ($attendus as $nom => $regle) {
        if (!in_array($nom, $presents, true)) {
            $manquants[$nom] = $regle;
        }
    }
    return [
        'attendus'  => count($attendus),
        'presents'  => count($attendus) - count($manquants),
        'manquants' => $manquants,
        'ok'        => $manquants === [],
    ];
}
