<?php
/**
 * Sous-navigation du module Tiers. Variable : $ongletActif
 *
 * Le referentiel des tiers est partage entre les projets (CDC 1.4) ; les contrats et
 * le registre des beneficiaires, eux, appartiennent au projet courant.
 *
 * L'annexe B ne dit rien de la tenue du referentiel : elle decrit le cycle de la
 * depense, pas l'administratif. Le partage retenu suit la logique des autres lignes
 * de la matrice - le RAF constitue, le Coordinateur engage. D'ou : le referentiel et
 * les beneficiaires s'ecrivent a deux, le contrat n'est signe que par le Coordinateur.
 */
$ongletsTiers = [
    'referentiel'   => ['index.php',         'Référentiel',   ['coordinateur', 'raf', 'mandataire']],
    'contrats'      => ['contrats.php',      'Contrats',      ['coordinateur', 'raf', 'mandataire']],
    'beneficiaires' => ['beneficiaires.php', 'Bénéficiaires', ['coordinateur', 'raf', 'mandataire']],
];
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($ongletsTiers as $k => [$href, $lib, $roles]): if (!in_array(user_role(), $roles, true)) continue; ?>
    <li class="nav-item"><a class="nav-link <?= ($ongletActif ?? '') === $k ? 'active' : '' ?>"
        href="<?= e(base_path('modules/tiers/' . $href)) ?>"><?= e($lib) ?></a></li>
    <?php endforeach; ?>
</ul>
