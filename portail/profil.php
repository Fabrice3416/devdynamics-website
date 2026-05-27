<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/alerts.php';

require_login();

$uid    = (int)user_id();
$errors = [];
$ok     = null;

// Recupere les infos utilisateur courantes
$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['new_password_confirm'] ?? '');

            if (!password_verify($current, $user['mot_de_passe'])) {
                $errors[] = 'Mot de passe actuel incorrect.';
            } elseif (strlen($new) < 10) {
                $errors[] = 'Le nouveau mot de passe doit comporter au moins 10 caracteres.';
            } elseif ($new !== $confirm) {
                $errors[] = 'Les deux nouveaux mots de passe ne correspondent pas.';
            } else {
                $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
                $stmt = db()->prepare('UPDATE users SET mot_de_passe = ? WHERE id = ?');
                $stmt->execute([$hash, $uid]);
                audit_log('login', 'Mot de passe modifie', 'users', $uid);
                $ok = 'Mot de passe modifie.';
            }
        }

        elseif ($action === 'upload_signature') {
            // Deux modes : upload fichier OU pad signature_pad.js
            $padData = (string)($_POST['signature_padpng'] ?? '');

            // Archive l'ancienne signature
            if (!empty($user['signature_image'])) {
                $oldAbs = storage_absolute_path($user['signature_image']);
                if (is_file($oldAbs)) {
                    $archive = preg_replace('/(\.png)$/i', '_archive_' . time() . '$1', $oldAbs);
                    @rename($oldAbs, $archive);
                }
            }

            $destDir = __DIR__ . '/storage/signatures/users';
            $destFile = $destDir . '/user_' . $uid . '.png';
            $relPath  = null;

            if ($padData !== '') {
                $saved = save_base64_png($padData, $destFile);
                if ($saved === null) {
                    $errors[] = 'Signature dessinee invalide.';
                } else {
                    $relPath = storage_relative_path($saved);
                }
            } elseif (!empty($_FILES['signature_file']['name'])) {
                $up = handle_upload($_FILES['signature_file'], $destDir, ALLOWED_IMAGE_ONLY);
                if (!$up['success']) {
                    $errors[] = 'Upload echoue : ' . ($up['error'] ?? 'inconnu');
                } else {
                    // Renomme l'upload en user_X.png pour standardiser
                    if (!@rename($up['path'], $destFile)) {
                        $errors[] = 'Impossible de finaliser l upload.';
                    } else {
                        @chmod($destFile, 0644);
                        $relPath = storage_relative_path($destFile);
                    }
                }
            } else {
                $errors[] = 'Aucune signature fournie (ni upload, ni dessin).';
            }

            if (!$errors && $relPath !== null) {
                $stmt = db()->prepare('UPDATE users SET signature_image = ? WHERE id = ?');
                $stmt->execute([$relPath, $uid]);
                audit_log('upload_fichier', 'Mise a jour signature utilisateur', 'users', $uid);
                $ok = 'Signature enregistree.';

                // Refresh
                $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$uid]);
                $user = $stmt->fetch();
            }
        }
    } catch (Throwable $e) {
        error_log('profil.php error: ' . $e->getMessage());
        $errors[] = 'Erreur technique.';
    }
}

$pageTitle  = 'Mon profil';
$activeMenu = 'profil';
require __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4"><i class="bi bi-person-circle"></i> Mon profil</h1>

<?php if ($ok): ?>
    <div class="alert alert-success"><?= e($ok) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-4">
    <!-- Infos -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Informations</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Nom</dt><dd class="col-7"><?= e($user['nom_complet']) ?></dd>
                    <dt class="col-5">Email</dt><dd class="col-7"><?= e($user['email']) ?></dd>
                    <dt class="col-5">Role</dt><dd class="col-7"><?= e(ucfirst($user['role'])) ?></dd>
                    <dt class="col-5">Cree le</dt><dd class="col-7"><?= e(date('d/m/Y', strtotime($user['created_at']))) ?></dd>
                    <dt class="col-5">Derniere connexion</dt>
                    <dd class="col-7"><?= $user['derniere_connexion'] ? e(date('d/m/Y H:i', strtotime($user['derniere_connexion']))) : '-' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Changer mot de passe -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Changer mon mot de passe</strong></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">Mot de passe actuel</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nouveau (min 10 caracteres)</label>
                        <input type="password" name="new_password" class="form-control" required minlength="10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmer</label>
                        <input type="password" name="new_password_confirm" class="form-control" required minlength="10">
                    </div>
                    <button class="btn btn-primary w-100">Mettre a jour</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Signature manuscrite -->
    <?php if ($user['role'] !== 'comptable'): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Ma signature manuscrite</strong></div>
            <div class="card-body">
                <?php if (!empty($user['signature_image']) && is_file(storage_absolute_path($user['signature_image']))): ?>
                    <p class="small text-muted mb-2">Signature actuelle :</p>
                    <img src="/portail/pdf/serve.php?path=<?= urlencode($user['signature_image']) ?>&type=sig"
                         alt="Signature" style="max-height: 100px; max-width: 100%; background: #f8f9fa; padding: 8px;">
                    <hr>
                <?php else: ?>
                    <p class="text-muted small">Aucune signature pour le moment. Sans signature, les PDF afficheront un bloc texte
                       "[Signe electroniquement par <?= e($user['nom_complet']) ?>]".</p>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_signature">

                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-upload">Upload PNG</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-draw">Dessiner</a></li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab-upload">
                            <p class="small text-muted">Format recommande : PNG fond transparent, 400 x 150 px min, max 5 Mo.</p>
                            <input type="file" name="signature_file" accept="image/png,image/jpeg" class="form-control mb-3">
                        </div>
                        <div class="tab-pane fade" id="tab-draw">
                            <p class="small text-muted">Dessinez avec la souris ou le doigt.</p>
                            <canvas id="signaturePad" width="380" height="150" style="border:1px solid #ddd;background:#fff;width:100%;"></canvas>
                            <input type="hidden" name="signature_padpng" id="signature_padpng">
                            <button type="button" id="clearPad" class="btn btn-sm btn-outline-secondary mt-2">Effacer</button>
                        </div>
                    </div>

                    <hr>
                    <button class="btn btn-primary w-100" id="saveSig">Enregistrer la signature</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/signature_pad/4.1.7/signature_pad.umd.min.js"></script>
<script>
(function() {
    const canvas = document.getElementById('signaturePad');
    if (!canvas) return;
    const pad = new SignaturePad(canvas, { backgroundColor: 'rgba(255,255,255,0)' });
    const clearBtn = document.getElementById('clearPad');
    const hidden = document.getElementById('signature_padpng');
    const form = document.getElementById('saveSig').closest('form');

    clearBtn.addEventListener('click', () => pad.clear());

    form.addEventListener('submit', () => {
        // Si l'utilisateur a dessine, on envoie le data URL
        if (!pad.isEmpty()) {
            hidden.value = pad.toDataURL('image/png');
        }
    });

    // Resize responsive
    function resize() {
        const ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
    }
    window.addEventListener('resize', resize);
    resize();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
