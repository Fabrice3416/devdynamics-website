<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('/portail/dashboard.php');
}

$error    = null;
$expired  = isset($_GET['expired']);
$emailFld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (login_attempts_exceeded($ip)) {
            $error = 'Trop de tentatives. Reessayez dans quelques minutes.';
        } else {
            $emailFld = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($emailFld === '' || $password === '') {
                $error = 'Email et mot de passe obligatoires.';
            } else {
                $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND actif = 1 LIMIT 1');
                $stmt->execute([$emailFld]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['mot_de_passe'])) {
                    login_user($user);
                    redirect('/portail/dashboard.php');
                } else {
                    log_failed_login($emailFld, $ip);
                    $error = 'Identifiants incorrects.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('login error: ' . $e->getMessage());
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
    <title>Connexion &middot; Portail DEVDYNAMICS</title>
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
                        <i class="bi bi-shield-lock-fill fs-1 text-primary"></i>
                        <h4 class="mt-2 mb-0">Portail DEVDYNAMICS</h4>
                        <small class="text-muted">Academie des Cartographes Populaires</small>
                    </div>

                    <?php if ($expired): ?>
                        <div class="alert alert-warning">Votre session a expire. Reconnectez-vous.</div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post" action="/portail/login.php" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control"
                                   value="<?= e($emailFld) ?>" required autofocus autocomplete="username">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Mot de passe</label>
                            <input type="password" name="password" id="password" class="form-control"
                                   required autocomplete="current-password">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right"></i> Se connecter
                        </button>
                    </form>

                    <hr class="my-4">

                    <div class="text-center">
                        <a href="/portail/reset.php" class="small text-decoration-none">
                            Mot de passe oublie ?
                        </a>
                    </div>
                </div>
            </div>
            <p class="text-center text-muted mt-3 small">
                Acces reserve aux utilisateurs autorises.<br>
                Contrat PAIESC/CS/04-2026/021
            </p>
        </div>
    </div>
</div>
</body>
</html>
