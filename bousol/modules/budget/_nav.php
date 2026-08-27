<?php
/** Sous-navigation du module Budget. Variable : $ongletActif */
$ongletsBudget = [
    'arbre'        => ['index.php',        'Nomenclature',   ['coordinateur', 'raf', 'mandataire']],
    'realloc'      => ['realloc.php',      'Réallocation',   ['coordinateur']],
    'detail'       => ['nomenclature.php', 'Détail du contrat', ['coordinateur']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsBudget as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/budget/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
