<?php
/** Sous-navigation du module Financement. Variable : $ongletActif */
$ongletsFin = [
    'tresorerie' => ['index.php',    'Trésorerie et tranches', ['coordinateur', 'raf', 'mandataire']],
    'demandes'   => ['demandes.php', 'Demandes de versement',  ['coordinateur', 'raf']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsFin as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/financement/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
