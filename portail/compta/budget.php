<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
check_role(['administrateur', 'coordinateur', 'comptable']);

$cfg = config();
$budgetTotal = (float)$cfg['app']['budget_total'];

// Consommation par ligne
$sql = "SELECT lb.id, lb.code, lb.libelle, lb.budget_initial_htg,
               COALESCE(SUM(d.montant_brut), 0) AS consomme
          FROM lignes_budgetaires lb
          LEFT JOIN imputations i ON i.ligne_budgetaire_id = lb.id AND i.statut = 'valide'
          LEFT JOIN decaissements d ON d.imputation_id = i.id
         GROUP BY lb.id, lb.code, lb.libelle, lb.budget_initial_htg
         ORDER BY lb.code";
$lignes = db()->query($sql)->fetchAll();

$totalInitial   = array_sum(array_column($lignes, 'budget_initial_htg'));
$totalConsomme  = array_sum(array_column($lignes, 'consomme'));
$totalRestant   = $totalInitial - $totalConsomme;
$pctGlobal      = $totalInitial > 0 ? ($totalConsomme / $totalInitial) * 100 : 0;

// Couleur barre
function pct_class(float $pct): string {
    if ($pct >= 90) return 'bg-danger';
    if ($pct >= 70) return 'bg-warning';
    return 'bg-success';
}

$pageTitle  = 'Suivi budgetaire';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-bar-chart-fill"></i> Suivi budgetaire</h1>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
            <strong>Budget total projet (PAIESC Annexe B)</strong>
            <span><?= format_htg($totalConsomme) ?> / <?= format_htg($totalInitial) ?></span>
        </div>
        <div class="progress" style="height: 1.5rem;">
            <div class="progress-bar <?= pct_class($pctGlobal) ?>" style="width:<?= number_format($pctGlobal,1) ?>%;">
                <?= number_format($pctGlobal,1) ?>%
            </div>
        </div>
        <small class="text-muted">Solde restant : <strong><?= format_htg($totalRestant) ?></strong></small>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>Detail par ligne budgetaire</strong></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr>
                <th>Code</th><th>Libelle</th>
                <th class="text-end">Budget initial</th>
                <th class="text-end">Consomme</th>
                <th class="text-end">Restant</th>
                <th style="min-width:180px;">% execution</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lignes as $l):
                $pct = (float)$l['budget_initial_htg'] > 0
                    ? ((float)$l['consomme'] / (float)$l['budget_initial_htg']) * 100
                    : 0;
                $restant = (float)$l['budget_initial_htg'] - (float)$l['consomme'];
            ?>
                <tr>
                    <td><strong><?= e($l['code']) ?></strong></td>
                    <td><?= e($l['libelle']) ?></td>
                    <td class="text-end font-monospace"><?= format_htg($l['budget_initial_htg']) ?></td>
                    <td class="text-end font-monospace"><?= format_htg($l['consomme']) ?></td>
                    <td class="text-end font-monospace <?= $restant < 0 ? 'text-danger' : '' ?>"><?= format_htg($restant) ?></td>
                    <td>
                        <div class="progress" style="height:1.25rem;">
                            <div class="progress-bar <?= pct_class($pct) ?>" style="width:<?= number_format(min(100,$pct),1) ?>%;">
                                <?= number_format($pct,1) ?>%
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Graphique Chart.js -->
<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-white"><strong>Consommation par ligne</strong></div>
    <div class="card-body">
        <canvas id="chartBudget" height="100"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('chartBudget').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($l) => $l['code'] . ' - ' . $l['libelle'], $lignes)) ?>,
        datasets: [
            {
                label: 'Budget initial',
                data: <?= json_encode(array_map(fn($l) => (float)$l['budget_initial_htg'], $lignes)) ?>,
                backgroundColor: '#1F4E79',
            },
            {
                label: 'Consomme',
                data: <?= json_encode(array_map(fn($l) => (float)$l['consomme'], $lignes)) ?>,
                backgroundColor: '#1A7A5E',
            }
        ]
    },
    options: {
        indexAxis: 'y',
        scales: { x: { beginAtZero: true } },
        plugins: { legend: { position: 'top' } }
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php';
