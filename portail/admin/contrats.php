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
        $numero = trim((string)($_POST['numero'] ?? ''));
        $type   = (string)($_POST['type_contrat'] ?? '');
        $prestaId = (int)($_POST['prestataire_id'] ?? 0);
        $debut  = (string)($_POST['date_debut'] ?? '');
        $fin    = (string)($_POST['date_fin'] ?? '');
        $montant = $_POST['montant_mensuel'] !== '' ? (float)str_replace([',', ' '], ['.', ''], (string)$_POST['montant_mensuel']) : null;
        $isCps01 = isset($_POST['is_cps01']) ? 1 : 0;

        if ($numero === '')               $errors[] = 'Numero obligatoire.';
        if (!in_whitelist($type, ['CPS','CPSP','CASI','CPSI'])) $errors[] = 'Type invalide.';
        if ($prestaId <= 0)               $errors[] = 'Prestataire obligatoire.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $errors[] = 'Date debut invalide.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) $errors[] = 'Date fin invalide.';
        if ($debut && $fin && strtotime($fin) <= strtotime($debut)) $errors[] = 'Date fin doit etre apres date debut.';
        if ($isCps01 && $type !== 'CPS') $errors[] = 'is_cps01 reserve aux contrats CPS.';

        $stmt = db()->prepare('SELECT id FROM contrats WHERE numero = ?');
        $stmt->execute([$numero]);
        if ($stmt->fetchColumn()) $errors[] = 'Numero deja utilise.';

        // Upload PDF signe (optionnel)
        $pdfPath = null;
        if (isset($_FILES['fichier_pdf']) && $_FILES['fichier_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            $destDir = __DIR__ . '/../storage/contrats';
            $up = handle_upload($_FILES['fichier_pdf'], $destDir, ALLOWED_PDF_ONLY);
            if (!$up['success']) {
                $errors[] = 'PDF contrat : ' . ($up['error'] ?? 'echec');
            } else {
                $pdfPath = storage_relative_path($up['path']);
            }
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO contrats (numero, type_contrat, prestataire_id, date_debut, date_fin,
                                       montant_mensuel, is_cps01, statut, fichier_pdf, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$numero, $type, $prestaId, $debut, $fin, $montant, $isCps01, 'actif', $pdfPath, (int)user_id()]);
            $newId = (int)db()->lastInsertId();
            audit_log('contrat_cree', "Contrat $numero ($type) cree", 'contrats', $newId);
            flash_set('success', "Contrat $numero cree.");
            redirect('/portail/admin/contrats.php?action=view&id=' . $newId);
        }
    } catch (Throwable $e) {
        error_log('contrat create: ' . $e->getMessage());
        $errors[] = 'Erreur technique : ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $cid = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE contrats SET statut='cloture' WHERE id=?");
        $stmt->execute([$cid]);
        audit_log('contrat_archive', "Contrat #$cid archive", 'contrats', $cid);
        flash_set('success', 'Contrat archive.');
    } catch (Throwable $e) {
        flash_set('danger', 'Erreur.');
    }
    redirect('/portail/admin/contrats.php');
}

$pageTitle = 'Contrats';
$activeMenu = 'admin';

