<?php
declare(strict_types=1);

/**
 * Parametres (annexe F, historises) et calendrier relatif (CDC 1.4).
 * Aucune date n'est ecrite en dur : tout derive de `date_debut_execution`,
 * sauf la seconde borne (avril 2028), absolue.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

/** Valeur en vigueur d'un parametre (derniere version dont la date d'effet est atteinte). */
function param(string $cle, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($cle, $cache)) {
        return $cache[$cle];
    }
    $stmt = db()->prepare(
        'SELECT valeur FROM parametres WHERE cle = ? AND date_effet <= CURDATE()
          ORDER BY date_effet DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$cle]);
    $v = $stmt->fetchColumn();
    $cache[$cle] = ($v === false || $v === null || $v === '') ? $default : (string)$v;
    return $cache[$cle];
}

/** Historique complet d'un parametre. */
function param_historique(string $cle): array
{
    $stmt = db()->prepare('SELECT * FROM parametres WHERE cle = ? ORDER BY date_effet DESC, id DESC');
    $stmt->execute([$cle]);
    return $stmt->fetchAll();
}

/** Nouvelle version d'un parametre (jamais d'ecrasement). Reserve au Coordinateur. */
function param_set(string $cle, ?string $valeur, string $motif, ?string $dateEffet = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO parametres (cle, valeur, date_effet, motif, auteur_id) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$cle, $valeur, $dateEffet ?? date('Y-m-d'), $motif, $_SESSION['user_id'] ?? null]);
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
function seconde_borne(): string
{
    return param('seconde_borne', '2028-04-30');
}

/** Duree residuelle de la phase 2 (en mois) : de la fin d'execution a la seconde borne. */
function duree_residuelle_phase2(): ?int
{
    $fin = date_fin();
    if (!$fin) {
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
function calendrier_verrouille(): bool
{
    $n = (int)db()->query('SELECT (SELECT COUNT(*) FROM ecritures) + (SELECT COUNT(*) FROM dossiers)')->fetchColumn();
    return $n > 0;
}

/** Phase courante : projet_actif | regularisation | post_cloture (ou null avant initialisation). */
function phase_courante(): ?array
{
    $row = db()->query("SELECT * FROM phases WHERE statut = 'en_cours' ORDER BY id DESC LIMIT 1")->fetch();
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

/** Les periodes (mois de projet) generees depuis la date de debut. */
function periodes(): array
{
    return db()->query('SELECT * FROM periodes ORDER BY numero')->fetchAll();
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
    $figees = (int)db()->query("SELECT COUNT(*) FROM periodes WHERE statut <> 'ouverte'")->fetchColumn();
    if ($figees > 0) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM periodes');
        $ins = $pdo->prepare('INSERT INTO periodes (numero, date_debut, date_fin) VALUES (?,?,?)');
        for ($n = 1; $n <= duree_mois(); $n++) {
            $p = periode_mois($n);
            $ins->execute([$n, $p['debut'], $p['fin']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('generer_periodes: ' . $e->getMessage());
        return false;
    }
    audit('noyau', 'periodes_generees', 'periode', null, duree_mois() . ' périodes depuis le ' . date_debut());
    return true;
}

/** Periode contenant une date (ou null). */
function periode_pour_date(?string $date = null): ?array
{
    $stmt = db()->prepare('SELECT * FROM periodes WHERE ? BETWEEN date_debut AND date_fin LIMIT 1');
    $stmt->execute([$date ?? date('Y-m-d')]);
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
