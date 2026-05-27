<?php
declare(strict_types=1);

/**
 * Endpoint public tokenise pour la signature prestataire de la FRP.
 * Cree la fiche_reglement si elle n'existe pas, puis met sig_prestataire = 1.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../services/TokenService.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/NoteHonoraireModel.php';
require_once __DIR__ . '/../models/FicheReglementModel.php';
require_once __DIR__ . '/../models/DecaissementModel.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('PORTAIL_FRP_TOKEN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/portail/api/',
        'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$token = trim((string)($_GET['t'] ?? ''));
$errors = [];
$success = false;

if ($token === '' || strlen($token) !== 64) {
    render_error('Lien invalide.', 'Verifiez le lien recu par email.');
    exit;
}

$tk = TokenService::validate($token, 'signature_frp', $ip);
if (!$tk) {
    render_error('Lien expire ou deja utilise.',
        'Contactez l Administrateur DEVDYNAMICS pour obtenir un nouveau lien.');
    exit;
}

$imp = ImputationModel::find((int)$tk['imputation_id']);
$nh  = NoteHonoraireModel::findByImputation((int)$tk['imputation_id']);
$f02 = DecaissementModel::findByImputation((int)$tk['imputation_id']);

if (!$imp || !$nh || !$f02) {
    render_error('Dossier incomplet.', 'Contactez l Administrateur.');
    exit;
}

// =====================================================================
// POST : signature prestataire
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$submittedCsrf)) {
        $errors[] = 'Jeton CSRF invalide.';
    } else {
        $eval = (string)($_POST['evaluation_livrables'] ?? 'conformes');
        $padData = (string)($_POST['signature_padpng'] ?? '');

        if (!in_whitelist($eval, ['conformes','partiels','non_conformes'])) {
            $errors[] = 'Evaluation invalide.';
        }
        if ($padData === '') $errors[] = 'Signature obligatoire.';

        if (!$errors) {
            $sigPath = __DIR__ . '/../storage/signatures/presta/frp_' . (int)$tk['id'] . '.png';
            $savedPath = save_base64_png($padData, $sigPath);
            if ($savedPath === null) {
                $errors[] = 'Signature invalide.';
            }

            if (!$errors) {
                $sigRel = 'storage/signatures/presta/frp_' . (int)$tk['id'] . '.png';

                db()->beginTransaction();
                try {
                    // Cree la FRP si elle n'existe pas
                    $frp = FicheReglementModel::findByImputation((int)$imp['id']);
                    if (!$frp) {
                        $numero = generate_numero_mensuel(
                            'FRP',
                            // sequence : compte le nb de FRP deja existantes pour ce mois
                            (int)(db()->query("SELECT COUNT(*)+1 FROM fiches_reglement WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn()),
                            (int)date('m', strtotime($imp['date_depense']))
                        );
                        $frpId = FicheReglementModel::create([
                            'numero' => $numero,
                            'imputation_id' => (int)$imp['id'],
                            'date_paiement' => date('Y-m-d'),
                            'numero_cheque' => $f02['numero_cheque'] ?: null,
                            'evaluation_livrables' => $eval,
                        ]);
                    } else {
                        $frpId = (int)$frp['id'];
                    }

                    FicheReglementModel::updateSignatures($frpId, ['sig_prestataire' => 1]);
                    TokenService::markUsed((int)$tk['id'], $sigRel);

                    // Notifier l'admin pour signer a son tour
                    $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role='administrateur' AND actif=1");
                    while ($admin = $stmt->fetch()) {
                        $body = '<p>Bonjour ' . e($admin['nom_complet']) . ',</p>'
                              . '<p>Le prestataire <strong>' . e($imp['prestataire']) . '</strong> a signe la FRP.</p>'
                              . '<p>Connectez-vous pour signer a votre tour :</p>'
                              . '<p><a href="https://dev-dynamics.org/portail/compta/frp.php?action=view&id=' . $frpId . '">Acceder a la FRP</a></p>';
                        alerte_envoyer('frp_cloture', $admin['email'],
                            'FRP a signer (prestataire deja signe) : ' . $imp['numero'], $body,
                            ['type' => 'fiches_reglement', 'id' => $frpId]);
                    }

                    db()->commit();
                    $success = true;
                } catch (Throwable $e) {
                    db()->rollBack();
                    error_log('FRP token submission failed: ' . $e->getMessage());
                    $errors[] = 'Erreur technique.';
                }
            }
        }
    }
}

if ($success) { render_success($imp); exit; }

render_form($imp, $nh, $f02, $tk, $errors, $_POST);

// =====================================================================
function render_error(string $title, string $hint): void
{
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>Erreur</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container py-5"><div class="row justify-content-center"><div class="col-md-6">
<div class="card shadow-sm border-0"><div class="card-body p-4 text-center">
<i class="bi bi-x-octagon text-danger" style="font-size:3rem;"></i>
<h4 class="mt-3"><?= e($title) ?></h4><p class="text-muted"><?= e($hint) ?></p>
</div></div></div></div></div></body></html>
<?php
}

function render_success(array $imp): void
{
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>FRP signee</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container py-5"><div class="row justify-content-center"><div class="col-md-7">
<div class="card shadow-sm border-0"><div class="card-body p-4 text-center">
<i class="bi bi-check2-circle text-success" style="font-size:4rem;"></i>
<h3 class="mt-3 text-success">Signature enregistree</h3>
<p>Merci. Votre signature pour le dossier <strong><?= e($imp['numero']) ?></strong> a ete enregistree.</p>
<p class="text-muted">L'Administrateur et le Coordinateur vont signer a leur tour pour cloturer definitivement le dossier.</p>
<p class="text-muted small">Vous pouvez fermer cette fenetre.</p>
</div></div></div></div></div></body></html>
<?php
}

function render_form(array $imp, array $nh, array $f02, array $tk, array $errors, array $post): void
{
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow"><title>Signature FRP - DEVDYNAMICS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:linear-gradient(135deg,#1F4E79,#1A7A5E); min-height:100vh; }
#sigCanvas { border:2px dashed #ccc; background:#fff; width:100%; height:160px; }
</style></head>
<body>
<div class="container py-4"><div class="row justify-content-center"><div class="col-lg-8">

<div class="card shadow-lg border-0"><div class="card-body p-4">
<h3 class="text-primary"><i class="bi bi-clipboard2-check"></i> Signature - Fiche de Reglement</h3>
<p class="text-muted">DEVDYNAMICS / Academie des Cartographes Populaires</p>

<hr>

<dl class="row mb-3 small">
    <dt class="col-4">Beneficiaire</dt><dd class="col-8"><?= e($imp['prestataire']) ?></dd>
    <dt class="col-4">Dossier F01</dt><dd class="col-8"><?= e($imp['numero']) ?></dd>
    <dt class="col-4">Note d'Honoraires</dt><dd class="col-8"><?= e($nh['numero']) ?></dd>
    <dt class="col-4">Mode paiement</dt><dd class="col-8"><span class="badge bg-light text-dark"><?= e($f02['mode_paiement']) ?></span></dd>
    <?php if ($f02['mode_paiement']==='cheque'): ?>
    <dt class="col-4">N° cheque</dt><dd class="col-8 font-monospace"><?= e($f02['numero_cheque']) ?></dd>
    <?php endif; ?>
    <dt class="col-4">Net a verser</dt><dd class="col-8 fw-bold"><?= format_htg($f02['total_net_a_verser']) ?></dd>
</dl>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

    <div class="mb-3">
        <label class="form-label">Evaluation des livrables</label>
        <select name="evaluation_livrables" class="form-select">
            <option value="conformes" <?= (($post['evaluation_livrables'] ?? 'conformes')==='conformes')?'selected':'' ?>>Conformes</option>
            <option value="partiels" <?= (($post['evaluation_livrables'] ?? '')==='partiels')?'selected':'' ?>>Partiels</option>
            <option value="non_conformes" <?= (($post['evaluation_livrables'] ?? '')==='non_conformes')?'selected':'' ?>>Non conformes</option>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">Votre signature <span class="text-danger">*</span></label>
        <p class="small text-muted">Dessinez avec votre souris (ordinateur) ou votre doigt (smartphone).</p>
        <canvas id="sigCanvas"></canvas>
        <button type="button" id="clearSig" class="btn btn-sm btn-outline-secondary mt-2">Effacer</button>
        <input type="hidden" name="signature_padpng" id="signature_padpng">
    </div>

    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i> Votre signature finalise votre engagement de prestataire.
        L'Administrateur et le Coordinateur signeront ensuite pour cloturer le dossier.
    </div>

    <button class="btn btn-primary w-100" id="btnSubmit">
        <i class="bi bi-send-check"></i> Confirmer ma signature
    </button>
</form>

<hr class="mt-4">
<p class="text-center text-muted small mb-0">Lien valide jusqu'au <?= e(date('d/m/Y H:i', strtotime($tk['expire_at']))) ?></p>
</div></div>

</div></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/signature_pad/4.1.7/signature_pad.umd.min.js"></script>
<script>
(function() {
    const canvas = document.getElementById('sigCanvas');
    const pad = new SignaturePad(canvas, { backgroundColor: 'rgba(255,255,255,1)' });
    document.getElementById('clearSig').addEventListener('click', () => pad.clear());
    function resize() {
        const r = window.devicePixelRatio || 1;
        canvas.width = canvas.offsetWidth * r;
        canvas.height = canvas.offsetHeight * r;
        canvas.getContext('2d').scale(r, r);
        pad.clear();
    }
    window.addEventListener('resize', resize);
    setTimeout(resize, 50);
    document.getElementById('btnSubmit').closest('form').addEventListener('submit', (e) => {
        if (pad.isEmpty()) {
            e.preventDefault();
            alert('Veuillez signer dans le cadre.');
            return;
        }
        document.getElementById('signature_padpng').value = pad.toDataURL('image/png');
    });
})();
</script>
</body></html>
<?php
}
