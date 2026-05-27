<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
check_role(['administrateur', 'coordinateur']);

// Import CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            flash_set('danger', 'Fichier CSV manquant.');
            redirect('/portail/admin/candidatures.php');
        }
        $rows = [];
        if (($h = fopen($_FILES['csv']['tmp_name'], 'r')) !== false) {
            $header = fgetcsv($h, 0, ';');
            while (($line = fgetcsv($h, 0, ';')) !== false) {
                $rows[] = array_combine($header, $line);
            }
            fclose($h);
        }
        $imported = 0; $dupes = 0;
        db()->beginTransaction();
        foreach ($rows as $r) {
            $tel = trim((string)($r['telephone'] ?? ''));
            $dn  = trim((string)($r['date_naissance'] ?? ''));
            if (!$tel || !$dn) continue;
            $stmt = db()->prepare('SELECT id FROM candidatures WHERE telephone=? AND date_naissance=?');
            $stmt->execute([$tel, $dn]);
            if ($stmt->fetchColumn()) { $dupes++; continue; }

            $numDossier = 'ACP-' . str_pad((string)((int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM candidatures')->fetchColumn()), 3, '0', STR_PAD_LEFT);
            $stmt = db()->prepare(
                'INSERT INTO candidatures (numero_dossier, nom_prenom, date_naissance, genre,
                    telephone, niveau_etudes, ordinateur, disponible, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $numDossier,
                (string)($r['nom_prenom'] ?? ''),
                $dn, $r['genre'] ?? 'F', $tel,
                $r['niveau_etudes'] ?? 'autre',
                isset($r['ordinateur']) && (int)$r['ordinateur'] === 1 ? 1 : 0,
                isset($r['disponible']) && (int)$r['disponible'] === 1 ? 1 : 0,
                'recu',
            ]);
            $imported++;
        }
        db()->commit();
        audit_log('upload_fichier', "Import candidatures: $imported nouveaux, $dupes doublons", 'candidatures', null);
        flash_set('success', "Import : $imported candidatures ajoutees, $dupes doublons ignores.");
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('danger', 'Erreur import: ' . $e->getMessage());
    }
    redirect('/portail/admin/candidatures.php');
}

// Mise a jour notes / decision
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    check_role(['administrateur','coordinateur']);
    try {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare(
            'UPDATE candidatures
                SET note_motivation=?, note_profil=?, note_disponibilite=?, note_entretien=?,
                    statut=?, decision_finale=?
              WHERE id=?'
        );
        $stmt->execute([
            $_POST['note_motivation'] !== '' ? (float)$_POST['note_motivation'] : null,
            $_POST['note_profil'] !== '' ? (float)$_POST['note_profil'] : null,
            $_POST['note_disponibilite'] !== '' ? (float)$_POST['note_disponibilite'] : null,
            $_POST['note_entretien'] !== '' ? (float)$_POST['note_entretien'] : null,
            $_POST['statut'],
            !empty($_POST['decision_finale']) ? $_POST['decision_finale'] : null,
            $id,
        ]);
        flash_set('success', 'Candidature mise a jour.');
    } catch (Throwable $e) { flash_set('danger', 'Erreur.'); }
    redirect('/portail/admin/candidatures.php');
}

