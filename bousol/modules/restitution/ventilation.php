<?php
declare(strict_types=1);

/**
 * Restitution - ventilation detaillee des depenses (CDC 6.5).
 *
 * Elle suit le formulaire de suivi des depenses transmis par le PAIESC : un journal
 * chronologique portant numero de piece, date, description, mode de paiement, ligne
 * budgetaire et montant, avec la feuille distincte de la petite caisse.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/restitution.php';
require_role(['coordinateur', 'raf']);
require_module('restitution');

$debut = (string)($_GET['debut'] ?? (date_debut() ?? date('Y-m-01')));
$fin   = (string)($_GET['fin'] ?? date('Y-m-t'));
$v = ventilation($debut, $fin);
$totalBanque = array_sum(array_map(fn($l) => (float)$l['montant'], $v['banque']));
$totalCaisse = array_sum(array_map(fn($l) => (float)$l['montant'], $v['caisse']));

$ongletActif = 'ventilation';
page_start('Ventilation détaillée', 'restitution');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Ventilation détaillée des dépenses</h1>
    <form method="get" class="d-flex gap-2">
        <input type="date" class="form-control form-control-sm" name="debut" value="<?= e($debut) ?>">
        <input type="date" class="form-control form-control-sm" name="fin" value="<?= e($fin) ?>">
        <button class="btn btn-sm btn-outline-secondary">Afficher</button>
    </form>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-bank"></i> Journal chronologique — banque</span>
        <span class="small fw-normal"><?= e(htg($totalBanque)) ?></span>
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr class="small text-muted">
            <th>N° pièce</th><th>Date</th><th>Description</th><th>Bénéficiaire</th>
            <th>Mode</th><th>Ligne</th><th class="text-end">Montant</th>
        </tr></thead>
        <tbody>
        <?php foreach ($v['banque'] as $l): ?>
        <tr>
            <td class="small"><?= e($l['numero_piece'] ?? '—') ?></td>
            <td class="small text-muted"><?= e(date_fr($l['date_imputation'])) ?></td>
            <td class="small"><?= e($l['objet']) ?><br><span class="text-muted"><?= e($l['dossier']) ?></span></td>
            <td class="small"><?= e($l['beneficiaire']) ?></td>
            <td class="small text-muted"><?= e(MODES_REGLEMENT[$l['mode']] ?? '—') ?></td>
            <td class="small text-muted"><?= e($l['ligne_code']) ?></td>
            <td class="text-end"><?= e(htg((float)$l['montant'], false)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$v['banque']): ?><tr><td colspan="7" class="text-muted p-3">Aucune dépense bancaire sur la période.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-cash-coin"></i> Feuille distincte — petite caisse</span>
        <span class="small fw-normal"><?= e(htg($totalCaisse)) ?></span>
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr class="small text-muted">
            <th>N° pièce</th><th>Date</th><th>Description</th><th>Bénéficiaire</th><th>Ligne</th><th class="text-end">Montant</th>
        </tr></thead>
        <tbody>
        <?php foreach ($v['caisse'] as $l): ?>
        <tr>
            <td class="small"><?= e($l['numero_piece'] ?? '—') ?></td>
            <td class="small text-muted"><?= e(date_fr($l['date_imputation'])) ?></td>
            <td class="small"><?= e($l['objet']) ?></td>
            <td class="small"><?= e($l['beneficiaire']) ?></td>
            <td class="small text-muted"><?= e($l['ligne_code']) ?></td>
            <td class="text-end"><?= e(htg((float)$l['montant'], false)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$v['caisse']): ?><tr><td colspan="6" class="text-muted p-3">Aucune dépense de petite caisse sur la période.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php page_end();
