<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!is_logged_in()) {
    redirect(base_path('login.php'));
}

$forcer = !empty($_SESSION['doit_changer_mdp']) || isset($_GET['mdp']);
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mdp') {
    verify_csrf();
    $actuel = (string)($_POST['actuel'] ?? '');
    $nouveau = (string)($_POST['nouveau'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    $stmt = db()->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = ?');
    $stmt->execute([user_id()]);
    $hash = (string)$stmt->fetchColumn();

    if (!password_verify($actuel, $hash)) {
        $erreur = 'Mot de passe actuel incorrect.';
    } elseif (strlen($nouveau) < 12 || !preg_match('/[A-Z]/', $nouveau) || !preg_match('/[a-z]/', $nouveau) || !preg_match('/\d/', $nouveau)) {
        $erreur = 'Le nouveau mot de passe doit faire au moins 12 caractères avec majuscule, minuscule et chiffre.';
    } elseif ($nouveau !== $confirm) {
        $erreur = 'La confirmation ne correspond pas.';
    } elseif (password_verify($nouveau, $hash)) {
        $erreur = 'Le nouveau mot de passe doit être différent de l\'actuel.';
    } else {
        db()->prepare('UPDATE utilisateurs SET mot_de_passe = ?, doit_changer_mdp = 0 WHERE id = ?')
            ->execute([hash_password($nouveau), user_id()]);
        $_SESSION['doit_changer_mdp'] = false;
        audit('noyau', 'mot_de_passe_change', 'utilisateur', user_id());
        flash_set('success', 'Mot de passe mis à jour.');
        redirect(base_path(projet_id() === null ? 'projets.php' : 'dashboard.php'));
    }
}

page_start('Profil', 'profil');
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <?php if ($forcer): ?>
            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Vous devez définir un nouveau mot de passe avant de continuer.</div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h1 class="h5 mb-3"><i class="bi bi-person-circle"></i> <?= e(user_nom()) ?></h1>
                <dl class="row mb-0 small">
                    <dt class="col-sm-4">Rôle</dt><dd class="col-sm-8">
                        <?php if (user_est_admin_outil()): ?><?= e(ADMIN_OUTIL_LIBELLE) ?><br><small class="text-muted">crée les projets, n'y saisit rien</small><br><?php endif; ?>
                        <?php foreach (projets_accessibles() as $pr): if (empty($pr['role'])) continue; ?>
                            <?= e(ROLES_LIBELLES[$pr['role']] ?? $pr['role']) ?> <small class="text-muted">sur <?= e($pr['intitule']) ?></small><br>
                        <?php endforeach; ?>
                    </dd>
                    <dt class="col-sm-4">Qualité de mandataire</dt><dd class="col-sm-8"><?= user_est_mandataire() ? 'Oui — signataire du compte bancaire' : 'Non' ?></dd>
                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($_SESSION['user_email'] ?? '') ?></dd>
                </dl>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 mb-3"><i class="bi bi-key"></i> Changer le mot de passe</h2>
                <?php if ($erreur): ?><div class="alert alert-danger"><?= e($erreur) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mdp">
                    <div class="mb-3"><label class="form-label">Mot de passe actuel</label><input type="password" name="actuel" class="form-control" required autocomplete="current-password"></div>
                    <div class="mb-3"><label class="form-label">Nouveau mot de passe</label><input type="password" name="nouveau" class="form-control" required minlength="12" autocomplete="new-password"></div>
                    <div class="mb-3"><label class="form-label">Confirmation</label><input type="password" name="confirm" class="form-control" required autocomplete="new-password"></div>
                    <button class="btn btn-primary">Enregistrer</button>
                </form>
            </div>
        </div>
        <p class="small mt-3"><a href="<?= e(base_path('modules/signature/specimen.php')) ?>"><i class="bi bi-pen"></i> Gérer mon spécimen de signature</a> (image et acte de dépôt signé à la main).</p>
    </div>
</div>
<?php page_end();
