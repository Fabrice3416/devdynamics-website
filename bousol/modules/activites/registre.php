<?php
declare(strict_types=1);

/**
 * Activites - registre des versions, anomalies et enquete d'adoption (CDC 3.6, 3.5).
 *
 * Le registre couvre l'ensemble du cycle de vie de l'application, de la version de
 * test interne jusqu'aux correctifs de la phase 2. La verification Google y figure
 * parce qu'elle conditionne la publication.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/activites.php';
require_role(['coordinateur', 'raf']);
require_module('activites');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'version') {
        $res = version_application_creer($_POST);
    } elseif ($action === 'diffuser') {
        $res = version_application_diffuser((int)($_POST['version_id'] ?? 0));
    } elseif ($action === 'google') {
        $res = version_application_verification((int)($_POST['version_id'] ?? 0), (string)($_POST['etat'] ?? ''));
    } elseif ($action === 'anomalie') {
        $res = anomalie_declarer($_POST);
    } elseif ($action === 'accuser') {
        $res = anomalie_accuser((int)($_POST['anomalie_id'] ?? 0));
    } elseif ($action === 'resoudre') {
        $res = anomalie_resoudre((int)($_POST['anomalie_id'] ?? 0), (string)($_POST['reponse'] ?? ''),
            (int)($_POST['version_id'] ?? 0) ?: null);
    } elseif ($action === 'enquete') {
        $res = enquete_saisir((int)($_POST['organisation_id'] ?? 0), !empty($_POST['usage_actif']),
            (string)($_POST['observations'] ?? ''));
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/activites/registre.php'));
    }
}

$versions = versions_application();
$listeAnomalies = anomalies();
$sansAccuse = anomalies_sans_accuse();
$ad = adoption();
$organisations = db()->query("SELECT id, nom FROM tiers WHERE type = 'organisation' AND actif = 1 ORDER BY nom")->fetchAll();

$ongletActif = 'registre';
page_start('Versions et anomalies', 'activites');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-3">Registre des versions et des anomalies</h1>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($sansAccuse): ?>
<div class="alert alert-warning py-2"><i class="bi bi-clock-history"></i>
    <strong><?= count($sansAccuse) ?> signalement(s) sans accusé de réception</strong> au-delà du délai paramétré.
    L'engagement de support est de <?= e(param('delai_accuse_phase2_heures', '48')) ?> heures ouvrables.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Versions au registre</div>
        <div class="valeur fs-5"><?= count($versions) ?></div>
        <small class="text-muted">du test interne aux correctifs</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Anomalies ouvertes</div>
        <div class="valeur fs-5"><?= count(array_filter($listeAnomalies, fn($a) => $a['date_resolution'] === null)) ?></div>
        <small class="text-muted">un signalement non corrigé est l'état normal</small>
    </div></div></div>
    <div class="col-12 col-lg-6"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Adoption, enquête de phase 2</div>
        <div class="valeur fs-5"><?= (int)$ad['actives'] ?> / <?= (int)$ad['enquetees'] ?></div>
        <small class="text-muted">organisations en usage actif · l'indicateur de l'objectif général tombe après la clôture</small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-tags"></i> Versions de l'application</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <?php foreach ($versions as $v): ?>
                <tr>
                    <td class="small"><b><?= e($v['numero']) ?></b> <?= e(NATURES_VERSION_APP[$v['nature']]) ?>
                        <br><span class="text-muted"><?= e(date_fr($v['date'])) ?>
                        <?php if ($v['modules_touches']): ?> · <?= e($v['modules_touches']) ?><?php endif; ?>
                        <?php if ($v['canal']): ?> · <?= e($v['canal']) ?><?php endif; ?></span></td>
                    <td class="small">
                        <span class="badge text-bg-light border"><?= e(ETATS_DIFFUSION[$v['etat_diffusion']]) ?></span>
                        <?php if ($v['verification_google']): ?>
                        <br><span class="text-muted">Google : <?= e(VERIFICATIONS_GOOGLE[$v['verification_google']]) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end" style="width:11rem">
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="google">
                            <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                            <select class="form-select form-select-sm" name="etat" onchange="this.form.submit()">
                                <?php foreach (VERIFICATIONS_GOOGLE as $k => $lib): ?>
                                <option value="<?= e($k) ?>" <?= $v['verification_google'] === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php if ($v['etat_diffusion'] !== 'diffusee'): ?>
                        <form method="post" class="d-inline mt-1"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="diffuser">
                            <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn-sm btn-link p-0">diffuser</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$versions): ?><tr><td class="text-muted p-3">Aucune version au registre.</td></tr><?php endif; ?>
            </table>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="version">
                    <div class="col-4">
                        <label class="form-label small mb-1">Numéro</label>
                        <input class="form-control form-control-sm" name="numero" maxlength="20" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Date</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Nature</label>
                        <select class="form-select form-select-sm" name="nature">
                            <?php foreach (NATURES_VERSION_APP as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Modules touchés</label>
                        <?php foreach (MODULES_APPLICATION as $k => $lib): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="modules_touches[]" value="<?= e($k) ?>" id="m<?= e($k) ?>">
                            <label class="form-check-label small" for="m<?= e($k) ?>"><?= e($k) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Canal de diffusion</label>
                        <input class="form-control form-control-sm" name="canal" maxlength="60">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Au titre de l'activité</label>
                        <input class="form-control form-control-sm" name="activite_code" maxlength="10" placeholder="1.1.1">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Inscrire la version</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bug"></i> Anomalies et conseils d'usage</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <?php foreach ($listeAnomalies as $a): ?>
                <tr>
                    <td class="small"><?= e(mb_substr($a['description'], 0, 90)) ?>
                        <br><span class="text-muted"><?= e(date_fr($a['date'])) ?>
                        · <?= e(GRAVITES_ANOMALIE[$a['gravite']]) ?>
                        · <?= e(NATURES_ANOMALIE[$a['nature']]) ?>
                        <?php if ($a['declarant']): ?> · <?= e($a['declarant']) ?><?php endif; ?></span>
                        <?php if ($a['reponse']): ?><br><span class="text-success"><?= e(mb_substr($a['reponse'], 0, 70)) ?></span><?php endif; ?>
                    </td>
                    <td class="text-end small" style="width:10rem">
                        <?php if ($a['date_resolution']): ?>
                            <span class="text-success">résolue le <?= e(date_fr($a['date_resolution'])) ?></span>
                            <?php if ($a['version_numero']): ?><br><span class="text-muted">v<?= e($a['version_numero']) ?></span><?php endif; ?>
                        <?php elseif ($a['date_accuse']): ?>
                            <span class="text-muted">accusée le <?= e(date_fr($a['date_accuse'])) ?></span>
                            <form method="post" class="mt-1"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="resoudre">
                                <input type="hidden" name="anomalie_id" value="<?= (int)$a['id'] ?>">
                                <input class="form-control form-control-sm mb-1" name="reponse" placeholder="réponse" required>
                                <select class="form-select form-select-sm mb-1" name="version_id">
                                    <option value="">correctif — facultatif</option>
                                    <?php foreach ($versions as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>"><?= e($v['numero']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-link p-0">résoudre</button>
                            </form>
                        <?php else: ?>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="accuser">
                                <input type="hidden" name="anomalie_id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm btn-link p-0">accuser réception</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$listeAnomalies): ?><tr><td class="text-muted p-3">Aucun signalement.</td></tr><?php endif; ?>
            </table>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="anomalie">
                    <div class="col-12">
                        <label class="form-label small mb-1">Description</label>
                        <textarea class="form-control form-control-sm" name="description" rows="2" required></textarea>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Gravité</label>
                        <select class="form-select form-select-sm" name="gravite">
                            <?php foreach (GRAVITES_ANOMALIE as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Nature</label>
                        <select class="form-select form-select-sm" name="nature">
                            <?php foreach (NATURES_ANOMALIE as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Canal</label>
                        <input class="form-control form-control-sm" name="canal" maxlength="60">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Signalée par</label>
                        <select class="form-select form-select-sm" name="declarant_id">
                            <option value="">— interne</option>
                            <?php foreach ($organisations as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e($o['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Enregistrer le signalement</button></div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-data"></i> Enquête d'adoption</div>
            <div class="card-body">
                <p class="small text-muted">Neuf des douze organisations doivent utiliser activement l'application
                    trois mois après leur formation. Cet indicateur tombe au mois 10, après la clôture : il relève
                    de la phase de suivi, et le rapport final le mentionnera comme tel.</p>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enquete">
                    <div class="col-7">
                        <label class="form-label small mb-1">Organisation</label>
                        <select class="form-select form-select-sm" name="organisation_id" required>
                            <option value="">—</option>
                            <?php foreach ($organisations as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e($o['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-5 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="usage_actif" id="ua" value="1">
                            <label class="form-check-label small" for="ua">Usage actif</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Observations</label>
                        <input class="form-control form-control-sm" name="observations">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Enregistrer</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php page_end();
