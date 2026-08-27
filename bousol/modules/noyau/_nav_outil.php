<?php
/**
 * Sous-navigation de l'administration de l'outil. Variable : $ongletOutil
 * Ces deux ecrans sont exterieurs a tout projet : ils portent le referentiel des
 * personnes, partage, et la creation des projets.
 */
$ongletsOutil = [
    'projets'      => ['projets.php',      'Projets et affectations'],
    'utilisateurs' => ['utilisateurs.php', 'Personnes et accès'],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsOutil as $k => [$href, $lib]): ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletOutil ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/noyau/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
    <?php if (projet_id() !== null): ?>
    <li class="nav-item ms-auto"><a class="nav-link" href="<?= e(base_path('dashboard.php')) ?>">
        <i class="bi bi-arrow-left"></i> Revenir au projet</a></li>
    <?php endif; ?>
</ul>
