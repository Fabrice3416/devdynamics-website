<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
check_role(['administrateur', 'coordinateur']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_statut') {
    check_role(['administrateur', 'coordinateur']);
    try {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $statut = (string)($_POST['statut'] ?? '');
        $dateEff = (string)($_POST['date_livraison_effective'] ?? '');
        $obs = trim((string)($_POST['observations'] ?? ''));

        if (!in_whitelist($statut, ['non_demarre','en_cours','livre','retard'])) {
            flash_set('danger', 'Statut invalide.');
        } else {
            $stmt = db()->prepare(
                'UPDATE livrables SET statut=?, date_livraison_effective=?, observations=?, updated_by=? WHERE id=?'
            );
            $stmt->execute([
                $statut,
                $dateEff ?: null,
                $obs ?: null,
                (int)user_id(),
                $id
            ]);
            audit_log('upload_fichier', "Livrable #$id statut->$statut", 'livrables', $id);
            flash_set('success', 'Livrable mis a jour.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Erreur.');
    }
    redirect('/portail/admin/livrables.php');
}

$catFilter = (string)($_GET['categorie'] ?? '');
$statFilter = (string)($_GET['statut'] ?? '');
$where = []; $params = [];
if ($catFilter !== '')  { $where[] = 'categorie = ?'; $params[] = $catFilter; }
if ($statFilter !== '') { $where[] = 'statut = ?'; $params[] = $statFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("SELECT * FROM livrables $whereSql ORDER BY date_cible ASC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Statistiques globales
$statsByCat = db()->query(
    "SELECT categorie,
            COUNT(*) AS total,
            SUM(CASE WHEN statut='livre' THEN 1 ELSE 0 END) AS livres
       FROM livrables GROUP BY categorie"
)->fetchAll();

$totalLivrables = (int)db()->query("SELECT COUNT(*) FROM livrables")->fetchColumn();
$totalLivres    = (int)db()->query("SELECT COUNT(*) FROM livrables WHERE statut='livre'")->fetchColumn();
$pctGlobal = $totalLivrables > 0 ? ($totalLivres / $totalLivrables) * 100 : 0;

$pageTitle = 'Tracker des Livrables';
$activeMenu = 'admin';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-3"><i class="bi bi-check2-square"></i> Tracker des Livrables (<?= $totalLivres ?>/<?= $totalLivrables ?>)</h1>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
            <strong>Progression globale</strong>
            <span><?= $totalLivres ?> / <?= $totalLivrables ?> livres</span>
        </div>
        <div class="progress" style="height:1.5rem;">
            <div class="progress-bar bg-success" style="width:<?= number_format($pctGlobal,1) ?>%;"><?= number_format($pctGlobal,1) ?>%</div>
        </div>
        <div class="row g-2 mt-3">
            <?php foreach ($statsByCat as $cat):
                $pct = $cat['total'] > 0 ? ($cat['livres'] / $cat['total']) * 100 : 0;
            ?>
                <div class="col-md-4 col-lg-2">
                    <small class="text-muted"><?= e(ucfirst($cat['categorie'])) ?></small>
                    <div class="progress" style="height:0.5rem;">
                        <div class="progress-bar bg-success" style="width:<?= number_format($pct,0) ?>%;"></div>
                    </div>
                    <small><?= (int)$cat['livres'] ?> / <?= (int)$cat['total'] ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-2">
        <div class="col-md-3">
            <select name="categorie" class="form-select form-select-sm">
                <option value="">-- Toutes categories --</option>
                <?php foreach (['partenariats','formation','terrain','communication','rapports','scolaire'] as $c): ?>
                    <option value="<?= $c ?>" <?= ($catFilter===$c)?'selected':'' ?>><?= e(ucfirst($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="statut" class="form-select form-select-sm">
                <option value="">-- Tous statuts --</option>
                <?php foreach (['non_demarre','en_cours','livre','retard'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($statFilter===$s)?'selected':'' ?>><?= e(str_replace('_',' ',ucfirst($s))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1"><button class="btn btn-sm btn-secondary w-100"><i class="bi bi-search"></i></button></div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr>
                <th>Code</th><th>Categorie</th><th>Description</th><th>Responsable</th>
                <th>Cible</th><th>Statut</th><th>Livre le</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $retard = $r['statut'] !== 'livre' && strtotime($r['date_cible']) < time();
            ?>
                <tr class="<?= $retard ? 'table-warning' : '' ?>">
                    <td><strong><?= e($r['code']) ?></strong></td>
                    <td><small><?= e($r['categorie']) ?></small></td>
                    <td><small><?= e($r['description']) ?></small></td>
                    <td><small><?= e($r['responsable']) ?></small></td>
                    <td><small><?= e(date('d/m/Y', strtotime($r['date_cible']))) ?></small>
                        <?php if ($retard): ?><br><small class="text-danger">Retard</small><?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= ['non_demarre'=>'secondary','en_cours'=>'info','livre'=>'success','retard'=>'danger'][$r['statut']] ?>"><?= e(str_replace('_',' ',$r['statut'])) ?></span></td>
                    <td><small><?= $r['date_livraison_effective'] ? e(date('d/m/Y', strtotime($r['date_livraison_effective']))) : '-' ?></small></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#m<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></button>
                    </td>
                </tr>

                <!-- Modal de mise a jour -->
                <div class="modal fade" id="m<?= (int)$r['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_statut">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <div class="modal-header"><h5 class="modal-title">Livrable <?= e($r['code']) ?></h5></div>
                            <div class="modal-body">
                                <p class="text-muted small mb-3"><?= e($r['description']) ?></p>
                                <div class="mb-3">
                                    <label class="form-label">Statut</label>
                                    <select name="statut" class="form-select">
                                        <?php foreach (['non_demarre','en_cours','livre','retard'] as $s): ?>
                                            <option value="<?= $s ?>" <?= ($r['statut']===$s)?'selected':'' ?>><?= e(str_replace('_',' ',$s)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Date livraison effective</label>
                                    <input type="date" name="date_livraison_effective" class="form-control" value="<?= e($r['date_livraison_effective'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Observations</label>
                                    <textarea name="observations" class="form-control" rows="2"><?= e($r['observations'] ?? '') ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
