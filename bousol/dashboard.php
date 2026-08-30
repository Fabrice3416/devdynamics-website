<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_projet();

$init      = initialisation_complete();
$debut     = date_debut();
$fin       = date_fin();
$moisCour  = mois_projet();
$residuel  = duree_residuelle_phase2();
$contrat   = param('numero_contrat');
$plafond   = plafond_contractuel();

$modules = db()->query('SELECT * FROM module_etats ORDER BY id')->fetchAll();
$sauvegarde = sauvegarde_etat();
$integrite  = integrite_triggers();
$st = db()->prepare('SELECT * FROM journal_audit WHERE projet_code = ? OR projet_code IS NULL ORDER BY id DESC LIMIT 10');
$st->execute([projet_code()]);
$audit = $st->fetchAll();

/**
 * Les parametres de l'annexe F qui restent a saisir sur ce projet.
 *
 * La liste se derive du registre et n'est pas tenue a la main : une carte qui en
 * surveillait huit sur trente-trois annoncait « annexe F » en n'en montrant qu'un
 * quart, et laissait croire au Coordinateur qu'il n'avait plus rien a saisir.
 *
 * Trois familles sont ecartees, non par oubli mais parce qu'elles ne se saisissent
 * pas : celles que l'outil pose lui-meme (l'enveloppe indirecte figee a la bascule,
 * le seuil de blocage de variation), celles qui ne valent que pour un projet ayant
 * une phase de suivi post-cloture, et le couple contrat / date d'ancrage que
 * l'alerte d'initialisation annonce deja plus haut.
 *
 * Koule Ki Pale ferme en decembre 2026 sans phase 2 : lui reclamer un delai
 * d'accuse de reception et un delai de correctif, c'est lui demander de parametrer
 * ce qui n'arrivera pas.
 */
const PARAMETRES_PHASE_2 = ['seconde_borne', 'delai_accuse_phase2_heures', 'delai_correctif_phase2_jours'];

$aDefinir = [];
$poseParLOutil = array_keys(array_filter(PARAMETRES_REGISTRE, fn($d) => $d[3] === false));
$dejaAnnonces  = ['numero_contrat', 'date_debut_execution'];
$avecPhase2    = param('suivi_post_cloture', '0') === '1';
foreach (PARAMETRES_REGISTRE as $k => $def) {
    if (in_array($k, $poseParLOutil, true) || in_array($k, $dejaAnnonces, true)) {
        continue;
    }
    if (!$avecPhase2 && in_array($k, PARAMETRES_PHASE_2, true)) {
        continue;
    }
    if (param($k) === null) {
        $aDefinir[] = $def[0];
    }
}

page_start('Tableau de bord', 'dashboard');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">Tableau de bord <small class="text-muted fw-normal">· <?= e(projet_intitule()) ?></small></h1>
    <span class="text-muted small"><?= e(date_fr(date('Y-m-d'))) ?></span>
</div>

<?php if (!$init): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    <strong>Initialisation incomplète.</strong>
    Le numéro du contrat de subvention et la date de début d'exécution ne sont pas encore saisis.
    Le calendrier (fin d'exécution, mois de projet, périodes de rapport) en dérive entièrement.
    <?php if (user_role() === 'coordinateur'): ?> Saisie dans <a href="<?= e(base_path('modules/noyau/')) ?>">Paramétrage</a> (module Noyau, phase 1).<?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$integrite['ok']): ?>
<div class="alert alert-danger py-2">
    <i class="bi bi-shield-exclamation"></i> <strong>Protections d'immuabilité incomplètes en base.</strong>
    <?= (int)$integrite['presents'] ?> des <?= (int)$integrite['attendus'] ?> garde-fous SQL sont installés.
    Les règles suivantes ne sont plus appliquées par la base :
    <?= e(implode(' ; ', $integrite['manquants'])) ?>.
    Importer <code>database/schema_triggers.sql</code><?php if (user_role() === 'coordinateur'): ?> —
    <a href="<?= e(base_path('modules/noyau/modules.php')) ?>">détail</a><?php endif; ?>.
</div>
<?php endif; ?>

<?php if ($sauvegarde['retard'] && user_role() === 'coordinateur'): ?>
<div class="alert alert-warning py-2">
    <i class="bi bi-hdd"></i> <strong>Sauvegarde hors site en retard.</strong>
    Dernier export téléchargé : <?= $sauvegarde['dernier'] ? e(date_fr($sauvegarde['dernier'])) : 'jamais' ?> (délai d'alerte <?= (int)$sauvegarde['delai'] ?> jours).
    <a href="<?= e(base_path('modules/noyau/sauvegarde.php')) ?>">Générer l'export</a>.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Convention</div><div class="valeur fs-5"><?= e($contrat ?? 'À saisir') ?></div>
        <small class="text-muted"><?= e(projet_code()) ?></small></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Exécution</div>
        <div class="valeur fs-5"><?= $debut ? e(date_fr($debut)) . ' → ' . e(date_fr($fin)) : 'À saisir' ?></div>
        <?php if ($moisCour): ?><small class="text-muted">Mois de projet <?= $moisCour ?> / <?= duree_mois() ?></small><?php endif; ?>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Plafond contractuel</div>
        <div class="valeur fs-5"><?= $plafond === null ? 'À saisir' : e(htg($plafond)) ?></div>
        <small class="text-muted"><?php $nl = db()->prepare('SELECT COUNT(*) FROM lignes_budgetaires WHERE projet_id = ?'); $nl->execute([projet_id()]);
            echo (int)$nl->fetchColumn(); ?> lignes chargées</small></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Phase 2 résiduelle</div>
        <div class="valeur fs-5"><?= $residuel === null ? 'sans objet' : $residuel . ' mois' ?></div>
        <small class="text-muted"><?= $residuel === null ? 'suivi post-clôture désactivé' : 'jusqu\'au ' . e(date_fr(seconde_borne())) ?></small></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-grid-3x3-gap"></i> Modules</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <?php foreach ($modules as $m): ?>
                <tr>
                    <td><?= e($m['libelle']) ?> <?php if ($m['critique']): ?><span class="badge text-bg-light border" title="Module critique">critique</span><?php endif; ?></td>
                    <td class="text-muted small">v<?= e($m['version']) ?></td>
                    <td class="text-end"><span class="badge <?= $m['interrupteur'] === 'actif' ? 'badge-module-actif' : 'badge-module-maintenance' ?>"><?= e($m['interrupteur']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php if ($aDefinir): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between"><span><i class="bi bi-sliders"></i> Paramètres à définir (annexe F)</span><?php if (user_role() === 'coordinateur'): ?><a class="small fw-normal" href="<?= e(base_path('modules/noyau/')) ?>">Paramétrer</a><?php endif; ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($aDefinir as $p): ?><li class="list-group-item small"><?= e($p) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-journal-text"></i> Journal d'audit — dernières entrées</div>
            <table class="table table-sm mb-0 small">
                <?php foreach ($audit as $a): ?>
                <tr>
                    <td class="text-nowrap text-muted"><?= e(datetime_fr($a['horodatage'])) ?></td>
                    <td><span class="badge text-bg-secondary"><?= e($a['module']) ?></span></td>
                    <td><?= e($a['action']) ?></td>
                    <td class="text-muted"><?= e($a['utilisateur_nom'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
<?php page_end();
