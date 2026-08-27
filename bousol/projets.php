<?php
declare(strict_types=1);

/**
 * Choix du projet courant.
 *
 * Un utilisateur travaille toujours a l'interieur d'un projet actif, selectionne a la
 * connexion et affiche en permanence (CDC 1.4). Cette contrainte n'est pas ergonomique
 * mais comptable : la premiere erreur d'un outil multi-projets est l'imputation d'une
 * depense au mauvais bailleur.
 */

require_once __DIR__ . '/includes/layout.php';
require_login();

$projets = projets_accessibles();

// Un seul projet accessible : inutile de faire choisir.
if (isset($_GET['id'])) {
    if (choisir_projet((int)$_GET['id'])) {
        audit('noyau', 'projet_selectionne', 'projet', projet_id(), projet_code());
        redirect(base_path('dashboard.php'));
    }
    flash_set('danger', 'Projet inaccessible : vous n\'y êtes pas affecté.');
} elseif (count($projets) === 1 && projet_id() === null) {
    if (choisir_projet((int)$projets[0]['id'])) {
        redirect(base_path('dashboard.php'));
    }
}

page_start('Projets', '');
?>
<div class="bousol-sans-projet">
    <h1 class="h4 mb-1">Choisir un projet</h1>
    <p class="text-muted small mb-4">Les soldes, les listes, les files d'attente et les rapports ne montrent que le projet courant.</p>

    <?php if (!$projets): ?>
        <div class="card">
            <div class="card-body">
                <h2 class="h6">Aucun projet accessible</h2>
                <p class="mb-0 text-muted small">
                    Vous n'êtes affecté à aucun projet en cours. L'absence d'affectation vaut absence d'accès, y compris en lecture.
                    <?php if (user_est_admin_outil()): ?>
                        En tant qu'administrateur de l'outil, vous pouvez <a href="<?= e(base_path('modules/noyau/projets.php')) ?>">créer un projet</a>.
                    <?php else: ?>
                        Demandez à l'administrateur de l'outil de vous affecter, sur présentation de votre acte de délégation.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($projets as $p): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
               href="?id=<?= (int)$p['id'] ?>">
                <span>
                    <strong><?= e($p['intitule']) ?></strong>
                    <small class="text-muted">· <?= e($p['bailleur']) ?></small><br>
                    <small class="text-muted"><code><?= e($p['code']) ?></code>
                        <?php if (!empty($p['role'])): ?> · <?= e(ROLES_LIBELLES[$p['role']] ?? $p['role']) ?>
                        <?php elseif (user_est_admin_outil()): ?> · <?= e(ADMIN_OUTIL_LIBELLE) ?>, sans rôle dans ce projet<?php endif; ?>
                    </small>
                </span>
                <span class="badge <?= $p['statut'] === 'actif' ? 'badge-module-actif' : 'badge-a-definir' ?>"><?= e($p['statut']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php page_end();
