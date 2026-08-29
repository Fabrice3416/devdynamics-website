<?php
/** Sous-navigation du module Noyau. Variable : $ongletActif */
$onglets = [
    'parametres' => ['index.php',      'Paramètres du projet', ['coordinateur']],
    'modules'    => ['modules.php',    'Modules',              ['coordinateur']],
    'sauvegarde' => ['sauvegarde.php', 'Sauvegarde',           ['coordinateur']],
    'audit'      => ['audit.php',      'Journal d\'audit',     ['coordinateur', 'raf', 'mandataire']],
    'bascule'    => ['bascule.php',    'Bascule',              ['coordinateur']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($onglets as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>" href="<?= e(base_path('modules/noyau/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
    <?php if (user_est_admin_outil()): ?>
    <li class="nav-item ms-auto"><a class="nav-link" href="<?= e(base_path('modules/noyau/projets.php')) ?>"><i class="bi bi-diagram-2"></i> Administration de l'outil</a></li>
    <?php endif; ?>
</ul>
