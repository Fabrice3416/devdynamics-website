<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(base_path('dashboard.php'));
}

$error = null;
$expired = isset($_GET['expired']);
$emailFld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $ip = client_ip() ?? '0.0.0.0';
        if (login_attempts_exceeded($ip)) {
            $error = 'Trop de tentatives. Réessayez dans quelques minutes.';
        } else {
            $emailFld = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($emailFld === '' || $password === '') {
                $error = 'Email et mot de passe obligatoires.';
            } else {
                $u = find_user_by_email($emailFld);
                if ($u && password_verify($password, $u['mot_de_passe'])) {
                    login_user($u);
                    // Le projet courant se choisit juste apres : aucune donnee d'execution hors projet.
                    redirect(base_path(!empty($u['doit_changer_mdp']) ? 'profil.php?mdp=1' : 'projets.php'));
                }
                log_failed_login($emailFld);
                $error = 'Identifiants incorrects.';
            }
        }
    } catch (Throwable $ex) {
        error_log('login error: ' . $ex->getMessage());
        $error = 'Erreur technique. Réessayez.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion &middot; Bousòl</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_path('assets/css/bousol.css')) ?>?v=1">
</head>
<body class="bousol-login">
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-compass fs-1" style="color:var(--bsl-olive)"></i>
                        <h4 class="mt-2 mb-0">Bousòl</h4>
                        <small class="text-muted">Pilotage des projets subventionnés</small>
                    </div>
                    <?php if ($expired): ?><div class="alert alert-warning">Votre session a expiré. Reconnectez-vous.</div><?php endif; ?>
                    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                    <form method="post" action="<?= e(base_path('login.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control" value="<?= e($emailFld) ?>" required autofocus autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Mot de passe</label>
                            <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
                    </form>
                </div>
            </div>
            <p class="text-center text-white-50 mt-3 small">
                Accès réservé aux utilisateurs autorisés.<br>DÉVELOPPEMENT ET DYNAMISME &middot; PAIESC / Union européenne
            </p>
        </div>
    </div>
</div>
</body>
</html>
