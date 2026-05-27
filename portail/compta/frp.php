<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/DecaissementModel.php';
require_once __DIR__ . '/../models/NoteHonoraireModel.php';
require_once __DIR__ . '/../models/FicheReglementModel.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$action = (string)($_GET['action'] ?? 'list');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$impId  = isset($_GET['imputation_id']) ? (int)$_GET['imputation_id'] : 0;
$errors = [];

// =====================================================================
// POST : signer la FRP (Admin ou Coordinateur)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sign') {
    check_role(['administrateur', 'coordinateur']);
    try {
        verify_csrf();
        $frpId = (int)($_POST['frp_id'] ?? 0);
        $frp = FicheReglementModel::find($frpId);
        if (!$frp) {
            flash_set('danger', 'FRP introuvable.');
            redirect('/portail/compta/frp.php');
        }
        $role = user_role();
        $field = $role === 'administrateur' ? 'sig_administrateur' : 'sig_coordinateur';
        if ((int)$frp[$field] === 1) {
            flash_set('warning', 'Vous avez deja signe cette FRP.');
        } else {
            FicheReglementModel::updateSignatures($frpId, [$field => 1]);
            audit_log('frp_signe', "Signature FRP $frp[numero] ($role)", 'fiches_reglement', $frpId);

            // Verifie si toutes les sigs sont OK (le trigger fait le travail mais on relance la notif)
            $frpAfter = FicheReglementModel::find($frpId);
            if ($frpAfter && $frpAfter['date_cloture']) {
                notifier_frp_cloture($frpAfter);
                audit_log('frp_cloture', "FRP $frp[numero] cloturee", 'fiches_reglement', $frpId);
            }
            flash_set('success', 'FRP signee.');
        }
        redirect('/portail/compta/frp.php?action=view&id=' . $frpId);
    } catch (Throwable $e) {
        error_log('frp sign error: ' . $e->getMessage());
        flash_set('danger', 'Erreur technique.');
        redirect('/portail/compta/frp.php');
    }
}

function notifier_frp_cloture(array $frp): void
{
    $stmt = db()->query("SELECT email, nom_complet, role FROM users WHERE role IN ('administrateur','coordinateur') AND actif=1");
    while ($user = $stmt->fetch()) {
        $body = '<p>Bonjour ' . e($user['nom_complet']) . ',</p>'
              . '<p>La Fiche de Reglement <strong>' . e($frp['numero']) . '</strong> est cloturee.</p>'
              . '<ul><li>F01 : ' . e($frp['imputation_numero']) . '</li>'
              . '<li>Beneficiaire : ' . e($frp['prestataire']) . '</li>'
              . '<li>Montant net : ' . format_htg($frp['total_net_a_verser']) . '</li>'
              . '<li>Cloturee le : ' . e(date('d/m/Y H:i', strtotime($frp['date_cloture']))) . '</li></ul>'
              . '<p>Le dossier complet (5 pieces) est genere et archive.</p>';
        alerte_envoyer('frp_cloture', $user['email'],
            'FRP cloturee : ' . $frp['numero'], $body,
            ['type' => 'fiches_reglement', 'id' => (int)$frp['id']]);
    }
}

// =====================================================================
// ROUTAGE
// =====================================================================
$pageTitle  = 'FRP - Fiches de Reglement';
$activeMenu = 'compta';

if ($action === 'view_imp' && $impId > 0) {
    $frp = FicheReglementModel::findByImputation($impId);
    if (!$frp) {
        flash_set('info', 'FRP non encore creee (en attente signature prestataire).');
        redirect('/portail/compta/nh.php');
    }
    redirect('/portail/compta/frp.php?action=view&id=' . $frp['id']);
}

if ($action === 'view' && $id > 0) {
    $frp = FicheReglementModel::find($id);
    if (!$frp) { flash_set('danger', 'FRP introuvable.'); redirect('/portail/compta/frp.php'); }
    require __DIR__ . '/../includes/header.php';
    render_view($frp);
    require __DIR__ . '/../includes/footer.php';
} else {
    $list = FicheReglementModel::listAll();
    require __DIR__ . '/../includes/header.php';
    render_list($list);
    require __DIR__ . '/../includes/footer.php';
}

// =====================================================================
function render_list(array $list): void
{
?>
<h1 class="h3 mb-3"><i class="bi bi-clipboard2-check"></i> FRP - Fiches de Reglement</h1>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr>
                <th>FRP</th><th>F01</th><th>Beneficiaire</th><th>Date paiement</th>
                <th>Signatures</th><th>Cloture</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Aucune FRP.</td></tr>
            <?php endif; ?>
            <?php foreach ($list as $r):
                $nbSig = (int)$r['sig_prestataire'] + (int)$r['sig_administrateur'] + (int)$r['sig_coordinateur'];
            ?>
                <tr>
                    <td><a href="?action=view&id=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= e($r['numero']) ?></a>
                        <?php if ((int)$r['is_retroactif']===1): ?><span class="badge bg-warning">Retroactif</span><?php endif; ?>
                    </td>
                    <td><small><?= e($r['f01_numero']) ?></small></td>
                    <td><?= e($r['prestataire']) ?></td>
                    <td><?= e(date('d/m/Y', strtotime($r['date_paiement']))) ?></td>
                    <td>
                        <span class="badge bg-<?= (int)$r['sig_prestataire']===1?'success':'secondary' ?>" title="Prestataire">P</span>
                        <span class="badge bg-<?= (int)$r['sig_administrateur']===1?'success':'secondary' ?>" title="Administrateur">A</span>
                        <span class="badge bg-<?= (int)$r['sig_coordinateur']===1?'success':'secondary' ?>" title="Coordinateur">C</span>
                        <small class="text-muted ms-1"><?= $nbSig ?>/3</small>
                    </td>
                    <td>
                        <?php if ($r['date_cloture']): ?>
                            <span class="badge bg-success">Cloturee</span>
                        <?php else: ?>
                            <span class="badge bg-warning">En cours</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="?action=view&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php
}

