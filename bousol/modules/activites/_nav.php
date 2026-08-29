<?php
/** Sous-navigation du module Activites. Variable : $ongletActif */
$ongletsAct = [
    'cadre'    => ['index.php',    'Cadre logique',   ['coordinateur', 'raf', 'mandataire']],
    'sessions' => ['sessions.php', 'Formations',      ['coordinateur', 'raf']],
    'registre' => ['registre.php', 'Versions et anomalies', ['coordinateur', 'raf']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsAct as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/activites/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