if ($action === 'view' && $id > 0) {
    $stmt = db()->prepare(
        'SELECT c.*, p.nom_complet AS prestataire, p.type_personne, p.email AS prestataire_email,
                u.nom_complet AS createur_nom
           FROM contrats c
           JOIN prestataires p ON c.prestataire_id = p.id
           JOIN users u ON c.created_by = u.id
          WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $contrat = $stmt->fetch();
    if (!$contrat) { flash_set('danger', 'Contrat introuvable.'); redirect('/portail/admin/contrats.php'); }

    // TCD associe (si CASI/CPSP)
    $tcd = null;
    if (in_array($contrat['type_contrat'], ['CASI','CPSP'], true)) {
        $stmt = db()->prepare("SELECT id, numero, valide_coordinateur FROM tcd_devis WHERE contrat_id = ? LIMIT 1");
        $stmt->execute([$id]);
        $tcd = $stmt->fetch();
    }

    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Contrat <?= e($contrat['numero']) ?>
            <span class="badge bg-<?= $contrat['statut']==='actif'?'success':($contrat['statut']==='suspendu'?'warning':'secondary') ?>"><?= e($contrat['statut']) ?></span>
            <?php if ((int)$contrat['is_cps01']===1): ?><span class="badge bg-info">CPS-01</span><?php endif; ?>
        </h1>
        <a href="/portail/admin/contrats.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Numero</dt><dd class="col-sm-8 font-monospace"><?= e($contrat['numero']) ?></dd>
                        <dt class="col-sm-4">Type</dt><dd class="col-sm-8"><?= e($contrat['type_contrat']) ?></dd>
                        <dt class="col-sm-4">Prestataire</dt><dd class="col-sm-8"><?= e($contrat['prestataire']) ?> (<?= e($contrat['type_personne']) ?>)</dd>
                        <dt class="col-sm-4">Periode</dt><dd class="col-sm-8"><?= e(date('d/m/Y', strtotime($contrat['date_debut']))) ?> au <?= e(date('d/m/Y', strtotime($contrat['date_fin']))) ?></dd>
                        <dt class="col-sm-4">Montant mensuel</dt><dd class="col-sm-8"><?= $contrat['montant_mensuel'] ? format_htg($contrat['montant_mensuel']) : '<em>Variable / N/A</em>' ?></dd>
                        <dt class="col-sm-4">Cree par</dt><dd class="col-sm-8"><?= e($contrat['createur_nom']) ?> le <?= e(date('d/m/Y', strtotime($contrat['created_at']))) ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><strong>Actions</strong></div>
                <div class="card-body">
                    <?php if ($contrat['fichier_pdf']): ?>
                        <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$contrat['fichier_pdf'])) ?>&type=pdf" target="_blank" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-file-pdf"></i> Telecharger le PDF signe
                        </a>
                    <?php else: ?>
                        <p class="text-muted small">Aucun PDF signe deplose.</p>
                    <?php endif; ?>

                    <?php if (in_array($contrat['type_contrat'], ['CASI','CPSP'], true)): ?>
                        <?php if ($tcd): ?>
                            <a href="/portail/admin/tcd.php?id=<?= (int)$tcd['id'] ?>" class="btn btn-outline-info w-100 mb-2">
                                <i class="bi bi-table"></i> TCD associe : <?= e($tcd['numero']) ?>
                                <?php if ((int)$tcd['valide_coordinateur'] === 1): ?>
                                    <span class="badge bg-success">valide</span>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning small">
                                <strong>TCD manquant.</strong> Obligatoire pour CASI/CPSP >= 300 000 HTG.
                                <a href="/portail/admin/tcd.php?action=new&contrat_id=<?= (int)$contrat['id'] ?>">Creer le TCD</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($contrat['statut'] === 'actif' && user_role() === 'administrateur'): ?>
                    <form method="post" onsubmit="return confirm('Archiver ce contrat ?');" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="archive">
                        <input type="hidden" name="id" value="<?= (int)$contrat['id'] ?>">
                        <button class="btn btn-outline-warning w-100"><i class="bi bi-archive"></i> Archiver le contrat</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';

} elseif ($action === 'new') {
    check_role(['administrateur']);
    $stmt = db()->query('SELECT id, nom_complet, type_personne FROM prestataires ORDER BY nom_complet');
    $prestataires = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Nouveau contrat</h1>
        <a href="/portail/admin/contrats.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
        <div class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Numero <span class="text-danger">*</span></label>
                    <input type="text" name="numero" class="form-control" required maxlength="30" placeholder="CPS-ACP-01-2026" value="<?= e($_POST['numero'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select name="type_contrat" id="type_contrat" class="form-select" required>
                        <?php foreach (['CPS','CPSP','CASI','CPSI'] as $t): ?>
                            <option value="<?= $t ?>"><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="form-check mt-2" id="bloc-cps01" style="display:none;">
                        <input type="checkbox" name="is_cps01" id="is_cps01" class="form-check-input" value="1">
                        <label class="form-check-label" for="is_cps01">is_cps01 (Coordinateur, double bloc)</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Prestataire <span class="text-danger">*</span></label>
                    <select name="prestataire_id" class="form-select" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($prestataires as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['nom_complet']) ?> (<?= e($p['type_personne']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date debut <span class="text-danger">*</span></label>
                    <input type="date" name="date_debut" class="form-control" required value="<?= e($_POST['date_debut'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date fin <span class="text-danger">*</span></label>
                    <input type="date" name="date_fin" class="form-control" required value="<?= e($_POST['date_fin'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Montant mensuel (optionnel)</label>
                    <div class="input-group">
                        <input type="number" name="montant_mensuel" class="form-control text-end" step="0.01" min="0" value="<?= e($_POST['montant_mensuel'] ?? '') ?>">
                        <span class="input-group-text">HTG</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">PDF du contrat signe (optionnel)</label>
                    <input type="file" name="fichier_pdf" class="form-control" accept="application/pdf">
                </div>
            </div>
            <hr>
            <button class="btn btn-primary"><i class="bi bi-save"></i> Creer le contrat</button>
        </div>
    </form>
    <script>
    const sel = document.getElementById('type_contrat');
    const bl  = document.getElementById('bloc-cps01');
    sel.addEventListener('change', () => bl.style.display = (sel.value === 'CPS') ? '' : 'none');
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';

} else {
    $filters = ['type' => $_GET['type'] ?? '', 'statut' => $_GET['statut'] ?? ''];
    $where = []; $params = [];
    if ($filters['type'] !== '') { $where[] = 'c.type_contrat = ?'; $params[] = $filters['type']; }
    if ($filters['statut'] !== '') { $where[] = 'c.statut = ?'; $params[] = $filters['statut']; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = db()->prepare(
        "SELECT c.*, p.nom_complet AS prestataire,
                DATEDIFF(c.date_fin, CURDATE()) AS jours_restants
           FROM contrats c
           JOIN prestataires p ON c.prestataire_id = p.id
           $whereSql
          ORDER BY c.created_at DESC LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="bi bi-file-earmark-text"></i> Contrats</h1>
        <?php if (user_role() === 'administrateur'): ?>
        <a href="?action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau contrat</a>
        <?php endif; ?>
    </div>

    <form method="get" class="card shadow-sm border-0 mb-3">
        <div class="card-body row g-2">
            <div class="col-md-3">
                <select name="type" class="form-select form-select-sm">
                    <option value="">-- Tous types --</option>
                    <?php foreach (['CPS','CPSP','CASI','CPSI'] as $t): ?>
                        <option value="<?= $t ?>" <?= ($filters['type']===$t)?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="statut" class="form-select form-select-sm">
                    <option value="">-- Tous statuts --</option>
                    <?php foreach (['actif','suspendu','cloture'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($filters['statut']===$s)?'selected':'' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-secondary w-100"><i class="bi bi-search"></i></button></div>
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr>
                    <th>Numero</th><th>Type</th><th>Prestataire</th><th>Periode</th>
                    <th class="text-end">Mensuel</th><th>Statut</th><th>PDF</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun contrat.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $expireBientot = $r['statut']==='actif' && (int)$r['jours_restants'] >= 0 && (int)$r['jours_restants'] < 30;
                ?>
                    <tr class="<?= $expireBientot ? 'table-warning' : '' ?>">
                        <td>
                            <a href="?action=view&id=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= e($r['numero']) ?></a>
                            <?php if ((int)$r['is_cps01']===1): ?><span class="badge bg-info">CPS-01</span><?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= e($r['type_contrat']) ?></span></td>
                        <td><?= e($r['prestataire']) ?></td>
                        <td><small><?= e(date('d/m/Y', strtotime($r['date_debut']))) ?> -> <?= e(date('d/m/Y', strtotime($r['date_fin']))) ?></small>
                            <?php if ($expireBientot): ?>
                                <br><small class="text-warning">Expire dans <?= (int)$r['jours_restants'] ?> jours</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end font-monospace"><?= $r['montant_mensuel'] ? format_htg($r['montant_mensuel']) : '-' ?></td>
                        <td><span class="badge bg-<?= $r['statut']==='actif'?'success':($r['statut']==='suspendu'?'warning':'secondary') ?>"><?= e($r['statut']) ?></span></td>
                        <td><?php if ($r['fichier_pdf']): ?><i class="bi bi-check-circle text-success"></i><?php else: ?><i class="bi bi-x-circle text-muted"></i><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
}
