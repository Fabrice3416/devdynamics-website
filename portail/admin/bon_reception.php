<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
check_role(['administrateur', 'coordinateur']);

$action = (string)($_GET['action'] ?? 'list');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $bcId = (int)($_POST['bon_commande_id'] ?? 0);
        $date = (string)($_POST['date_reception'] ?? '');
        $statut = (string)($_POST['statut_livraison'] ?? '');
        $obs = trim((string)($_POST['observations'] ?? ''));

        if ($bcId <= 0) $errors[] = 'BC obligatoire.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Date invalide.';
        if (!in_whitelist($statut, ['conforme','partielle','non_conforme'])) $errors[] = 'Statut invalide.';

        // Lignes
        $lignes = [];
        $descs = $_POST['rl_desc'] ?? []; $qtycmds = $_POST['rl_qcmd'] ?? []; $qtyrecs = $_POST['rl_qrec'] ?? []; $conformes = $_POST['rl_conf'] ?? [];
        foreach ($descs as $i => $d) {
            $d = trim((string)$d); if ($d === '') continue;
            $lignes[] = [
                'description' => $d,
                'quantite_commandee' => (float)($qtycmds[$i] ?? 0),
                'quantite_recue' => (float)($qtyrecs[$i] ?? 0),
                'conforme' => isset($conformes[$i]) ? 1 : 0,
            ];
        }
        if (!$lignes) $errors[] = 'Au moins une ligne.';

        // Pieces jointes (optionnel)
        $pieces = [];
        if (!empty($_FILES['pieces']['name'][0] ?? null)) {
            for ($i=0; $i<count($_FILES['pieces']['name']); $i++) {
                if (($_FILES['pieces']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $sf = [
                    'name'=>$_FILES['pieces']['name'][$i], 'type'=>$_FILES['pieces']['type'][$i],
                    'tmp_name'=>$_FILES['pieces']['tmp_name'][$i], 'error'=>$_FILES['pieces']['error'][$i],
                    'size'=>$_FILES['pieces']['size'][$i],
                ];
                $up = handle_upload($sf, __DIR__ . '/../storage/bons_reception/BR-' . date('YmdHis'));
                if ($up['success']) $pieces[] = ['type'=>'photo','fichier'=>storage_relative_path($up['path'])];
            }
        }

        if (!$errors) {
            // signature admin
            $stmt = db()->prepare('SELECT signature_image FROM users WHERE id=?');
            $stmt->execute([(int)user_id()]);
            $sig = $stmt->fetchColumn() ?: null;

            db()->beginTransaction();
            $numero = generate_numero('BR', 'bons_reception');
            $stmt = db()->prepare(
                'INSERT INTO bons_reception (numero, bon_commande_id, date_reception, lignes_reception_json,
                    statut_livraison, observations, pieces_jointes_json,
                    sig_admin_scan, valide_administrateur, date_validation, valide_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)'
            );
            $stmt->execute([
                $numero, $bcId, $date, json_encode($lignes),
                $statut, $obs ?: null, $pieces ? json_encode($pieces) : null,
                $sig, (int)user_id()
            ]);
            $id = (int)db()->lastInsertId();

            // Mise a jour BC
            $newBcStatut = $statut === 'conforme' ? 'recu' : ($statut === 'partielle' ? 'partiellement_recu' : 'emis');
            $stmt = db()->prepare("UPDATE bons_commande SET statut = ? WHERE id = ?");
            $stmt->execute([$newBcStatut, $bcId]);

            // Alerte si non conforme
            if ($statut === 'non_conforme') {
                notifier_br_non_conforme($numero, $bcId, $id);
            }

            audit_log('br_valide', "BR $numero ($statut)", 'bons_reception', $id);
            db()->commit();
            flash_set('success', "BR $numero enregistre. BC statut -> $newBcStatut.");
            redirect('/portail/admin/bon_reception.php');
        }
    } catch (Throwable $e) {
        db()->rollBack();
        $errors[] = 'Erreur: ' . $e->getMessage();
    }
}

function notifier_br_non_conforme(string $brNumero, int $bcId, int $brId): void
{
    $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role IN ('administrateur','coordinateur') AND actif=1");
    while ($u = $stmt->fetch()) {
        $body = '<p>BR <strong>' . e($brNumero) . '</strong> declare <strong>NON CONFORME</strong>.</p>'
              . '<p>Le F02 lie a ce BC est bloque jusqu\'a resolution.</p>';
        alerte_envoyer('br_non_conforme', $u['email'],
            'BR non conforme : ' . $brNumero, $body, ['type' => 'bons_reception', 'id' => $brId]);
    }
}

