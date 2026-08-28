<?php
/**
 * Sous-navigation du module Comptes. Variable : $ongletActif
 *
 * Les droits suivent l'annexe B : le RAF etablit le rapprochement et libelle les
 * reglements, le Coordinateur les lit, et la signature appartient aux mandataires
 * quel que soit leur role de projet.
 */
$ongletsComptes = [
    'plan'          => ['index.php',         'Plan et balance',  ['coordinateur', 'raf', 'mandataire']],
    'reglements'    => ['reglements.php',    'Règlements',       ['coordinateur', 'raf', 'mandataire']],
    'journal'       => ['journal.php',       'Journal',          ['coordinateur', 'raf', 'mandataire']],
    'rapprochement' => ['rapprochement.php', 'Rapprochement',    ['coordinateur', 'raf']],
    'caisse'        => ['caisse.php',        'Petite caisse',    ['coordinateur', 'raf']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsComptes as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/comptes/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
