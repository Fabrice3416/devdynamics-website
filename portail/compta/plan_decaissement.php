<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
check_role(['administrateur', 'coordinateur']);

$cfg = config();
// 6 mois projet : M01 a M06
$MOIS_PROJET = [1, 2, 3, 4, 5, 6];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    check_role(['coordinateur']);
    try {
        verify_csrf();
        db()->beginTransaction();
        foreach ($_POST['cells'] ?? [] as $ligneId => $byMois) {
            foreach ($byMois as $mois => $val) {
                $val = (float)str_replace([',', ' '], ['.', ''], (string)$val);
                if ($val <= 0) {
                    // suppression si zero
                    $stmt = db()->prepare('DELETE FROM plan_decaissement WHERE ligne_budgetaire_id=? AND mois=?');
                    $stmt->execute([(int)$ligneId, (int)$mois]);
                    continue;
                }
                $stmt = db()->prepare(
                    'INSERT INTO plan_decaissement (ligne_budgetaire_id, mois, montant_previsionnel, created_by)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE montant_previsionnel = VALUES(montant_previsionnel), updated_at = NOW()'
                );
                $stmt->execute([(int)$ligneId, (int)$mois, $val, (int)user_id()]);
            }
        }
        audit_log('pdp_modifie', 'Plan de Decaissement Previsionnel mis a jour');
        db()->commit();
        flash_set('success', 'Plan de Decaissement enregistre.');
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('PDP save: ' . $e->getMessage());
        flash_set('danger', 'Erreur enregistrement.');
    }
    redirect('/portail/compta/plan_decaissement.php');
}

// Charge le PDP courant
$stmt = db()->query('SELECT * FROM plan_decaissement');
$pdpRaw = $stmt->fetchAll();
$pdp = []; // [ligne_id][mois] = montant
foreach ($pdpRaw as $r) {
    $pdp[(int)$r['ligne_budgetaire_id']][(int)$r['mois']] = (float)$r['montant_previsionnel'];
}

// Realise par ligne x mois (somme decaissements valides)
$stmt = db()->query(
    "SELECT i.ligne_budgetaire_id, MONTH(i.date_depense) AS mois, SUM(d.montant_brut) AS realise
       FROM imputations i
       JOIN decaissements d ON d.imputation_id = i.id
      WHERE i.statut = 'valide'
      GROUP BY i.ligne_budgetaire_id, MONTH(i.date_depense)"
);
$realise = [];
while ($r = $stmt->fetch()) {
    $realise[(int)$r['ligne_budgetaire_id']][(int)$r['mois']] = (float)$r['realise'];
}

$stmt = db()->query('SELECT id, code, libelle FROM lignes_budgetaires WHERE actif=1 ORDER BY code');
$lignes = $stmt->fetchAll();

$pageTitle  = 'Plan de Decaissement Previsionnel';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-table"></i> Plan de Decaissement Previsionnel (PDP)</h1>
    <small class="text-muted">M01 a M06 du projet</small>
</div>

<form method="post" class="card shadow-sm border-0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" style="min-width:240px;">Ligne budgetaire</th>
                    <?php foreach ($MOIS_PROJET as $m): ?>
                        <th class="text-center" colspan="2">M<?= str_pad((string)$m,2,'0',STR_PAD_LEFT) ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($MOIS_PROJET as $m): ?>
                        <th class="text-center small text-muted">Prevu</th>
                        <th class="text-center small text-muted">Realise</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lignes as $lb):
                $lid = (int)$lb['id']; ?>
                <tr>
                    <td>
                        <strong><?= e($lb['code']) ?></strong> - <small><?= e($lb['libelle']) ?></small>
                    </td>
                    <?php foreach ($MOIS_PROJET as $m):
                        $prevu = $pdp[$lid][$m] ?? 0;
                        $real  = $realise[$lid][$m] ?? 0;
                        $ecart = $prevu > 0 ? (($real - $prevu) / $prevu) * 100 : null;
                    ?>
                        <td>
                            <input type="number" name="cells[<?= $lid ?>][<?= $m ?>]" class="form-control form-control-sm text-end"
                                   step="0.01" min="0" value="<?= $prevu > 0 ? e((string)$prevu) : '' ?>"
                                   <?= user_role() !== 'coordinateur' ? 'readonly' : '' ?>>
                        </td>
                        <td class="text-end font-monospace <?= $ecart !== null && abs($ecart) > 20 ? 'text-danger' : '' ?>">
                            <small><?= $real > 0 ? format_htg($real) : '-' ?></small>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if (user_role() === 'coordinateur'): ?>
    <div class="card-footer bg-white d-flex justify-content-between">
        <small class="text-muted">Modifie ? Toute mise a jour est horodatee dans audit_log.</small>
        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer le PDP</button>
    </div>
    <?php endif; ?>
</form>

<?php require __DIR__ . '/../includes/footer.php';
