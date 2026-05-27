<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
check_role(['administrateur', 'coordinateur', 'comptable']);

$filters = [
    'mois'     => (int)($_GET['mois'] ?? date('m')),
    'annee'    => (int)($_GET['annee'] ?? date('Y')),
    'rubrique' => (string)($_GET['rubrique'] ?? ''),
];

$where = ['MONTH(t.date_depense) = ?', 'YEAR(t.date_depense) = ?'];
$params = [$filters['mois'], $filters['annee']];
if ($filters['rubrique'] !== '') {
    $where[] = 't.rubrique = ?'; $params[] = $filters['rubrique'];
}

$sql = "SELECT t.*, lb.code AS ligne_code, lb.libelle AS ligne_libelle,
               r.numero AS renflouement_numero
          FROM caisse_transactions t
          JOIN lignes_budgetaires lb ON t.ligne_budgetaire_id = lb.id
          LEFT JOIN caisse_renflouements r ON t.renflouement_id = r.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY t.date_depense DESC, t.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = array_sum(array_column($rows, 'montant'));

$pageTitle = 'Journal Petite Caisse';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-journal"></i> Journal Petite Caisse</h1>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-2">
        <div class="col-md-3">
            <select name="mois" class="form-select form-select-sm">
                <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= ($filters['mois']===$m)?'selected':'' ?>><?= e(mois_fr($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2"><input type="number" name="annee" class="form-control form-control-sm" value="<?= e((string)$filters['annee']) ?>"></div>
        <div class="col-md-3">
            <select name="rubrique" class="form-select form-select-sm">
                <option value="">-- Rubrique --</option>
                <?php foreach(['personnel','achats_services','terrain','communication','autre'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($filters['rubrique']===$r)?'selected':'' ?>><?= e(ucfirst(str_replace('_',' ',$r))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-secondary w-100"><i class="bi bi-search"></i></button></div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr>
                <th>N°</th><th>Date</th><th>Description</th><th>Rubrique</th><th>Ligne</th>
                <th class="text-end">Montant</th><th>N° recu</th><th>Statut</th><th>Renflouement</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">Aucune transaction.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><small><?= e($t['numero']) ?></small></td>
                    <td><small><?= e(date('d/m/Y', strtotime($t['date_depense']))) ?></small></td>
                    <td><small><?= e($t['description']) ?></small></td>
                    <td><small><?= e($t['rubrique']) ?></small></td>
                    <td><small><?= e($t['ligne_code']) ?></small></td>
                    <td class="text-end font-monospace"><?= format_htg($t['montant']) ?></td>
                    <td><small><?= e($t['numero_recu']) ?></small></td>
                    <td><small><?= (int)$t['valide_administrateur']===1 ? '<span class="badge bg-success">Validee</span>' : '<span class="badge bg-warning">Attente</span>' ?></small></td>
                    <td><small><?= e($t['renflouement_numero'] ?? '-') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold"><td colspan="5">Total (<?= count($rows) ?>)</td><td class="text-end font-monospace"><?= format_htg($total) ?></td><td colspan="3"></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
