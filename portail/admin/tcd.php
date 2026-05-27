<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
check_role(['administrateur', 'coordinateur']);

$action = (string)($_GET['action'] ?? 'list');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $contratId = (int)($_POST['contrat_id'] ?? 0);
        $date = (string)($_POST['date_comparaison'] ?? '');
        $prestaRetenu = trim((string)($_POST['prestataire_retenu'] ?? ''));
        $motif = trim((string)($_POST['motif_choix'] ?? ''));

        $stmt = db()->prepare('SELECT id, type_contrat FROM contrats WHERE id = ?');
        $stmt->execute([$contratId]);
        $contrat = $stmt->fetch();
        if (!$contrat) $errors[] = 'Contrat introuvable.';

        if ($prestaRetenu === '') $errors[] = 'Prestataire retenu obligatoire.';
        if ($motif === '') $errors[] = 'Motif du choix obligatoire.';

        $devis = [];
        for ($i = 1; $i <= 3; $i++) {
            $nom = trim((string)($_POST['devis_' . $i . '_prestataire'] ?? ''));
            $mt  = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['devis_' . $i . '_montant'] ?? '0'));
            if ($nom === '' || $mt <= 0) {
                $errors[] = "Devis $i : nom et montant obligatoires.";
                continue;
            }
            $scanPath = null;
            if (isset($_FILES['devis_' . $i . '_scan']) && $_FILES['devis_' . $i . '_scan']['error'] !== UPLOAD_ERR_NO_FILE) {
                $up = handle_upload($_FILES['devis_' . $i . '_scan'], __DIR__ . '/../storage/devis/TCD-' . date('YmdHis'));
                if (!$up['success']) {
                    $errors[] = "Devis $i scan : " . ($up['error'] ?? 'echec');
                } else {
                    $scanPath = storage_relative_path($up['path']);
                }
            } else {
                $errors[] = "Devis $i : scan obligatoire.";
            }
            $devis[$i] = ['nom' => $nom, 'mt' => $mt, 'scan' => $scanPath];
        }

        if (!$errors) {
            $numero = generate_numero('TCD', 'tcd_devis');
            $stmt = db()->prepare(
                'INSERT INTO tcd_devis (numero, contrat_id, date_comparaison, prestataire_retenu, motif_choix,
                    devis_1_prestataire, devis_1_montant, devis_1_scan,
                    devis_2_prestataire, devis_2_montant, devis_2_scan,
                    devis_3_prestataire, devis_3_montant, devis_3_scan)
                 VALUES (?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?)'
            );
            $stmt->execute([
                $numero, $contratId, $date, $prestaRetenu, $motif,
                $devis[1]['nom'], $devis[1]['mt'], $devis[1]['scan'],
                $devis[2]['nom'], $devis[2]['mt'], $devis[2]['scan'],
                $devis[3]['nom'], $devis[3]['mt'], $devis[3]['scan'],
            ]);
            $newId = (int)db()->lastInsertId();
            audit_log('contrat_cree', "TCD $numero cree (contrat #$contratId)", 'tcd_devis', $newId);
            flash_set('success', "TCD $numero cree. En attente de validation Coordinateur.");
            redirect('/portail/admin/tcd.php?id=' . $newId);
        }
    } catch (Throwable $e) {
        error_log('TCD create: ' . $e->getMessage());
        $errors[] = 'Erreur technique.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    check_role(['coordinateur']);
    try {
        verify_csrf();
        $tid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE tcd_devis SET valide_coordinateur=1, valide_par=? WHERE id=?");
        $stmt->execute([(int)user_id(), $tid]);
        audit_log('contrat_cree', "TCD #$tid valide par Coordinateur", 'tcd_devis', $tid);
        flash_set('success', 'TCD valide. Les F01 lies a ce contrat peuvent maintenant etre soumis.');
    } catch (Throwable $e) { flash_set('danger', 'Erreur.'); }
    redirect('/portail/admin/tcd.php?id=' . ($tid ?? 0));
}

$pageTitle = 'TCD - Tableau Comparatif Devis';
$activeMenu = 'admin';

