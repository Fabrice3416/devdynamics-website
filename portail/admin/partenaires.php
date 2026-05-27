<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
check_role(['administrateur', 'coordinateur']);

$action = (string)($_GET['action'] ?? 'list');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_partenaire') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $numero = trim((string)($_POST['numero_cpsi'] ?? ''));
        $nom    = trim((string)($_POST['nom'] ?? ''));
        $type   = (string)($_POST['type'] ?? '');
        $rep    = trim((string)($_POST['representant'] ?? ''));
        $titre  = trim((string)($_POST['titre_representant'] ?? ''));
        $stmt = db()->prepare('SELECT id FROM partenaires WHERE numero_cpsi=?');
        $stmt->execute([$numero]);
        if ($stmt->fetchColumn()) $errors[] = 'CPSI existant.';
        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO partenaires (numero_cpsi, nom, type, representant, titre_representant,
                    point_focal, email_contact, telephone, statut, date_signature)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $numero, $nom, $type, $rep, $titre,
                $_POST['point_focal'] ?: null,
                $_POST['email_contact'] ?: null,
                $_POST['telephone'] ?: null,
                'actif',
                $_POST['date_signature'] ?: null,
            ]);
            flash_set('success', 'Partenaire cree.');
            redirect('/portail/admin/partenaires.php');
        }
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_fecp') {
    check_role(['administrateur','coordinateur']);
    try {
        verify_csrf();
        $partenaireId = (int)($_POST['partenaire_id'] ?? 0);
        $mois = (int)($_POST['mois'] ?? 0);
        $appreciation = (string)($_POST['appreciation'] ?? 'actif');
        $devdynEng = trim((string)($_POST['engagements_devdyn'] ?? ''));
        $partEng   = trim((string)($_POST['engagements_partenaire'] ?? ''));
        if ($partenaireId <= 0 || $mois < 1 || $mois > 6) $errors[] = 'Donnees invalides.';

        if (!$errors) {
            $numero = sprintf('FECP-ACP-%02d-M%02d', $partenaireId, $mois);
            $devdynList = array_filter(array_map('trim', explode("\n", $devdynEng)));
            $partList   = array_filter(array_map('trim', explode("\n", $partEng)));
            $stmt = db()->prepare(
                'INSERT INTO fiches_execution_cpsi (numero, partenaire_id, mois,
                    engagements_devdyn_json, engagements_partenaire_json,
                    appreciation, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    engagements_devdyn_json = VALUES(engagements_devdyn_json),
                    engagements_partenaire_json = VALUES(engagements_partenaire_json),
                    appreciation = VALUES(appreciation)'
            );
            $stmt->execute([
                $numero, $partenaireId, $mois,
                json_encode(array_map(fn($l) => ['libelle'=>$l,'statut'=>'realise'], $devdynList)),
                json_encode(array_map(fn($l) => ['libelle'=>$l,'statut'=>'realise'], $partList)),
                $appreciation, (int)user_id()
            ]);
            flash_set('success', "FECP $numero cree/mis a jour.");
            redirect('/portail/admin/partenaires.php');
        }
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$pageTitle = 'Partenaires (CPSI)';
$activeMenu = 'admin';

if ($action === 'new_partenaire') {
    check_role(['administrateur']);
    require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Nouveau partenaire CPSI</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_partenaire">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">N° CPSI <span class="text-danger">*</span></label>
                <input type="text" name="numero_cpsi" class="form-control" required placeholder="CPSI-ACP-01-2026"></div>
            <div class="col-md-5"><label class="form-label">Nom <span class="text-danger">*</span></label>
                <input type="text" name="nom" class="form-control" required></div>
            <div class="col-md-3"><label class="form-label">Type <span class="text-danger">*</span></label>
                <select name="type" class="form-select" required>
                    <?php foreach (['Mairie','Universite','Ecole','Institution','Ministeriel','autre'] as $t): ?>
                        <option value="<?= $t ?>"><?= $t ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label">Representant <span class="text-danger">*</span></label>
                <input type="text" name="representant" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Titre <span class="text-danger">*</span></label>
                <input type="text" name="titre_representant" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Point focal</label>
                <input type="text" name="point_focal" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Email</label>
                <input type="email" name="email_contact" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Telephone</label>
                <input type="text" name="telephone" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Date signature</label>
                <input type="date" name="date_signature" class="form-control"></div>
        </div>
        <hr>
        <button class="btn btn-primary">Creer</button>
    </div>
</form>
<?php
    require __DIR__ . '/../includes/footer.php';
} elseif ($action === 'new_fecp') {
    $partenaires = db()->query("SELECT id, numero_cpsi, nom FROM partenaires WHERE statut!='inactif' ORDER BY numero_cpsi")->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Nouvelle FECP mensuelle</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_fecp">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Partenaire <span class="text-danger">*</span></label>
                <select name="partenaire_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($partenaires as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['numero_cpsi']) ?> - <?= e($p['nom']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label">Mois (M01-M06) <span class="text-danger">*</span></label>
                <select name="mois" class="form-select" required>
                    <?php for ($m=1;$m<=6;$m++): ?><option value="<?= $m ?>">M<?= str_pad((string)$m,2,'0',STR_PAD_LEFT) ?></option><?php endfor; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label">Appreciation <span class="text-danger">*</span></label>
                <select name="appreciation" class="form-select">
                    <option value="actif">Actif</option>
                    <option value="partiel">Partiel</option>
                    <option value="inactif">Inactif</option>
                </select></div>
            <div class="col-md-6"><label class="form-label">Engagements DEVDYNAMICS (1 par ligne)</label>
                <textarea name="engagements_devdyn" class="form-control" rows="5" placeholder="Formation prevue le 15&#10;Materiel livre"></textarea></div>
            <div class="col-md-6"><label class="form-label">Engagements Partenaire (1 par ligne)</label>
                <textarea name="engagements_partenaire" class="form-control" rows="5" placeholder="Local mis a disposition&#10;Participants envoyes"></textarea></div>
        </div>
        <hr>
        <button class="btn btn-primary">Enregistrer la FECP</button>
    </div>
</form>
<?php
    require __DIR__ . '/../includes/footer.php';
} else {
    $partenaires = db()->query(
        "SELECT p.*, (SELECT COUNT(*) FROM fiches_execution_cpsi f WHERE f.partenaire_id=p.id) AS nb_fecp
           FROM partenaires p ORDER BY p.numero_cpsi"
    )->fetchAll();
    $fecps = db()->query(
        "SELECT f.*, p.numero_cpsi, p.nom AS partenaire_nom
           FROM fiches_execution_cpsi f
           JOIN partenaires p ON f.partenaire_id = p.id
          ORDER BY f.id DESC LIMIT 50"
    )->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-people"></i> Partenaires CPSI</h1>
    <div>
        <?php if (user_role()==='administrateur'): ?>
        <a href="?action=new_partenaire" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> Nouveau partenaire</a>
        <?php endif; ?>
        <a href="?action=new_fecp" class="btn btn-sm btn-primary"><i class="bi bi-file-plus"></i> Nouvelle FECP</a>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white"><strong>Partenaires (<?= count($partenaires) ?> / 5 cibles)</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>CPSI</th><th>Nom</th><th>Type</th><th>Representant</th><th>FECP</th><th>Statut</th></tr></thead>
            <tbody>
            <?php if (!$partenaires): ?><tr><td colspan="6" class="text-center text-muted py-3">Aucun partenaire.</td></tr><?php endif; ?>
            <?php foreach ($partenaires as $p): ?>
                <tr>
                    <td><?= e($p['numero_cpsi']) ?></td>
                    <td><?= e($p['nom']) ?></td>
                    <td><small><?= e($p['type']) ?></small></td>
                    <td><small><?= e($p['representant']) ?> (<?= e($p['titre_representant']) ?>)</small></td>
                    <td><?= (int)$p['nb_fecp'] ?></td>
                    <td><span class="badge bg-<?= $p['statut']==='actif'?'success':($p['statut']==='partiel'?'warning':'secondary') ?>"><?= e($p['statut']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>FECP recentes</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>N°</th><th>Partenaire</th><th>Mois</th><th>Appreciation</th><th>Cloture</th></tr></thead>
            <tbody>
            <?php if (!$fecps): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucune FECP.</td></tr><?php endif; ?>
            <?php foreach ($fecps as $f): ?>
                <tr>
                    <td><?= e($f['numero']) ?></td>
                    <td><small><?= e($f['numero_cpsi']) ?> - <?= e($f['partenaire_nom']) ?></small></td>
                    <td>M<?= str_pad((string)$f['mois'],2,'0',STR_PAD_LEFT) ?></td>
                    <td><span class="badge bg-<?= $f['appreciation']==='actif'?'success':($f['appreciation']==='partiel'?'warning':'secondary') ?>"><?= e($f['appreciation']) ?></span></td>
                    <td><small><?= $f['date_cloture'] ? e(date('d/m/Y', strtotime($f['date_cloture']))) : '<em>En cours</em>' ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
    require __DIR__ . '/../includes/footer.php';
}
