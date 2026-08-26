<?php
declare(strict_types=1);

/** Signature - file de signature, appositions faites, verification d'un code. */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/signature.php';
require_login();
require_module('signature');

$verif = null; $codeSaisi = trim((string)($_GET['code'] ?? ''));
if ($codeSaisi !== '') {
    $verif = apposition_par_code($codeSaisi);
    audit('signature', 'verification_code', 'apposition', $verif['id'] ?? null, 'Code ' . $codeSaisi . ($verif ? ' reconnu' : ' inconnu'));
}
$specimen = specimen_actif(user_id());
$aSigner = documents_a_signer();
$faites = mes_appositions();

page_start('File de signature', 'signature');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">File de signature</h1>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(base_path('modules/signature/specimen.php')) ?>"><i class="bi bi-pen"></i> Mon spécimen</a>
</div>
<?php if (!$specimen): ?>
<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle"></i> Aucun spécimen actif : vous ne pouvez pas signer tant que votre spécimen et son acte de dépôt signé ne sont pas déposés. <a href="<?= e(base_path('modules/signature/specimen.php')) ?>">Déposer maintenant</a>.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold">Documents en attente de ma signature <span class="badge text-bg-secondary"><?= count($aSigner) ?></span></div>
            <?php if (!$aSigner): ?><div class="card-body text-muted small">Rien à signer pour l'instant. Les documents arrivent ici lorsqu'un module les met en attente de signature au titre de votre rôle<?= user_est_mandataire() ? ' ou de votre qualité de mandataire' : '' ?>.</div>
            <?php else: ?>
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead><tr><th>Document</th><th>Objet</th><th>Version</th><th>Signatures</th><th>Créé le</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($aSigner as $d): ?>
                <tr>
                    <td><?= e(DOCUMENTS_GENERES[$d['type']][0] ?? $d['type']) ?><br><small class="text-muted"><?= e($d['nom_genere'] ?? '') ?></small></td>
                    <td class="small"><?= e($d['module']) ?> · <?= e($d['objet_type']) ?> #<?= (int)$d['objet_id'] ?></td>
                    <td>v<?= (int)$d['version'] ?></td>
                    <td><?= (int)$d['nb_appositions'] ?> / <?= signatures_attendues($d['type']) ?></td>
                    <td class="small text-muted"><?= e(date_fr($d['created_at'])) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($d['fichier_id']): ?><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(base_path('pdf/serve.php?id=' . (int)$d['fichier_id'])) ?>"><i class="bi bi-file-earmark-pdf"></i></a><?php endif; ?>
                        <a class="btn btn-sm btn-primary <?= $specimen ? '' : 'disabled' ?>" href="<?= e(base_path('modules/signature/apposer.php?id=' . (int)$d['id'])) ?>">Signer</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-semibold">Mes appositions</div>
            <?php if (!$faites): ?><div class="card-body text-muted small">Aucune apposition.</div>
            <?php else: ?>
            <table class="table table-sm table-striped mb-0 small">
                <thead><tr><th>Horodatage</th><th>Document</th><th>Qualité</th><th>Code</th></tr></thead>
                <tbody>
                <?php foreach ($faites as $a): ?>
                <tr><td class="text-nowrap"><?= e(datetime_fr($a['horodatage'])) ?></td><td><?= e(DOCUMENTS_GENERES[$a['type']][0] ?? $a['type']) ?> · <?= e($a['objet_type']) ?> #<?= (int)$a['objet_id'] ?> v<?= (int)$a['version'] ?></td><td><?= e(QUALITES_SIGNATURE[$a['qualite']] ?? $a['qualite']) ?></td><td><a href="?code=<?= e($a['code_verification']) ?>" class="code-verification" style="font-size:.85rem"><?= e($a['code_verification']) ?></a></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Vérifier un code de signature</div>
            <div class="card-body">
                <form method="get" class="d-flex gap-2 mb-3">
                    <input name="code" class="form-control" placeholder="XXXX-XXXX-XX" value="<?= e($codeSaisi) ?>" maxlength="12" style="text-transform:uppercase">
                    <button class="btn btn-outline-primary">Vérifier</button>
                </form>
                <?php if ($codeSaisi !== '' && !$verif): ?>
                    <div class="alert alert-danger py-2 small mb-0">Code inconnu : aucune apposition ne porte ce code.</div>
                <?php elseif ($verif): ?>
                    <div class="alert alert-success py-2 small">Apposition authentique.</div>
                    <dl class="row small mb-0">
                        <dt class="col-5">Signataire</dt><dd class="col-7"><?= e($verif['signataire_nom']) ?></dd>
                        <dt class="col-5">Qualité</dt><dd class="col-7"><?= e(QUALITES_SIGNATURE[$verif['qualite']] ?? $verif['qualite']) ?></dd>
                        <dt class="col-5">Horodatage</dt><dd class="col-7"><?= e(datetime_fr($verif['horodatage'])) ?></dd>
                        <dt class="col-5">Document</dt><dd class="col-7"><?= e(DOCUMENTS_GENERES[$verif['type']][0] ?? $verif['type']) ?> · <?= e($verif['objet_type']) ?> #<?= (int)$verif['objet_id'] ?> v<?= (int)$verif['version'] ?> (<?= e($verif['document_statut']) ?>)</dd>
                        <dt class="col-5">Empreinte avant</dt><dd class="col-7" style="font-family:monospace;font-size:.7rem;word-break:break-all"><?= e($verif['empreinte_avant']) ?></dd>
                        <dt class="col-5">Empreinte après</dt><dd class="col-7" style="font-family:monospace;font-size:.7rem;word-break:break-all"><?= e($verif['empreinte_apres']) ?></dd>
                        <dt class="col-5">Fichier courant</dt><dd class="col-7"><?= hash_equals((string)$verif['empreinte_apres'], (string)$verif['empreinte_courante']) ? '<span class="text-success">identique à l\'empreinte après signature</span>' : '<span class="text-muted">modifié depuis (signature ultérieure ou nouvelle version)</span>' ?></dd>
                        <dt class="col-5">Appareil</dt><dd class="col-7 text-muted"><?= e($verif['ip'] ?? '') ?> · <?= e(mb_substr($verif['appareil'] ?? '', 0, 60)) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php page_end();