if ($id > 0) {
    $stmt = db()->prepare(
        'SELECT t.*, c.numero AS contrat_numero, c.type_contrat,
                u.nom_complet AS valide_par_nom
           FROM tcd_devis t
           JOIN contrats c ON t.contrat_id = c.id
           LEFT JOIN users u ON t.valide_par = u.id
          WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $tcd = $stmt->fetch();
    if (!$tcd) { flash_set('danger','Introuvable.'); redirect('/portail/admin/tcd.php'); }

    require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">TCD <?= e($tcd['numero']) ?>
        <?php if ((int)$tcd['valide_coordinateur']===1): ?><span class="badge bg-success">Valide</span><?php else: ?><span class="badge bg-warning">Attente Coord</span><?php endif; ?>
    </h1>
    <a href="/portail/admin/tcd.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Contrat lie</dt><dd class="col-sm-9"><?= e($tcd['contrat_numero']) ?> (<?= e($tcd['type_contrat']) ?>)</dd>
            <dt class="col-sm-3">Date comparaison</dt><dd class="col-sm-9"><?= e(date('d/m/Y', strtotime($tcd['date_comparaison']))) ?></dd>
            <dt class="col-sm-3">Prestataire retenu</dt><dd class="col-sm-9"><strong><?= e($tcd['prestataire_retenu']) ?></strong></dd>
            <dt class="col-sm-3">Motif du choix</dt><dd class="col-sm-9"><?= nl2br(e($tcd['motif_choix'])) ?></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white"><strong>3 devis compares</strong></div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead class="table-light"><tr><th>Prestataire</th><th class="text-end">Montant</th><th>Scan devis</th></tr></thead>
            <tbody>
            <?php for ($i = 1; $i <= 3; $i++):
                $isRetenu = $tcd['prestataire_retenu'] === $tcd["devis_{$i}_prestataire"];
            ?>
                <tr class="<?= $isRetenu ? 'table-success' : '' ?>">
                    <td><?= e($tcd["devis_{$i}_prestataire"]) ?>
                        <?php if ($isRetenu): ?><span class="badge bg-success">retenu</span><?php endif; ?>
                    </td>
                    <td class="text-end font-monospace"><?= format_htg($tcd["devis_{$i}_montant"]) ?></td>
                    <td>
                        <?php if ($tcd["devis_{$i}_scan"]): ?>
                        <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$tcd["devis_{$i}_scan"])) ?>&type=pdf" target="_blank">Voir scan</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ((int)$tcd['valide_coordinateur']===0 && user_role()==='coordinateur'): ?>
<form method="post" class="d-inline" onsubmit="return confirm('Valider definitivement ce TCD ?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="validate">
    <input type="hidden" name="id" value="<?= (int)$tcd['id'] ?>">
    <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Valider le TCD</button>
</form>
<?php elseif ((int)$tcd['valide_coordinateur']===1): ?>
<div class="alert alert-success">
    Valide par <strong><?= e($tcd['valide_par_nom']) ?></strong> - les F01 lies au contrat <?= e($tcd['contrat_numero']) ?> peuvent etre soumis.
</div>
<?php endif; ?>
<?php
    require __DIR__ . '/../includes/footer.php';

} elseif ($action === 'new') {
    check_role(['administrateur']);
    $stmt = db()->query("SELECT id, numero, type_contrat FROM contrats WHERE type_contrat IN ('CASI','CPSP') AND statut='actif' ORDER BY numero");
    $contrats = $stmt->fetchAll();
    $contratPre = (int)($_GET['contrat_id'] ?? 0);

    require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Nouveau TCD</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Contrat lie (CASI/CPSP) <span class="text-danger">*</span></label>
                <select name="contrat_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($contrats as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($contratPre === (int)$c['id'])?'selected':'' ?>><?= e($c['numero']) ?> (<?= e($c['type_contrat']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" name="date_comparaison" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Prestataire retenu <span class="text-danger">*</span></label>
                <input type="text" name="prestataire_retenu" class="form-control" required maxlength="100">
            </div>
            <div class="col-12">
                <label class="form-label">Motif du choix <span class="text-danger">*</span></label>
                <textarea name="motif_choix" class="form-control" rows="2" required></textarea>
            </div>
        </div>

        <hr>
        <h6>3 devis compares</h6>
        <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="row g-2 mb-3 pb-3 border-bottom">
            <div class="col-md-5">
                <label class="form-label small">Devis <?= $i ?> - Prestataire <span class="text-danger">*</span></label>
                <input type="text" name="devis_<?= $i ?>_prestataire" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Montant <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="number" name="devis_<?= $i ?>_montant" class="form-control text-end" step="0.01" min="0.01" required>
                    <span class="input-group-text">HTG</span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Scan PDF/JPG/PNG <span class="text-danger">*</span></label>
                <input type="file" name="devis_<?= $i ?>_scan" class="form-control" accept="application/pdf,image/jpeg,image/png" required>
            </div>
        </div>
        <?php endfor; ?>

        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer le TCD</button>
    </div>
</form>
<?php
    require __DIR__ . '/../includes/footer.php';
} else {
    $stmt = db()->query(
        "SELECT t.*, c.numero AS contrat_numero, c.type_contrat
           FROM tcd_devis t
           JOIN contrats c ON t.contrat_id = c.id
          ORDER BY t.id DESC"
    );
    $rows = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-table"></i> TCD - Tableau Comparatif Devis</h1>
    <?php if (user_role()==='administrateur'): ?>
    <a href="?action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau TCD</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr>
                <th>TCD</th><th>Contrat</th><th>Date</th><th>Prestataire retenu</th><th>Statut</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucun TCD.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?id=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= e($r['numero']) ?></a></td>
                    <td><?= e($r['contrat_numero']) ?> <span class="badge bg-light text-dark"><?= e($r['type_contrat']) ?></span></td>
                    <td><?= e(date('d/m/Y', strtotime($r['date_comparaison']))) ?></td>
                    <td><?= e($r['prestataire_retenu']) ?></td>
                    <td>
                        <?php if ((int)$r['valide_coordinateur']===1): ?>
                            <span class="badge bg-success">Valide</span>
                        <?php else: ?>
                            <span class="badge bg-warning">Attente Coord</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
    require __DIR__ . '/../includes/footer.php';
}
