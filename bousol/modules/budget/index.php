<?php
declare(strict_types=1);

/**
 * Budget - la nomenclature du projet, ses deux colonnes et sa consommation.
 *
 * Le budget contractuel est fige et ne bouge que par avenant ; le budget de gestion
 * est la vue interne que les reallocations mettent a jour (CDC 2.2). Les deux sont
 * affiches cote a cote, parce que l'ecart entre eux est justement ce que les
 * controles surveillent.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/budget.php';
require_projet();
require_module('budget');

$lignes    = budget_lignes();
$consomme  = budget_consomme();
$variations = budget_variations();
$manque    = budget_detail_manquant();
$indirects = budget_couts_indirects_constates();
$plafond   = plafond_contractuel();
$totalGestion = budget_total_gestion();
$partRh    = budget_part_rh();
$directs   = budget_couts_directs_contractuels();
$provision = budget_ligne_provision();
$alerte    = (int)(param('seuil_alerte_variation_pct', '20') ?? 20);
$blocage   = (int)(param('seuil_blocage_variation_pct', '25') ?? 25);

$consommeTotal = 0.0;
foreach ($consomme as $c) {
    $consommeTotal += $c['montant'];
}

$ongletActif = 'arbre';
page_start('Budget', 'budget');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">Nomenclature budgétaire <small class="text-muted fw-normal">· <?= e(projet_intitule()) ?></small></h1>
    <span class="text-muted small">Granularité du contrôle de variation :
        <?= param('granularite_variation', 'rubrique') === 'ligne' ? 'ligne budgétaire' : 'rubrique principale' ?></span>
</div>

<?php if ($manque): ?>
<div class="alert alert-warning">
    <i class="bi bi-pencil-square"></i> <strong>Détail du contrat incomplet.</strong>
    Le budget approuvé ne communique que les sous-totaux de rubrique pour
    <?= e(implode(', ', array_map(fn($c, $m) => $c . ' (' . htg($m['ecart']) . ' non ventilés)', array_keys($manque), $manque))) ?>.
    Tant que le détail n'est pas saisi, la consommation ne peut pas se contrôler ligne par ligne.
    <?php if (user_role() === 'coordinateur'): ?>
    <a href="<?= e(base_path('modules/budget/nomenclature.php')) ?>">Saisir le détail</a>.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($variations as $g):
    if ($g['variation_pct'] === null || $g['variation_pct'] < $alerte) continue; ?>
<div class="alert <?= $g['variation_pct'] >= $blocage ? 'alert-danger' : 'alert-warning' ?> py-2">
    <i class="bi bi-graph-up-arrow"></i>
    <strong><?= e($g['libelle']) ?></strong> — variation de <?= number_format($g['variation_pct'], 2, ',', ' ') ?> %
    entre le budget de gestion (<?= e(htg($g['gestion'])) ?>) et le budget contractuel (<?= e(htg($g['contractuel'])) ?>).
    <?= $g['variation_pct'] >= $blocage
        ? 'Au-delà du seuil de blocage : toute nouvelle réallocation exige une autorisation écrite téléversée.'
        : 'Seuil d\'alerte franchi.' ?>
</div>
<?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Budget de gestion</div>
        <div class="valeur fs-5"><?= e(htg($totalGestion)) ?></div>
        <small class="text-muted"><?= $plafond === null ? 'plafond contractuel à saisir'
            : 'plafond ' . e(htg($plafond)) . ' · marge ' . e(htg($plafond - $totalGestion)) ?></small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Consommé</div>
        <div class="valeur fs-5"><?= e(htg($consommeTotal)) ?></div>
        <small class="text-muted"><?= $totalGestion > 0 ? number_format($consommeTotal / $totalGestion * 100, 1, ',', ' ') . ' % du budget de gestion' : '—' ?></small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Coûts indirects constatés</div>
        <div class="valeur fs-5"><?= e(htg($indirects['enveloppe'])) ?></div>
        <small class="text-muted"><?= number_format($indirects['taux'] * 100, 2, ',', ' ') ?> % des coûts directs constatés<?php
            if ($indirects['enveloppe_contractuelle'] !== null): ?> · enveloppe <?= e(htg($indirects['enveloppe_contractuelle'])) ?><?php endif; ?></small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Part des ressources humaines</div>
        <div class="valeur fs-5"><?= $partRh === null ? '—' : number_format($partRh, 2, ',', ' ') . ' %' ?></div>
        <small class="text-muted">indicateur, sans seuil ni alerte</small>
    </div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-list-nested"></i> Lignes budgétaires</span>
        <span class="small fw-normal text-muted">Coûts directs contractuels <?= e(htg($directs)) ?></span>
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead>
            <tr class="small text-muted">
                <th style="min-width:22rem">Ligne</th>
                <th class="text-end">Unité</th>
                <th class="text-end">Qté</th>
                <th class="text-end">Contractuel</th>
                <th class="text-end">Gestion</th>
                <th class="text-end">Consommé</th>
                <th class="text-end">Solde</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lignes as $code => $l):
            $imputable = $l['nature'] === 'imputable';
            $c = $consomme[(int)$l['id']] ?? ['montant' => 0.0, 'quantite' => 0.0];
            $gestion = $l['montant_gestion'] === null ? null : (float)$l['montant_gestion'];
            $solde = $gestion === null ? null : round($gestion - $c['montant'], 2);
            $estProvision = $provision !== null && (int)$provision['id'] === (int)$l['id'];
        ?>
            <tr class="<?= $imputable ? '' : 'fw-semibold' ?>">
                <td style="padding-left:<?= (int)$l['niveau'] * 0.9 ?>rem">
                    <span class="text-muted small me-2"><?= e($code) ?></span><?= e($l['libelle']) ?>
                    <?php if ($estProvision): ?><span class="badge text-bg-light border" title="Mobilisation sur autorisation">provision</span><?php endif; ?>
                    <?php if ($l['nature'] === 'calculee'): ?><span class="badge text-bg-light border">calculée</span><?php endif; ?>
                </td>
                <td class="text-end text-muted small"><?= $l['unite'] ? e(UNITES[$l['unite']] ?? $l['unite']) : '' ?></td>
                <td class="text-end text-muted small"><?= $l['quantite'] === null ? '' : e(rtrim(rtrim(number_format((float)$l['quantite'], 2, ',', ' '), '0'), ',')) ?></td>
                <td class="text-end"><?= $l['montant'] === null ? '<span class="text-muted">à saisir</span>' : e(htg((float)$l['montant'])) ?></td>
                <td class="text-end"><?= $gestion === null ? '' : e(htg($gestion)) ?>
                    <?php if ($gestion !== null && $l['montant'] !== null && abs($gestion - (float)$l['montant']) >= 0.01): ?>
                        <br><small class="text-muted"><?= $gestion > (float)$l['montant'] ? '+' : '' ?><?= e(htg($gestion - (float)$l['montant'])) ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= $imputable ? e(htg($c['montant'])) : '' ?>
                    <?php if ($imputable && $c['quantite'] > 0): ?>
                        <br><small class="text-muted"><?= e(rtrim(rtrim(number_format($c['quantite'], 2, ',', ' '), '0'), ',')) ?> <?= e(UNITES[$l['unite']] ?? '') ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= $imputable && $solde !== null ? e(htg($solde)) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php page_end();
