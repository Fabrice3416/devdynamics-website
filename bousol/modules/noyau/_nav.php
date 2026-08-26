<?php
/** Sous-navigation du module Noyau. Variable : $ongletActif */
$onglets = [
    'parametres'   => ['index.php',         'Paramètres',   ['coordinateur']],
    'utilisateurs' => ['utilisateurs.php',  'Utilisateurs', ['coordinateur']],
    'modules'      => ['modules.php',       'Modules',      ['coordinateur']],
    'sauvegarde'   => ['sauvegarde.php',    'Sauvegarde',   ['coordinateur']],
    'audit'        => ['audit.php',         'Journal d\'audit', ['coordinateur', 'raf', 'mandataire']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($onglets as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>" href="<?= e(base_path('modules/noyau/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
