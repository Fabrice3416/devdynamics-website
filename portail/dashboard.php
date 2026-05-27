<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$cfg = config();
$role = user_role();

// =============================================================
// Indicateurs (toutes les requetes sont resilientes - retournent
// 0 si la table est vide, jamais d'erreur fatale)
// =============================================================

// Budget global
$stmt = db()->query(
    "SELECT COALESCE(SUM(d.montant_brut), 0) AS consomme
       FROM decaissements d
       JOIN imputations i ON d.imputation_id = i.id
      WHERE i.statut = 'valide'"
);
$budgetConsomme = (float)$stmt->fetchColumn();
$budgetTotal    = (float)$cfg['app']['budget_total'];
$budgetRestant  = max(0.0, $budgetTotal - $budgetConsomme);
$budgetPct      = $budgetTotal > 0 ? ($budgetConsomme / $budgetTotal) * 100 : 0.0;

// Livrables
$stmt = db()->query("SELECT statut, COUNT(*) AS n FROM livrables GROUP BY statut");
$livrables = ['non_demarre' => 0, 'en_cours' => 0, 'livre' => 0, 'retard' => 0];
while ($row = $stmt->fetch()) {
    $livrables[$row['statut']] = (int)$row['n'];
}
$livrablesTotal = array_sum($livrables);

// Candidatures retenues
$stmt = db()->query("SELECT COUNT(*) FROM candidatures WHERE decision_finale = 'retenu'");
$candRetenus = (int)$stmt->fetchColumn();
$stmt = db()->query("SELECT COUNT(*) FROM candidatures WHERE decision_finale = 'retenu' AND genre = 'F'");
$candFemmes = (int)$stmt->fetchColumn();

// Dossiers en cours
$dossiers = [];
$dossiers['soumis']     = (int)db()->query("SELECT COUNT(*) FROM imputations WHERE statut='soumis'")->fetchColumn();
$dossiers['valides']    = (int)db()->query("SELECT COUNT(*) FROM imputations WHERE statut='valide'")->fetchColumn();
$dossiers['attente_f02'] = (int)db()->query(
    "SELECT COUNT(*) FROM imputations i LEFT JOIN decaissements d ON d.imputation_id=i.id WHERE i.statut='soumis' AND d.id IS NULL"
)->fetchColumn();

// Petite Caisse - solde temps reel
$stmt = db()->query(
    "SELECT COALESCE(SUM(montant), 0)
       FROM caisse_transactions
      WHERE renflouement_id IS NULL"
);
$caisseDepenses = (float)$stmt->fetchColumn();
$caisseSolde = $cfg['app']['caisse_fonds'] - $caisseDepenses;

// Partenaires
$stmt = db()->query("SELECT statut, COUNT(*) AS n FROM partenaires GROUP BY statut");
$partenaires = ['actif' => 0, 'partiel' => 0, 'inactif' => 0];
while ($row = $stmt->fetch()) {
    $partenaires[$row['statut']] = (int)$row['n'];
}

// Rapports recents
$stmt = db()->query(
    "SELECT id, numero, type_rapport, created_at, statut
       FROM rapports_generes
      ORDER BY created_at DESC LIMIT 5"
);
$rapports = $stmt->fetchAll();

// PDP vs Realise (mois en cours)
$moisCourant = (int)date('m');
$stmt = db()->prepare(
    "SELECT lb.code, lb.libelle,
            COALESCE(p.montant_previsionnel, 0) AS prevu,
            COALESCE((SELECT SUM(d.montant_brut) FROM decaissements d
                       JOIN imputations i ON d.imputation_id = i.id
                      WHERE i.ligne_budgetaire_id = lb.id
                        AND MONTH(i.date_depense) = ?
                        AND i.statut = 'valide'), 0) AS realise
       FROM lignes_budgetaires lb
       LEFT JOIN plan_decaissement p ON p.ligne_budgetaire_id = lb.id AND p.mois = ?
      ORDER BY lb.code"
);
$stmt->execute([$moisCourant, $moisCourant]);
$pdpVsRealise = $stmt->fetchAll();

// Budget par rubrique pour Chart.js
$stmt = db()->query(
    "SELECT i.rubrique, COALESCE(SUM(d.montant_brut),0) AS total
       FROM decaissements d
       JOIN imputations i ON d.imputation_id = i.id
      WHERE i.statut = 'valide'
      GROUP BY i.rubrique"
);
$budgetParRubrique = $stmt->fetchAll();

// Couleur de la barre budget
$budgetClass = 'bg-success';
if ($budgetPct >= 90) {
    $budgetClass = 'bg-danger';
} elseif ($budgetPct >= 70) {
    $budgetClass = 'bg-warning';
}

$pageTitle  = 'Tableau de bord';
$activeMenu = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Tableau de bord</h1>
        <small class="text-muted">Bienvenue <?= e(user_nom() ?? '') ?> &middot; <?= e(date('d/m/Y H:i')) ?></small>
    </div>
</div>

