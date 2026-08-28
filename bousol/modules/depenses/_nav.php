<?php
/** Sous-navigation du module Depenses. Variable : $ongletActif */
$ongletsDepenses = [
    'ouverts' => ['index.php?statut=ouverts', 'Dossiers en cours', ['coordinateur', 'raf', 'mandataire']],
    'tous'    => ['index.php',                'Tous les dossiers', ['coordinateur', 'raf', 'mandataire']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsDepenses as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/depenses/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
