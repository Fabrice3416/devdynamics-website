<?php
/** Sous-navigation du module Restitution. Variable : $ongletActif */
$ongletsRest = [
    'cloture'     => ['index.php',       'Clôture et rapports', ['coordinateur', 'raf', 'mandataire']],
    'ventilation' => ['ventilation.php', 'Ventilation détaillée', ['coordinateur', 'raf']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsRest as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/restitution/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
