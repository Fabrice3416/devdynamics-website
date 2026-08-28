<?php
declare(strict_types=1);

/**
 * Comptes - plan de comptes et balance.
 *
 * Six familles suffisent a couvrir la totalite des operations (CDC 4.8). Le solde
 * est presente dans son sens naturel : debiteur pour la tresorerie, les charges et
 * les avances, crediteur pour les tiers, la dette et les financements, de sorte
 * qu'un solde positif ait partout le meme sens.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_projet();
require_module('comptes');

$lignes = balance();
$totaux = ['debit' => 0.0, 'credit' => 0.0];
$parFamille = [];
foreach ($lignes as $l) {
    $totaux['debit']  += $l['debit'];
    $totaux['credit'] += $l['credit'];
    $parFamille[$l['type']][] = $l;
}
$equilibre = abs(round($totaux['debit'] - $totaux['credit'], 2)) < 0.01;

$tresorerie = 0.0;
foreach ($lignes as $l) {
    if (in_array($l['type'], COMPTES_TRESORERIE, true)) {
        $tresorerie += $l['solde'];
    }
}

$ongletActif = 'plan';
page_start('Comptes', 'comptes');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Plan de comptes et balance</h1>
    <span class="text-muted small">Partie double allégée · <?= count($lignes) ?> comptes</span>
</div>

<?php if (!$equilibre): ?>
<div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i>
    <strong>Balance déséquilibrée.</strong> <?= e(htg($totaux['debit'])) ?> au débit pour
    <?= e(htg($totaux['credit'])) ?> au crédit. Aucune écriture ne peut produire cet écart :
    il vient d'une intervention hors application.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Trésorerie</div>
        <div class="valeur fs-5"><?= e(htg($tresorerie)) ?></div>
        <small class="text-muted">banque et petite caisse</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Total des débits</div>
        <div class="valeur fs-5"><?= e(htg($totaux['debit'])) ?></div>
        <small class="text-muted"><?= $equilibre ? 'balance équilibrée' : 'écart à expliquer' ?></small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Dette envers la DGI</div>
        <?php $dgi = compte_par_code('DGI'); ?>
        <div class="valeur fs-5"><?= e(htg($dgi ? solde_compte((int)$dgi['id']) * -1 : 0)) ?></div>
        <small class="text-muted">acomptes retenus, non encore versés</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Écritures</div>
        <?php $ne = db()->prepare('SELECT COUNT(*) FROM ecritures WHERE projet_id = ?'); $ne->execute([projet_id()]); ?>
        <div class="valeur fs-5"><?= (int)$ne->fetchColumn() ?></div>
        <small class="text-muted"><a href="<?= e(base_path('modules/comptes/journal.php')) ?>">voir le journal</a></small>
    </div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-bank"></i> Balance</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th style="min-width:20rem">Compte</th><th>Ligne budgétaire</th>
            <th class="text-end">Débit</th><th class="text-end">Crédit</th><th class="text-end">Solde</th>
        </tr></thead>
        <tbody>
        <?php foreach (FAMILLES_COMPTES as $type => $libFamille):
            if (empty($parFamille[$type])) continue; ?>
            <tr class="fw-semibold"><td colspan="5"><?= e($libFamille) ?></td></tr>
            <?php foreach ($parFamille[$type] as $c): ?>
            <tr>
                <td style="padding-left:1.5rem"><span class="text-muted small me-2"><?= e($c['code']) ?></span><?= e($c['libelle']) ?></td>
                <td class="small text-muted"><?= $c['ligne_code'] ? e($c['ligne_code']) : '' ?></td>
                <td class="text-end text-muted"><?= $c['debit'] > 0 ? e(htg($c['debit'])) : '' ?></td>
                <td class="text-end text-muted"><?= $c['credit'] > 0 ? e(htg($c['credit'])) : '' ?></td>
                <td class="text-end"><?= $c['solde'] != 0.0 ? e(htg($c['solde'])) : '<span class="text-muted">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-semibold">
            <td colspan="2">Total</td>
            <td class="text-end"><?= e(htg($totaux['debit'])) ?></td>
            <td class="text-end"><?= e(htg($totaux['credit'])) ?></td>
            <td></td>
        </tr></tfoot>
    </table>
    </div>
</div>
<?php page_end();