$pageTitle = 'Bons de Reception';
$activeMenu = 'admin';

if ($action === 'new') {
    check_role(['administrateur']);
    $bcPre = (int)($_GET['bc_id'] ?? 0);
    $stmt = db()->query("SELECT bc.id, bc.numero, bc.type_commande, bc.objet_commande, bc.lignes_commande_json FROM bons_commande bc WHERE bc.statut IN ('emis','partiellement_recu') AND bc.type_commande='biens_materiels' ORDER BY bc.id DESC");
    $bcs = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Nouveau Bon de Reception</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Bon de Commande <span class="text-danger">*</span></label>
                <select name="bon_commande_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($bcs as $bc): ?>
                        <option value="<?= (int)$bc['id'] ?>" <?= ($bcPre === (int)$bc['id'])?'selected':'' ?>><?= e($bc['numero']) ?> - <?= e(mb_substr($bc['objet_commande'], 0, 60)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date reception <span class="text-danger">*</span></label>
                <input type="date" name="date_reception" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Statut livraison <span class="text-danger">*</span></label>
                <select name="statut_livraison" class="form-select" required>
                    <option value="conforme">Conforme</option>
                    <option value="partielle">Partielle</option>
                    <option value="non_conforme">Non conforme</option>
                </select>
            </div>
        </div>

        <hr>
        <h6>Lignes recues</h6>
        <div id="lignes">
            <div class="row g-2 mb-2 ligne">
                <div class="col-md-5"><input type="text" name="rl_desc[]" class="form-control" placeholder="Description"></div>
                <div class="col-md-2"><input type="number" name="rl_qcmd[]" class="form-control text-end" placeholder="Qte cmd" min="0" step="0.01"></div>
                <div class="col-md-2"><input type="number" name="rl_qrec[]" class="form-control text-end" placeholder="Qte recue" min="0" step="0.01"></div>
                <div class="col-md-3">
                    <div class="form-check mt-2"><input type="checkbox" name="rl_conf[]" class="form-check-input" value="1"><label class="form-check-label">Conforme</label></div>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('lignes').appendChild(document.querySelector('#lignes .ligne').cloneNode(true))"><i class="bi bi-plus"></i> Ajouter ligne</button>

        <hr>
        <div class="mb-3">
            <label class="form-label">Observations / non-conformites</label>
            <textarea name="observations" class="form-control" rows="2"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Photos / bons livraison (optionnel)</label>
            <input type="file" name="pieces[]" class="form-control" multiple accept="application/pdf,image/jpeg,image/png">
        </div>

        <button class="btn btn-primary"><i class="bi bi-save"></i> Valider la reception</button>
    </div>
</form>
<?php
    require __DIR__ . '/../includes/footer.php';
} else {
    $stmt = db()->query(
        "SELECT br.*, bc.numero AS bc_numero, u.nom_complet AS valide_par_nom
           FROM bons_reception br
           JOIN bons_commande bc ON br.bon_commande_id = bc.id
           LEFT JOIN users u ON br.valide_par = u.id
          ORDER BY br.id DESC LIMIT 200"
    );
    $rows = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-truck"></i> Bons de Reception</h1>
    <?php if (user_role()==='administrateur'): ?>
    <a href="?action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau BR</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0"><div class="card-body p-0">
<table class="table table-hover mb-0">
<thead class="table-light"><tr><th>BR</th><th>BC</th><th>Date</th><th>Statut</th><th>Validateur</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucun BR.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= e($r['numero']) ?></td>
    <td><small><?= e($r['bc_numero']) ?></small></td>
    <td><small><?= e(date('d/m/Y', strtotime($r['date_reception']))) ?></small></td>
    <td><span class="badge bg-<?= ['conforme'=>'success','partielle'=>'warning','non_conforme'=>'danger'][$r['statut_livraison']] ?? 'secondary' ?>"><?= e(str_replace('_',' ',$r['statut_livraison'])) ?></span></td>
    <td><small><?= e($r['valide_par_nom'] ?? '-') ?></small></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div></div>
<?php
    require __DIR__ . '/../includes/footer.php';
}
