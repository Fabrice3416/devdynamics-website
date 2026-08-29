<?php
/** Sous-navigation du module Remuneration. Variable : $ongletActif */
$ongletsRem = [
    'rapports'   => ['index.php', 'Rapports et prestations', ['coordinateur', 'raf', 'mandataire']],
    'dgi'        => ['dgi.php',   'Versements à la DGI',     ['coordinateur', 'raf']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsRem as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/remuneration/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