function render_view(array $frp): void
{
    $role = user_role();
    $isClosed = !empty($frp['date_cloture']);
    $sigP = (int)$frp['sig_prestataire'] === 1;
    $sigA = (int)$frp['sig_administrateur'] === 1;
    $sigC = (int)$frp['sig_coordinateur'] === 1;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">FRP <?= e($frp['numero']) ?>
            <?php if ($isClosed): ?>
                <span class="badge bg-success">Cloturee</span>
            <?php else: ?>
                <span class="badge bg-warning">En cours</span>
            <?php endif; ?>
            <?php if ((int)$frp['is_cps01']===1): ?><span class="badge bg-info">CPS-01</span><?php endif; ?>
            <?php if ((int)$frp['is_retroactif']===1): ?><span class="badge bg-warning">Retroactif</span><?php endif; ?>
        </h1>
        <small class="text-muted">F01 : <a href="/portail/compta/f01.php?action=view&id=<?= (int)$frp['imputation_id'] ?>"><?= e($frp['imputation_numero']) ?></a></small>
    </div>
    <a href="/portail/compta/frp.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Reglement</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Beneficiaire</dt><dd class="col-7"><?= e($frp['prestataire']) ?></dd>
                    <dt class="col-5">Contrat</dt><dd class="col-7"><?= e($frp['contrat_numero']) ?> (<?= e($frp['type_contrat']) ?>)</dd>
                    <dt class="col-5">Date paiement</dt><dd class="col-7"><?= e(date('d/m/Y', strtotime($frp['date_paiement']))) ?></dd>
                    <dt class="col-5">Mode paiement F02</dt><dd class="col-7"><span class="badge bg-light text-dark"><?= e($frp['mode_paiement']) ?></span></dd>
                    <?php if ($frp['mode_paiement']==='cheque'): ?>
                        <dt class="col-5">N° cheque<?= (int)$frp['is_cps01']===1?' (honoraires)':'' ?></dt>
                        <dd class="col-7 font-monospace"><?= e($frp['f02_numero_cheque']) ?></dd>
                        <?php if ($frp['numero_cheque_allocation']): ?>
                        <dt class="col-5">N° cheque (allocation)</dt><dd class="col-7 font-monospace"><?= e($frp['numero_cheque_allocation']) ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                    <dt class="col-5">Evaluation livrables</dt>
                    <dd class="col-7"><span class="badge bg-<?= ['conformes'=>'success','partiels'=>'warning','non_conformes'=>'danger'][$frp['evaluation_livrables']] ?? 'secondary' ?>"><?= e($frp['evaluation_livrables']) ?></span></dd>
                    <dt class="col-5"><strong>Total net verse</strong></dt>
                    <dd class="col-7 fw-bold font-monospace"><?= format_htg($frp['total_net_a_verser']) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($isClosed): ?>
        <div class="alert alert-success mt-3">
            <i class="bi bi-check2-circle"></i> FRP cloturee le <strong><?= e(date('d/m/Y H:i', strtotime($frp['date_cloture']))) ?></strong>.
            Le statut de l'imputation est passe a <strong>valide</strong>.
        </div>
        <a href="/portail/pdf/render.php?type=dossier&id=<?= (int)$frp['id'] ?>" class="btn btn-primary" target="_blank">
            <i class="bi bi-file-earmark-pdf"></i> Dossier complet (5 pieces)
        </a>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Signatures</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <i class="bi bi-<?= $sigP?'check-circle-fill text-success':'circle text-muted' ?>"></i>
                        <strong>Prestataire</strong>
                    </div>
                    <?php if ($sigP): ?><small class="text-success">Signe</small><?php else: ?><small class="text-muted">Via lien tokenise</small><?php endif; ?>
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <i class="bi bi-<?= $sigA?'check-circle-fill text-success':'circle text-muted' ?>"></i>
                        <strong>Administrateur</strong>
                    </div>
                    <?php if (!$sigA && $role === 'administrateur'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Confirmer votre signature ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sign">
                            <input type="hidden" name="frp_id" value="<?= (int)$frp['id'] ?>">
                            <button class="btn btn-sm btn-primary">Signer</button>
                        </form>
                    <?php elseif ($sigA): ?>
                        <small class="text-success">Signe</small>
                    <?php else: ?>
                        <small class="text-muted">En attente</small>
                    <?php endif; ?>
                </div>
                <div class="d-flex justify-content-between mb-0">
                    <div>
                        <i class="bi bi-<?= $sigC?'check-circle-fill text-success':'circle text-muted' ?>"></i>
                        <strong>Coordinateur</strong>
                    </div>
                    <?php if (!$sigC && $role === 'coordinateur'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Confirmer votre signature ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sign">
                            <input type="hidden" name="frp_id" value="<?= (int)$frp['id'] ?>">
                            <button class="btn btn-sm btn-primary">Signer</button>
                        </form>
                    <?php elseif ($sigC): ?>
                        <small class="text-success">Signe</small>
                    <?php else: ?>
                        <small class="text-muted">En attente</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$isClosed): ?>
            <div class="card-footer bg-white small text-muted">
                La 3e signature declenchera automatiquement la cloture (trigger MySQL).
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
}