<!-- Budget global -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between flex-wrap mb-2">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-piggy-bank"></i> Budget global
                    </h5>
                    <span class="fw-bold">
                        <?= format_htg($budgetConsomme) ?> / <?= format_htg($budgetTotal) ?>
                    </span>
                </div>
                <div class="progress" style="height: 1.5rem;">
                    <div class="progress-bar <?= $budgetClass ?>" role="progressbar"
                         style="width: <?= number_format($budgetPct, 1) ?>%;"
                         aria-valuenow="<?= number_format($budgetPct, 1) ?>" aria-valuemin="0" aria-valuemax="100">
                        <?= number_format($budgetPct, 1) ?>%
                    </div>
                </div>
                <small class="text-muted">Solde restant : <strong><?= format_htg($budgetRestant) ?></strong></small>
            </div>
        </div>
    </div>
</div>

<!-- Cartes KPI -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">
                    <i class="bi bi-people-fill"></i> Candidatures
                </h6>
                <h3 class="mb-0"><?= $candRetenus ?> <small class="text-muted">/ 30</small></h3>
                <small class="<?= $candFemmes < 15 ? 'text-danger' : 'text-success' ?>">
                    <i class="bi bi-gender-female"></i> <?= $candFemmes ?> femmes
                    <?= $candFemmes < 15 ? '(min 15 requis)' : '' ?>
                </small>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">
                    <i class="bi bi-check2-square"></i> Livrables
                </h6>
                <h3 class="mb-0"><?= $livrables['livre'] ?> <small class="text-muted">/ <?= $livrablesTotal ?: 47 ?></small></h3>
                <small class="text-muted">
                    <?= $livrables['en_cours'] ?> en cours
                    <?php if ($livrables['retard'] > 0): ?>
                        &middot; <span class="text-danger"><?= $livrables['retard'] ?> en retard</span>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">
                    <i class="bi bi-cash-stack"></i> Petite Caisse
                </h6>
                <h3 class="mb-0 <?= $caisseSolde < $cfg['app']['caisse_seuil'] ? 'text-warning' : '' ?>">
                    <?= format_htg($caisseSolde) ?>
                </h3>
                <small class="text-muted">/ <?= format_htg($cfg['app']['caisse_fonds']) ?> (fonds)</small>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small mb-2">
                    <i class="bi bi-folder2-open"></i> Dossiers
                </h6>
                <h3 class="mb-0"><?= $dossiers['valides'] ?> <small class="text-muted">cloture(s)</small></h3>
                <small class="text-muted">
                    <?= $dossiers['attente_f02'] ?> attente F02
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Rapports recents -->
<?php if (in_array($role, ['administrateur', 'coordinateur'], true)): ?>
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <h6 class="mb-0">
                    <i class="bi bi-file-earmark-bar-graph"></i> Rapports recents
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (!$rapports): ?>
                    <p class="text-muted p-3 mb-0 small">Aucun rapport genere pour le moment.</p>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Numero</th><th>Type</th><th>Date</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach ($rapports as $r): ?>
                        <tr>
                            <td><?= e($r['numero']) ?></td>
                            <td><?= e(strtoupper($r['type_rapport'])) ?></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                            <td><span class="badge bg-secondary"><?= e($r['statut']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-people"></i> Partenaires (CPSI)</h6>
            </div>
            <div class="card-body">
                <p class="mb-1"><i class="bi bi-check-circle text-success"></i> Actifs : <strong><?= $partenaires['actif'] ?></strong></p>
                <p class="mb-1"><i class="bi bi-exclamation-circle text-warning"></i> Partiels : <strong><?= $partenaires['partiel'] ?></strong></p>
                <p class="mb-0"><i class="bi bi-x-circle text-muted"></i> Inactifs : <strong><?= $partenaires['inactif'] ?></strong></p>
            </div>
        </div>
    </div>
</div>

<!-- Graphiques -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-pie-chart"></i> Repartition consommee par rubrique</h6></div>
            <div class="card-body">
                <canvas id="chartRubriques" height="200"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-bar-chart"></i> Prevu vs Realise (<?= e(mois_fr($moisCourant)) ?>)</h6></div>
            <div class="card-body">
                <canvas id="chartPdp" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Rubriques pie
new Chart(document.getElementById('chartRubriques'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($r) => ucfirst(str_replace('_',' ',$r['rubrique'])), $budgetParRubrique)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $budgetParRubrique)) ?>,
            backgroundColor: ['#1F4E79','#1A7A5E','#dc3545','#ffc107','#6c757d','#0dcaf0']
        }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});

// PDP vs realise
new Chart(document.getElementById('chartPdp'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($l) => $l['code'], $pdpVsRealise)) ?>,
        datasets: [
            { label: 'Prevu', data: <?= json_encode(array_map(fn($l) => (float)$l['prevu'], $pdpVsRealise)) ?>, backgroundColor: '#1F4E79' },
            { label: 'Realise', data: <?= json_encode(array_map(fn($l) => (float)$l['realise'], $pdpVsRealise)) ?>, backgroundColor: '#1A7A5E' }
        ]
    },
    options: { scales: { y: { beginAtZero: true } } }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
