<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
check_role(['administrateur', 'coordinateur', 'comptable']);

$filters = [
    'mois'      => (int)($_GET['mois'] ?? date('m')),
    'annee'     => (int)($_GET['annee'] ?? date('Y')),
    'rubrique'  => (string)($_GET['rubrique'] ?? ''),
    'type_contrat' => (string)($_GET['type_contrat'] ?? ''),
    'mode'      => (string)($_GET['mode'] ?? ''),
    'statut'    => (string)($_GET['statut'] ?? ''),
];

$where = ["MONTH(j.date_depense) = ?", "YEAR(j.date_depense) = ?"];
$params = [$filters['mois'], $filters['annee']];

if ($filters['rubrique'] !== '')      { $where[] = 'j.rubrique = ?';      $params[] = $filters['rubrique']; }
if ($filters['type_contrat'] !== '')  { $where[] = 'j.type_contrat = ?';  $params[] = $filters['type_contrat']; }
if ($filters['mode'] !== '')          { $where[] = 'j.mode_paiement = ?'; $params[] = $filters['mode']; }
if ($filters['statut'] !== '')        {
    if ($filters['statut'] === 'cloture')  { $where[] = 'j.date_cloture IS NOT NULL'; }
    elseif ($filters['statut'] === 'en_cours') { $where[] = 'j.date_cloture IS NULL'; }
}

$whereSql = implode(' AND ', $where);
$sql = "SELECT j.* FROM journal_depenses j WHERE $whereSql ORDER BY j.date_depense DESC LIMIT 1000";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Totaux
$totalBrut = 0; $totalDgi = 0; $totalNet = 0; $totalAlloc = 0;
$nbCloture = 0; $nbEnCours = 0;
foreach ($rows as $r) {
    $totalBrut += (float)$r['montant_brut'];
    $totalDgi  += (float)$r['dgi_2pct'];
    $totalNet  += (float)$r['total_net_a_verser'];
    $totalAlloc += (float)($r['montant_allocation'] ?? 0);
    if ($r['date_cloture']) $nbCloture++; else $nbEnCours++;
}

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="journal_' . $filters['annee'] . '_M' . str_pad((string)$filters['mois'],2,'0',STR_PAD_LEFT) . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Numero','Date','Rubrique','Ligne','Description','Beneficiaire','Contrat','Type','Montant brut','DGI 2%','Net','Allocation','Total net','Mode','N cheque','Cloture'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['numero_ecriture'], $r['date_depense'], $r['rubrique'], $r['ligne_budgetaire'],
            $r['description'], $r['beneficiaire'], $r['contrat'], $r['type_contrat'],
            $r['montant_brut'], $r['dgi_2pct'], $r['net_honoraires'], $r['montant_allocation'],
            $r['total_net_a_verser'], $r['mode_paiement'], $r['numero_cheque'],
            $r['date_cloture'] ?: '',
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Journal des depenses';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-journal-text"></i> Journal des depenses</h1>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-sm btn-outline-success">
        <i class="bi bi-filetype-csv"></i> Export CSV
    </a>
</div>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-2">
        <div class="col-md-2">
            <select name="mois" class="form-select form-select-sm">
                <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= ($filters['mois']===$m)?'selected':'' ?>><?= e(mois_fr($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-1">
            <input type="number" name="annee" class="form-control form-control-sm" value="<?= e((string)$filters['annee']) ?>" min="2020" max="2030">
        </div>
        <div class="col-md-2">
            <select name="rubrique" class="form-select form-select-sm">
                <option value="">-- Rubrique --</option>
                <?php foreach (['personnel','achats_services','terrain','communication','autre'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($filters['rubrique']===$r)?'selected':'' ?>><?= e(ucfirst(str_replace('_',' ',$r))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="type_contrat" class="form-select form-select-sm">
                <option value="">-- Type contrat --</option>
                <?php foreach (['CPS','CPSP','CASI','CPSI'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($filters['type_contrat']===$t)?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="mode" class="form-select form-select-sm">
                <option value="">-- Mode paiement --</option>
                <option value="cheque" <?= ($filters['mode']==='cheque')?'selected':'' ?>>Cheque</option>
                <option value="virement" <?= ($filters['mode']==='virement')?'selected':'' ?>>Virement</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="statut" class="form-select form-select-sm">
                <option value="">-- Statut --</option>
                <option value="cloture" <?= ($filters['statut']==='cloture')?'selected':'' ?>>Cloture</option>
                <option value="en_cours" <?= ($filters['statut']==='en_cours')?'selected':'' ?>>En cours</option>
            </select>
        </div>
        <div class="col-md-1">
            <button class="btn btn-sm btn-secondary w-100"><i class="bi bi-search"></i></button>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr>
                <th>N° ecriture</th><th>Date</th><th>Beneficiaire</th><th>Contrat</th><th>Rubrique</th>
                <th class="text-end">Brut</th><th class="text-end">DGI 2%</th><th class="text-end">Total net</th>
                <th>Mode</th><th>N° cheque</th><th>Cloture</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Aucune ecriture pour cette periode.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><small><?= e($r['numero_ecriture']) ?></small></td>
                    <td><small><?= e(date('d/m/Y', strtotime($r['date_depense']))) ?></small></td>
                    <td><small><?= e($r['beneficiaire']) ?></small></td>
                    <td><small><?= e($r['contrat']) ?> <span class="badge bg-light text-dark"><?= e($r['type_contrat']) ?></span></small></td>
                    <td><small><?= e($r['rubrique']) ?></small></td>
                    <td class="text-end font-monospace"><small><?= format_htg($r['montant_brut']) ?></small></td>
                    <td class="text-end font-monospace text-danger"><small><?= format_htg($r['dgi_2pct']) ?></small></td>
                    <td class="text-end font-monospace"><small><?= format_htg($r['total_net_a_verser']) ?></small></td>
                    <td><small><span class="badge bg-<?= $r['mode_paiement']==='cheque'?'success':'warning' ?>"><?= e($r['mode_paiement']) ?></span></small></td>
                    <td><small class="font-monospace"><?= e($r['numero_cheque'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($r['date_cloture']): ?>
                            <small class="badge bg-success">Cloture</small>
                        <?php else: ?>
                            <small class="badge bg-warning">En cours</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td colspan="5">TOTAUX (<?= count($rows) ?> ecritures, <?= $nbCloture ?> cloturees / <?= $nbEnCours ?> en cours)</td>
                    <td class="text-end font-monospace"><?= format_htg($totalBrut) ?></td>
                    <td class="text-end font-monospace text-danger"><?= format_htg($totalDgi) ?></td>
                    <td class="text-end font-monospace"><?= format_htg($totalNet) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