$filters = [
    'genre'  => $_GET['genre']  ?? '',
    'statut' => $_GET['statut'] ?? '',
    'niveau' => $_GET['niveau'] ?? '',
];
$where = []; $params = [];
if ($filters['genre'])  { $where[] = 'genre = ?';        $params[] = $filters['genre']; }
if ($filters['statut']) { $where[] = 'statut = ?';       $params[] = $filters['statut']; }
if ($filters['niveau']) { $where[] = 'niveau_etudes = ?'; $params[] = $filters['niveau']; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("SELECT * FROM candidatures $whereSql ORDER BY note_finale DESC, nom_prenom ASC LIMIT 200");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$retenus = (int)db()->query("SELECT COUNT(*) FROM candidatures WHERE decision_finale='retenu'")->fetchColumn();
$femmes = (int)db()->query("SELECT COUNT(*) FROM candidatures WHERE decision_finale='retenu' AND genre='F'")->fetchColumn();

$pageTitle = 'Candidatures';
$activeMenu = 'admin';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-3"><i class="bi bi-people"></i> Registre des Candidatures</h1>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="small text-muted">Retenus</h6>
            <h4><?= $retenus ?> / 30</h4>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0"><div class="card-body">
            <h6 class="small text-muted">Femmes retenues</h6>
            <h4 class="<?= $femmes < 15 ? 'text-danger' : 'text-success' ?>"><?= $femmes ?> / 15</h4>
        </div></div>
    </div>
    <?php if (user_role()==='administrateur'): ?>
    <div class="col-md-6">
        <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_csv">
                <h6 class="small text-muted">Import CSV (UTF-8, separateur ;)</h6>
                <p class="small text-muted mb-2">Colonnes : nom_prenom;date_naissance;genre;telephone;niveau_etudes;ordinateur;disponible</p>
                <div class="input-group">
                    <input type="file" name="csv" class="form-control form-control-sm" accept=".csv,text/csv" required>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Importer</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-2">
        <div class="col-md-2">
            <select name="genre" class="form-select form-select-sm">
                <option value="">-- Genre --</option>
                <option value="F" <?= ($filters['genre']==='F')?'selected':'' ?>>Femme</option>
                <option value="H" <?= ($filters['genre']==='H')?'selected':'' ?>>Homme</option>
            </select>
        </div>
        <div class="col-md-3">
            <select name="statut" class="form-select form-select-sm">
                <option value="">-- Statut --</option>
                <?php foreach (['recu','complet','incomplet','preselectione','retenu','liste_attente','rejete'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($filters['statut']===$s)?'selected':'' ?>><?= e(str_replace('_',' ',$s)) ?></option>
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
                <th>N°</th><th>Nom</th><th>G</th><th>Niveau</th>
                <th class="text-end">Notes /30+</th><th>Statut</th><th>Decision</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">Aucune candidature.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><small><?= e($r['numero_dossier']) ?></small></td>
                    <td><?= e($r['nom_prenom']) ?></td>
                    <td><?= e($r['genre']) ?></td>
                    <td><small><?= e($r['niveau_etudes']) ?></small></td>
                    <td class="text-end"><?= $r['note_finale'] !== null ? number_format((float)$r['note_finale'], 1) : '-' ?></td>
                    <td><small><?= e(str_replace('_',' ',$r['statut'])) ?></small></td>
                    <td><small><?= e(str_replace('_',' ',$r['decision_finale'] ?? '')) ?></small></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cm<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></button>
                    </td>
                </tr>

                <div class="modal fade" id="cm<?= (int)$r['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <div class="modal-header"><h5 class="modal-title"><?= e($r['nom_prenom']) ?> (<?= e($r['numero_dossier']) ?>)</h5></div>
                            <div class="modal-body">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small">Motivation /10</label>
                                        <input type="number" name="note_motivation" class="form-control" step="0.1" min="0" max="10" value="<?= e((string)($r['note_motivation'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">Profil /10</label>
                                        <input type="number" name="note_profil" class="form-control" step="0.1" min="0" max="10" value="<?= e((string)($r['note_profil'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">Disponibilite /5</label>
                                        <input type="number" name="note_disponibilite" class="form-control" step="0.1" min="0" max="5" value="<?= e((string)($r['note_disponibilite'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Note entretien /10</label>
                                        <input type="number" name="note_entretien" class="form-control" step="0.1" min="0" max="10" value="<?= e((string)($r['note_entretien'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Statut</label>
                                        <select name="statut" class="form-select">
                                            <?php foreach (['recu','complet','incomplet','preselectione','retenu','liste_attente','rejete'] as $s): ?>
                                                <option value="<?= $s ?>" <?= ($r['statut']===$s)?'selected':'' ?>><?= e(str_replace('_',' ',$s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small">Decision finale</label>
                                        <select name="decision_finale" class="form-select">
                                            <option value="">-- Aucune --</option>
                                            <?php foreach (['retenu','liste_attente','rejete'] as $d): ?>
                                                <option value="<?= $d ?>" <?= ($r['decision_finale']===$d)?'selected':'' ?>><?= e(str_replace('_',' ',$d)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                <button class="btn btn-primary">Enregistrer</button>
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
