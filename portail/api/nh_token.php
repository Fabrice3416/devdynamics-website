<?php
declare(strict_types=1);

/**
 * Endpoint public tokenise pour la soumission de la Note d'Honoraires (NH).
 * Aucune session requise - le token (72h, single-use) autorise l'acces.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../services/TokenService.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/NoteHonoraireModel.php';

// Pas de session - on cree une session light uniquement pour le CSRF
if (session_status() === PHP_SESSION_NONE) {
    session_name('PORTAIL_NH_TOKEN');
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
function csrf_field_local(): string {
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$token = trim((string)($_GET['t'] ?? ''));
$errors = [];
$success = false;

if ($token === '' || strlen($token) !== 64) {
    render_error('Lien invalide.', 'Verifiez le lien recu par email.');
    exit;
}

$tk = TokenService::validate($token, 'note_honoraires', $ip);
if (!$tk) {
    render_error(
        'Lien expire ou deja utilise.',
        'Contactez l Administrateur DEVDYNAMICS pour obtenir un nouveau lien.'
    );
    exit;
}

// Charge l'imputation associee
$imp = ImputationModel::find((int)$tk['imputation_id']);
if (!$imp) {
    render_error('Dossier introuvable.', 'Contactez l Administrateur.');
    exit;
}

// Verifie qu'aucune NH n'existe deja
if (NoteHonoraireModel::findByImputation((int)$imp['id'])) {
    render_error(
        'Note d\'honoraires deja soumise.',
        'Si vous avez besoin de la modifier, contactez l Administrateur.'
    );
    exit;
}

// =====================================================================
// POST : soumission de la NH
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$submittedCsrf)) {
        $errors[] = 'Jeton CSRF invalide. Rechargez la page.';
    } else {
        $description = trim((string)($_POST['description_prestation'] ?? ''));
        $mode        = (string)($_POST['mode_paiement'] ?? 'cheque');
        $coords      = trim((string)($_POST['coordonnees_bancaires'] ?? ''));
        $padData     = (string)($_POST['signature_padpng'] ?? '');

        if ($description === '')          $errors[] = 'Description prestation obligatoire.';
        if (!in_whitelist($mode, ['cheque','virement'])) $errors[] = 'Mode paiement invalide.';
        if ($mode === 'virement' && $coords === '') $errors[] = 'Coordonnees bancaires obligatoires si virement.';
        if ($padData === '')              $errors[] = 'Signature obligatoire.';

        if (!$errors) {
            // Sauvegarde signature PNG
            $sigPath = __DIR__ . '/../storage/signatures/presta/nh_' . (int)$tk['id'] . '.png';
            $savedPath = save_base64_png($padData, $sigPath);
            if ($savedPath === null) {
                $errors[] = 'Signature invalide (image PNG corrompue).';
            }

            if (!$errors) {
                $sigRel = 'storage/signatures/presta/nh_' . (int)$tk['id'] . '.png';

                db()->beginTransaction();
                try {
                    $numero = generate_numero('NH', 'notes_honoraires');
                    $nhId = NoteHonoraireModel::create([
                        'numero' => $numero,
                        'imputation_id' => (int)$imp['id'],
                        'token_id' => (int)$tk['id'],
                        'description_prestation' => $description,
                        'montant_brut' => $imp['montant'],
                        'mode_paiement' => $mode,
                        'coordonnees_bancaires' => $coords ?: null,
                        'certifie_prestataire' => 1,
                        'date_soumission' => date('Y-m-d H:i:s'),
                        'sig_presta_scan' => $sigRel,
                    ]);

                    TokenService::markUsed((int)$tk['id'], $sigRel);

                    // Genere le token FRP pour la signature finale
                    $tokenFrp = TokenService::create(
                        'signature_frp',
                        (int)$imp['id'],
                        $tk['email_destinataire'],
                        $imp['prestataire'] ?? 'Prestataire',
                        'Votre Note d\'Honoraires a ete enregistree (N° ' . $numero
                        . '). Veuillez maintenant signer la Fiche de Reglement (FRP) finale.'
                    );

                    // Notifier l'Administrateur
                    $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role='administrateur' AND actif=1");
                    while ($admin = $stmt->fetch()) {
                        $body = '<p>Bonjour ' . e($admin['nom_complet']) . ',</p>'
                              . '<p>Le prestataire <strong>' . e($imp['prestataire']) . '</strong> vient de soumettre sa Note d\'Honoraires :</p>'
                              . '<ul><li>F01 : ' . e($imp['numero']) . '</li>'
                              . '<li>NH : ' . e($numero) . '</li>'
                              . '<li>Montant : ' . format_htg($imp['montant']) . '</li></ul>'
                              . '<p>Le lien de signature FRP a ete envoye au prestataire. '
                              . 'Une fois sa signature recue, vous pourrez signer a votre tour.</p>'
                              . '<p><a href="https://dev-dynamics.org/portail/compta/frp.php">Acceder au portail</a></p>';
                        alerte_envoyer('nh_soumise', $admin['email'],
                            'NH soumise : ' . $numero, $body,
                            ['type' => 'notes_honoraires', 'id' => $nhId]);
                    }

                    // Cree aussi la FRP (avec sig_prestataire=1 si signature deja capturee)
                    // En realite, la FRP est creee SEPAREMENT lors de la signature via frp_token
                    // Ici on garde uniquement la NH

                    audit_log_anonymous('nh_soumise', "Soumission NH $numero via token", 'notes_honoraires', $nhId);
                    db()->commit();
                    $success = true;
                } catch (Throwable $e) {
                    db()->rollBack();
                    error_log('NH submission failed: ' . $e->getMessage());
                    $errors[] = 'Erreur technique lors de l enregistrement.';
                }
            }
        }
    }
}

if ($success) {
    render_success($imp);
    exit;
}

render_form($imp, $tk, $errors, $_POST);

// =====================================================================
// AUDIT LOG sans session connectee
// =====================================================================
function audit_log_anonymous(string $action, string $description, ?string $refType, ?int $refId): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO audit_log (user_id, action, description, reference_type, reference_id, ip_address, user_agent)
             VALUES (NULL, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $action, $description, $refType, $refId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) { error_log('audit_log_anonymous: ' . $e->getMessage()); }
}

// =====================================================================
// VIEWS
// =====================================================================
function render_error(string $title, string $hint): void
{
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow">
<title>Erreur - DEVDYNAMICS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm border-0"><div class="card-body p-4 text-center">
                <i class="bi bi-x-octagon text-danger" style="font-size:3rem;"></i>
                <h4 class="mt-3"><?= e($title) ?></h4>
                <p class="text-muted"><?= e($hint) ?></p>
            </div></div>
        </div>
    </div>
</div></body></html>
<?php
}

function render_success(array $imp): void
{
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow">
<title>NH enregistree - DEVDYNAMICS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card shadow-sm border-0"><div class="card-body p-4 text-center">
                <i class="bi bi-check2-circle text-success" style="font-size:4rem;"></i>
                <h3 class="mt-3 text-success">Note d'honoraires enregistree</h3>
                <p>Merci. Votre Note d'Honoraires pour le dossier <strong><?= e($imp['numero']) ?></strong> a ete enregistree.</p>
                <p class="text-muted">Vous allez recevoir un second email avec un lien pour signer
                la Fiche de Reglement (FRP) - veuillez patienter quelques minutes.</p>
                <p class="text-muted small">Vous pouvez fermer cette fenetre.</p>
            </div></div>
        </div>
    </div>
</div></body></html>
<?php
}

function render_form(array $imp, array $tk, array $errors, array $post): void
{
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Note d'Honoraires - DEVDYNAMICS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:linear-gradient(135deg,#1F4E79,#1A7A5E); min-height:100vh; }
#sigCanvas { border:2px dashed #ccc; background:#fff; width:100%; height:160px; }
</style>
</head>
<body>
<div class="container py-4">
<div class="row justify-content-center">
<div class="col-lg-8">

<div class="card shadow-lg border-0">
    <div class="card-body p-4">
        <h3 class="text-primary"><i class="bi bi-file-earmark-text"></i> Note d'Honoraires</h3>
        <p class="text-muted">DEVDYNAMICS / Academie des Cartographes Populaires</p>

        <hr>

        <dl class="row mb-3 small">
            <dt class="col-4">Beneficiaire</dt><dd class="col-8"><?= e($imp['prestataire']) ?></dd>
            <dt class="col-4">Dossier (F01)</dt><dd class="col-8"><?= e($imp['numero']) ?></dd>
            <dt class="col-4">Date</dt><dd class="col-8"><?= e(date('d/m/Y', strtotime($imp['date_depense']))) ?></dd>
            <dt class="col-4">Description</dt><dd class="col-8"><?= e($imp['description']) ?></dd>
            <dt class="col-4">Montant brut</dt><dd class="col-8 fw-bold"><?= format_htg($imp['montant']) ?></dd>
        </dl>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <?= csrf_field_local() ?>

            <div class="mb-3">
                <label class="form-label">Description de la prestation <span class="text-danger">*</span></label>
                <textarea name="description_prestation" class="form-control" rows="3" required><?= e($post['description_prestation'] ?? $imp['description']) ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Mode de paiement souhaite</label>
                <select name="mode_paiement" id="mode_paiement" class="form-select">
                    <option value="cheque" <?= (($post['mode_paiement'] ?? 'cheque')==='cheque')?'selected':'' ?>>Cheque SOGEBANK (par defaut)</option>
                    <option value="virement" <?= (($post['mode_paiement'] ?? '')==='virement')?'selected':'' ?>>Virement bancaire</option>
                </select>
            </div>

            <div class="mb-3" id="bloc-coords" style="display:none;">
                <label class="form-label">Coordonnees bancaires <span class="text-danger">*</span></label>
                <textarea name="coordonnees_bancaires" class="form-control" rows="2"
                          placeholder="Banque, N° compte, IBAN, beneficiaire..."><?= e($post['coordonnees_bancaires'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Votre signature <span class="text-danger">*</span></label>
                <p class="small text-muted">Dessinez avec votre souris (ordinateur) ou votre doigt (smartphone).</p>
                <canvas id="sigCanvas"></canvas>
                <button type="button" id="clearSig" class="btn btn-sm btn-outline-secondary mt-2">Effacer</button>
                <input type="hidden" name="signature_padpng" id="signature_padpng">
            </div>

            <div class="alert alert-info small">
                <i class="bi bi-info-circle"></i> En validant, vous certifiez l'exactitude des informations ci-dessus.
                Apres cette etape, vous recevrez un second email pour signer la Fiche de Reglement finale.
            </div>

            <button id="btnSubmit" class="btn btn-primary w-100">
                <i class="bi bi-send-check"></i> Soumettre ma Note d'Honoraires
            </button>
        </form>

        <hr class="mt-4">
        <p class="text-center text-muted small mb-0">
            Lien valide jusqu'au <?= e(date('d/m/Y H:i', strtotime($tk['expire_at']))) ?>
        </p>
    </div>
</div>

</div></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/signature_pad/4.1.7/signature_pad.umd.min.js"></script>
<script>
(function() {
    const canvas = document.getElementById('sigCanvas');
    const pad = new SignaturePad(canvas, { backgroundColor: 'rgba(255,255,255,1)' });
    const clearBtn = document.getElementById('clearSig');
    const hidden = document.getElementById('signature_padpng');
    const form = document.getElementById('btnSubmit').closest('form');
    const modeSel = document.getElementById('mode_paiement');
    const blocCoords = document.getElementById('bloc-coords');

    function resize() {
        const ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
    }
    window.addEventListener('resize', resize);
    setTimeout(resize, 50);

    clearBtn.addEventListener('click', () => pad.clear());

    function refreshMode() {
        const isVir = modeSel.value === 'virement';
        blocCoords.style.display = isVir ? '' : 'none';
        blocCoords.querySelector('textarea').required = isVir;
    }
    modeSel.addEventListener('change', refreshMode);
    refreshMode();

    form.addEventListener('submit', (e) => {
        if (pad.isEmpty()) {
            e.preventDefault();
            alert('Veuillez signer dans le cadre avant de soumettre.');
            return;
        }
        hidden.value = pad.toDataURL('image/png');
    });
})();
</script>
</body>
</html>
<?php
}
