<?php
declare(strict_types=1);

/** Signature - depot, consultation et revocation du specimen du titulaire connecte. */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/signature.php';
require_login();  // Le specimen est partage entre projets : une personne garde une identite
require_module('signature');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'deposer') {
        $png = null;
        if (!empty($_POST['pad_png']) && preg_match('#^data:image/jpeg;base64,(.+)$#', (string)$_POST['pad_png'], $m)) {
            $bin = base64_decode($m[1], true);
            if ($bin !== false && (new finfo(FILEINFO_MIME_TYPE))->buffer($bin) === 'image/jpeg') {
                $png = $bin;
            }
        }
        if ($png === null && !empty($_FILES['image']['name'])) {
            $f = $_FILES['image'];
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if ($f['error'] === UPLOAD_ERR_OK && in_array($ext, ['png', 'jpg', 'jpeg'], true) && is_uploaded_file($f['tmp_name'])) {
                $png = file_get_contents($f['tmp_name']) ?: null;
            }
        }
        if ($png === null) {
            $erreur = 'Tracez votre signature dans le cadre ou téléversez une image PNG ou JPEG.';
        } elseif (empty($_FILES['acte']['name'])) {
            $erreur = 'L\'acte de dépôt signé à la main et scanné est obligatoire.';
        } else {
            $r = deposer_specimen(user_id(), $png, $_FILES['acte']);
            if ($r['success']) {
                flash_set('success', 'Spécimen déposé. Il est conservé chiffré et n\'est apposable que par vous, après réauthentification.');
                redirect(base_path('modules/signature/specimen.php'));
            }
            $erreur = $r['error'];
        }
    } elseif ($action === 'revoquer') {
        $motif = trim((string)($_POST['motif'] ?? ''));
        if ($motif === '') {
            $erreur = 'Indiquez le motif de la révocation.';
        } else {
            revoquer_specimen(user_id(), $motif);
            flash_set('success', 'Spécimen révoqué. Les appositions déjà faites restent valables.');
            redirect(base_path('modules/signature/specimen.php'));
        }
    }
}

$specimen = specimen_actif(user_id());
$historique = specimens_historique(user_id());

page_start('Mon spécimen de signature', 'signature');
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold">Spécimen actif</div>
            <div class="card-body">
                <?php if ($specimen): ?>
                    <div class="d-flex gap-3 align-items-start">
                        <img src="<?= e(base_path('pdf/serve.php?id=' . (int)$specimen['image_fichier_id'])) ?>" alt="Spécimen" style="max-height:90px;max-width:260px;border:1px solid var(--bsl-filet);background:#fff;padding:4px">
                        <dl class="small mb-0">
                            <dt>Déposé le</dt><dd><?= e(date_fr($specimen['date_depot'])) ?></dd>
                            <dt>Acte de dépôt</dt><dd><a href="<?= e(base_path('pdf/serve.php?id=' . (int)$specimen['acte_depot_fichier_id'])) ?>" target="_blank"><?= e($specimen['acte_nom']) ?></a></dd>
                            <dt>Empreinte de l'image</dt><dd style="font-family:monospace;font-size:.7rem;word-break:break-all"><?= e($specimen['image_empreinte']) ?></dd>
                        </dl>
                    </div>
                    <hr>
                    <form method="post" data-confirm="Révoquer ce spécimen ? Vous ne pourrez plus signer avant d'en déposer un nouveau.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="revoquer">
                        <div class="input-group input-group-sm">
                            <input name="motif" class="form-control" placeholder="Motif de révocation" required>
                            <button class="btn btn-outline-danger">Révoquer</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-0">Aucun spécimen actif. Déposez-en un ci-contre : sans spécimen accompagné de son acte de dépôt, aucune apposition n'est possible.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php if (count($historique) > ($specimen ? 1 : 0)): ?>
        <div class="card">
            <div class="card-header bg-white fw-semibold">Spécimens révoqués</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <thead><tr><th>Déposé le</th><th>Révoqué le</th><th>Motif</th></tr></thead>
                <tbody><?php foreach ($historique as $h): if (!$h['date_revocation']) continue; ?><tr><td><?= e(date_fr($h['date_depot'])) ?></td><td><?= e(date_fr($h['date_revocation'])) ?></td><td><?= e($h['motif_revocation'] ?? '') ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Déposer un spécimen</div>
            <div class="card-body">
                <?php if ($erreur): ?><div class="alert alert-danger py-2"><?= e($erreur) ?></div><?php endif; ?>
                <?php if ($specimen): ?>
                    <p class="text-muted small mb-0">Un seul spécimen est actif à la fois. Révoquez le spécimen courant pour en déposer un nouveau.</p>
                <?php else: ?>
                <ol class="small text-muted mb-3">
                    <li><a href="<?= e(base_path('modules/signature/acte.php')) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Imprimer l'acte de dépôt</a>, le signer à la main, le faire viser par le Coordinateur, le scanner (PDF ou image).</li>
                    <li>Tracer la signature dans le cadre ci-dessous (ou téléverser une image PNG ou JPEG sur fond blanc).</li>
                    <li>Téléverser l'acte scanné et valider.</li>
                </ol>
                <form method="post" enctype="multipart/form-data" id="form-specimen">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deposer">
                    <input type="hidden" name="pad_png" id="pad_png">
                    <label class="form-label small">Signature (tracée)</label>
                    <div class="signature-pad-box mb-1"><canvas id="pad" width="520" height="160" style="width:100%;height:160px;touch-action:none"></canvas></div>
                    <div class="d-flex justify-content-between mb-3"><button type="button" class="btn btn-sm btn-link p-0" id="pad-clear">Effacer</button><small class="text-muted">ou</small></div>
                    <div class="mb-3"><label class="form-label small">Image de la signature (PNG ou JPEG)</label><input type="file" name="image" accept="image/png,image/jpeg" class="form-control form-control-sm"></div>
                    <div class="mb-3"><label class="form-label small">Acte de dépôt signé et scanné <span class="text-danger">*</span></label><input type="file" name="acte" accept="application/pdf,image/png,image/jpeg" class="form-control form-control-sm" required></div>
                    <button class="btn btn-primary">Déposer le spécimen</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if (!$specimen): ?>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
(function () {
  var canvas = document.getElementById('pad'); if (!canvas || !window.SignaturePad) return;
  var pad = new SignaturePad(canvas, { backgroundColor: 'rgb(255,255,255)', penColor: '#1a1a55', minWidth: 0.8, maxWidth: 2.2 });
  function resize() { var r = Math.max(window.devicePixelRatio || 1, 1); var d = pad.toData(); canvas.width = canvas.offsetWidth * r; canvas.height = canvas.offsetHeight * r; canvas.getContext('2d').scale(r, r); pad.clear(); pad.fromData(d); }
  window.addEventListener('resize', resize); resize();
  document.getElementById('pad-clear').addEventListener('click', function () { pad.clear(); });
  document.getElementById('form-specimen').addEventListener('submit', function () { if (!pad.isEmpty()) { document.getElementById('pad_png').value = pad.toDataURL('image/jpeg', 0.92); } });
})();
</script>
<?php endif; ?>
<?php page_end();
