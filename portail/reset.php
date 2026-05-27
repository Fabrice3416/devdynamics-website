<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/alerts.php';

$token   = trim((string)($_GET['token'] ?? ''));
$mode    = $token !== '' ? 'change' : 'request';
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        if ($mode === 'request') {
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email === '') {
                $error = 'Email obligatoire.';
            } else {
                $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND actif = 1 LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // On affiche toujours le meme message (anti-enumeration)
                $success = 'Si un compte existe pour cet email, un lien de reinitialisation vient d etre envoye.';

                if ($user) {
                    $newToken = bin2hex(random_bytes(32));
                    $stmt = db()->prepare(
                        'UPDATE users SET reset_token = ?, reset_token_expire = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?'
                    );
                    $stmt->execute([$newToken, (int)$user['id']]);

                    $cfg = config();
                    $link = rtrim($cfg['app']['url'], '/') . '/reset.php?token=' . $newToken;
                    $body = '<p>Bonjour ' . e($user['nom_complet']) . ',</p>'
                          . '<p>Vous avez demande la reinitialisation de votre mot de passe. Cliquez sur le lien suivant :</p>'
                          . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
                          . '<p>Ce lien expire dans 1 heure. Si vous n etes pas a l origine de cette demande, ignorez ce message.</p>';

                    alerte_envoyer('token_expire', $user['email'],
                        'Reinitialisation de votre mot de passe - Portail DEVDYNAMICS', $body);
                }
            }
        } elseif ($mode === 'change') {
            $pw1 = (string)($_POST['password'] ?? '');
            $pw2 = (string)($_POST['password_confirm'] ?? '');

            if (strlen($pw1) < 10) {
                $error = 'Le mot de passe doit comporter au moins 10 caracteres.';
            } elseif ($pw1 !== $pw2) {
                $error = 'Les deux mots de passe ne correspondent pas.';
            } else {
                $stmt = db()->prepare(
                    'SELECT * FROM users
                       WHERE reset_token = ? AND reset_token_expire > NOW() AND actif = 1
                       LIMIT 1'
                );
                $stmt->execute([$token]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'Lien invalide ou expire. Demandez un nouveau lien.';
                } else {
                    $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 10]);
                    $stmt = db()->prepare(
                        'UPDATE users SET mot_de_passe = ?, reset_token = NULL, reset_token_expire = NULL WHERE id = ?'
                    );
                    $stmt->execute([$hash, (int)$user['id']]);

                    audit_log('login', 'Mot de passe reinitialise', 'users', (int)$user['id']);
                    flash_set('success', 'Mot de passe mis a jour. Connectez-vous.');
                    redirect('/portail/login.php');
                }
            }
        }
    } catch (Throwable $e) {
        error_log('reset.php error: ' . $e->getMessage());
        $error = 'Erreur technique. Reessayez.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Reinitialisation &middot; Portail DEVDYNAMICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/portail/assets/css/portail.css?v=1">
</head>
<body class="portail-login">
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-key-fill fs-1 text-primary"></i>
                        <h4 class="mt-2 mb-0">
                            <?= $mode === 'change' ? 'Nouveau mot de passe' : 'Mot de passe oublie' ?>
                        </h4>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= e($success) ?></div>
                    <?php endif; ?>

                    <?php if ($mode === 'request'): ?>
                        <form method="post" action="/portail/reset.php" novalidate>
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="email" class="form-label">Votre email</label>
                                <input type="email" name="email" id="email" class="form-control" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                Envoyer le lien de reinitialisation
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/portail/reset.php?token=<?= e($token) ?>" novalidate>
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label for="password" class="form-label">Nouveau mot de passe (min 10 caracteres)</label>
                                <input type="password" name="password" id="password" class="form-control" required minlength="10">
                            </div>
                            <div class="mb-3">
                                <label for="password_confirm" class="form-label">Confirmer</label>
                                <input type="password" name="password_confirm" id="password_confirm" class="form-control" required minlength="10">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Valider</button>
                        </form>
                    <?php endif; ?>

                    <hr class="my-4">
                    <div class="text-center">
                        <a href="/portail/login.php" class="small text-decoration-none">
                            <i class="bi bi-arrow-left"></i> Retour au login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
