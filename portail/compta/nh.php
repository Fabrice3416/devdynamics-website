<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/NoteHonoraireModel.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$pageTitle  = 'NH - Notes d\'Honoraires';
$activeMenu = 'compta';

if ($id > 0) {
    $nh = NoteHonoraireModel::find($id);
    if (!$nh) { flash_set('danger', 'NH introuvable.'); redirect('/portail/compta/nh.php'); }
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">NH <?= e($nh['numero']) ?></h1>
        <a href="/portail/compta/nh.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">F01 source</dt>
                <dd class="col-sm-8"><a href="/portail/compta/f01.php?action=view&id=<?= (int)$nh['imputation_id'] ?>"><?= e($nh['imputation_numero']) ?></a></dd>
                <dt class="col-sm-4">Beneficiaire</dt><dd class="col-sm-8"><?= e($nh['prestataire']) ?></dd>
                <dt class="col-sm-4">Contrat</dt><dd class="col-sm-8"><?= e($nh['contrat_numero']) ?> (<?= e($nh['type_contrat']) ?>)</dd>
                <dt class="col-sm-4">Description prestation</dt><dd class="col-sm-8"><?= nl2br(e($nh['description_prestation'])) ?></dd>
                <dt class="col-sm-4">Montant brut</dt><dd class="col-sm-8 font-monospace"><?= format_htg($nh['montant_brut']) ?></dd>
                <dt class="col-sm-4">Mode paiement souhaite</dt><dd class="col-sm-8"><?= e($nh['mode_paiement']) ?></dd>
                <?php if ($nh['coordonnees_bancaires']): ?>
                <dt class="col-sm-4">Coordonnees bancaires</dt><dd class="col-sm-8"><?= nl2br(e((string)$nh['coordonnees_bancaires'])) ?></dd>
                <?php endif; ?>
                <dt class="col-sm-4">Soumise le</dt><dd class="col-sm-8"><?= $nh['date_soumission'] ? e(date('d/m/Y H:i', strtotime($nh['date_soumission']))) : '-' ?></dd>
                <?php if ($nh['sig_presta_scan']): ?>
                <dt class="col-sm-4">Signature prestataire</dt>
                <dd class="col-sm-8">
                    <img src="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$nh['sig_presta_scan'])) ?>&type=sig"
                         style="max-height:60px;background:#f8f9fa;padding:4px;" alt="Sig">
                </dd>
                <?php endif; ?>
            </dl>
        </div>
        <div class="card-footer bg-white">
            <a href="/portail/compta/frp.php?action=view_imp&imputation_id=<?= (int)$nh['imputation_id'] ?>" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle"></i> Voir FRP associee
            </a>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
} else {
    $list = NoteHonoraireModel::listSubmitted();
    require __DIR__ . '/../includes/header.php';
    ?>
    <h1 class="h3 mb-3"><i class="bi bi-receipt"></i> NH - Notes d'Honoraires soumises</h1>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr>
                    <th>NH</th><th>F01</th><th>Beneficiaire</th><th>Soumise le</th>
                    <th class="text-end">Montant</th><th>Mode</th><th>FRP</th>
                </tr></thead>
                <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucune NH soumise.</td></tr>
                <?php endif; ?>
                <?php foreach ($list as $r): ?>
                    <tr>
                        <td><a href="?id=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= e($r['numero']) ?></a></td>
                        <td><small><?= e($r['f01_numero']) ?></small></td>
                        <td><?= e($r['prestataire']) ?></td>
                        <td><?= $r['date_soumission'] ? e(date('d/m/Y H:i', strtotime($r['date_soumission']))) : '-' ?></td>
                        <td class="text-end font-monospace"><?= format_htg($r['montant_brut']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= e($r['mode_paiement']) ?></span></td>
                        <td>
                            <?php if ($r['frp_id']): ?>
                                <a href="/portail/compta/frp.php?action=view&id=<?= (int)$r['frp_id'] ?>" class="badge bg-success text-decoration-none">FRP creee</a>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">En attente FRP</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
}
