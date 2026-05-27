<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
check_role(['administrateur', 'coordinateur']);

$action = (string)($_GET['action'] ?? 'list');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $contratId = (int)($_POST['contrat_id'] ?? 0);
        $objet = trim((string)($_POST['objet_commande'] ?? ''));
        $typeCmd = (string)($_POST['type_commande'] ?? 'biens_materiels');
        $fournisseur = trim((string)($_POST['fournisseur'] ?? ''));

        // Lignes simples (1 ligne par defaut, JSON)
        $lignes = [];
        $totalCalc = 0;
        $descs = $_POST['l_desc'] ?? []; $qtys = $_POST['l_qty'] ?? []; $units = $_POST['l_unit'] ?? []; $prices = $_POST['l_price'] ?? [];
        foreach ($descs as $i => $d) {
            $d = trim((string)$d); if ($d === '') continue;
            $qty = (float)($qtys[$i] ?? 1);
            $pu  = (float)str_replace([',', ' '], ['.', ''], (string)($prices[$i] ?? '0'));
            $mt  = round($qty * $pu, 2);
            $lignes[] = ['description'=>$d, 'quantite'=>$qty, 'unite'=>(string)($units[$i] ?? 'u'), 'prix_unitaire'=>$pu, 'montant_ligne'=>$mt];
            $totalCalc += $mt;
        }

        if ($contratId <= 0) $errors[] = 'Contrat obligatoire.';
        if ($objet === '')   $errors[] = 'Objet obligatoire.';
        if (!$lignes)        $errors[] = 'Au moins une ligne de commande.';
        if ($fournisseur === '') $errors[] = 'Fournisseur obligatoire.';

        if (!$errors) {
            $numero = generate_numero('BC', 'bons_commande');
            $stmt = db()->prepare(
                'INSERT INTO bons_commande (numero, contrat_id, date_emission, objet_commande, type_commande,
                    lignes_commande_json, montant_total, fournisseur, statut, created_by)
                 VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $numero, $contratId, $objet, $typeCmd,
                json_encode($lignes), $totalCalc, $fournisseur,
                'emis', (int)user_id()
            ]);
            $id = (int)db()->lastInsertId();
            audit_log('bc_emis', "BC $numero emis ($typeCmd, " . format_htg($totalCalc) . ")", 'bons_commande', $id);
            flash_set('success', "BC $numero emis.");
            redirect('/portail/admin/bon_commande.php?id=' . $id);
        }
    } catch (Throwable $e) { $errors[] = 'Erreur: ' . $e->getMessage(); }
}

$pageTitle = 'Bons de Commande';
$activeMenu = 'admin';

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = db()->prepare(
        'SELECT bc.*, c.numero AS contrat_numero, c.type_contrat,
                (SELECT br.statut_livraison FROM bons_reception br WHERE br.bon_commande_id = bc.id ORDER BY br.id DESC LIMIT 1) AS br_statut
           FROM bons_commande bc JOIN contrats c ON bc.contrat_id = c.id
          WHERE bc.id = ?'
    );
    $stmt->execute([$id]);
    $bc = $stmt->fetch();
    if (!$bc) { flash_set('danger','Introuvable.'); redirect('/portail/admin/bon_commande.php'); }
    $lignes = json_decode($bc['lignes_commande_json'], true) ?: [];

    require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">BC <?= e($bc['numero']) ?>
        <span class="badge bg-<?= $bc['statut']==='recu'?'success':'warning' ?>"><?= e($bc['statut']) ?></span>
    </h1>
    <a href="/portail/admin/bon_commande.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Contrat</dt><dd class="col-sm-9"><?= e($bc['contrat_numero']) ?> (<?= e($bc['type_contrat']) ?>)</dd>
            <dt class="col-sm-3">Date emission</dt><dd class="col-sm-9"><?= e(date('d/m/Y', strtotime($bc['date_emission']))) ?></dd>
            <dt class="col-sm-3">Fournisseur</dt><dd class="col-sm-9"><?= e($bc['fournisseur']) ?></dd>
            <dt class="col-sm-3">Objet</dt><dd class="col-sm-9"><?= nl2br(e($bc['objet_commande'])) ?></dd>
            <dt class="col-sm-3">Type</dt><dd class="col-sm-9"><?= e(str_replace('_',' ',$bc['type_commande'])) ?></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white"><strong>Lignes de commande</strong></div>
    <table class="table mb-0">
        <thead class="table-light"><tr><th>Description</th><th class="text-end">Qte</th><th>Unite</th><th class="text-end">PU</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
            <tr>
                <td><?= e($l['description']) ?></td>
                <td class="text-end"><?= e((string)$l['quantite']) ?></td>
                <td><?= e($l['unite']) ?></td>
                <td class="text-end font-monospace"><?= format_htg($l['prix_unitaire']) ?></td>
                <td class="text-end font-monospace"><?= format_htg($l['montant_ligne']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light"><tr class="fw-bold"><td colspan="4">Total</td><td class="text-end font-monospace"><?= format_htg($bc['montant_total']) ?></td></tr></tfoot>
    </table>
</div>

<?php if ($bc['type_commande'] === 'biens_materiels' && $bc['statut'] === 'emis'): ?>
<div class="alert alert-info">
    <strong>BC pour biens materiels :</strong> un Bon de Reception est requis avant validation du F02.
    <a href="/portail/admin/bon_reception.php?action=new&bc_id=<?= (int)$bc['id'] ?>" class="alert-link">Creer le Bon de Reception</a>
</div>
<?php endif; ?>
<?php
    require __DIR__ . '/../includes/footer.php';

} elseif ($action === 'new') {
    check_role(['administrateur']);
    $stmt = db()->query("SELECT id, numero FROM contrats WHERE type_contrat='CASI' AND statut='actif' ORDER BY numero");
    $contrats = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Nouveau Bon de Commande (CASI)</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Contrat CASI <span class="text-danger">*</span></label>
                <select name="contrat_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($contrats as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['numero']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Type <span class="text-danger">*</span></label>
                <select name="type_commande" class="form-select" required>
                    <option value="biens_materiels">Biens materiels</option>
                    <option value="services">Services</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Fournisseur <span class="text-danger">*</span></label>
                <input type="text" name="fournisseur" class="form-control" required>
            </div>
            <div class="col-12">
                <label class="form-label">Objet de la commande <span class="text-danger">*</span></label>
                <textarea name="objet_commande" class="form-control" rows="2" required></textarea>
            </div>
        </div>

        <hr>
        <h6>Lignes de commande</h6>
        <div id="lignes">
            <div class="row g-2 mb-2 ligne">
                <div class="col-md-5"><input type="text" name="l_desc[]" class="form-control" placeholder="Description" required></div>
                <div class="col-md-2"><input type="number" name="l_qty[]" class="form-control text-end" placeholder="Qte" min="0.01" step="0.01" required></div>
                <div class="col-md-1"><input type="text" name="l_unit[]" class="form-control" placeholder="u" required></div>
                <div class="col-md-3"><input type="number" name="l_price[]" class="form-control text-end" placeholder="PU HTG" min="0.01" step="0.01" required></div>
                <div class="col-md-1"></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="addLine"><i class="bi bi-plus"></i> Ajouter</button>

        <hr>
        <button class="btn btn-primary"><i class="bi bi-save"></i> Emettre le BC</button>
    </div>
</form>

<script>
document.getElementById('addLine').addEventListener('click', () => {
    const c = document.getElementById('lignes');
    const r = c.firstElementChild.cloneNode(true);
    r.querySelectorAll('input').forEach(i => i.value = '');
    c.appendChild(r);
});
</script>
<?php
    require __DIR__ . '/../includes/footer.php';
} else {
    $stmt = db()->query(
        "SELECT bc.*, c.numero AS contrat_numero
           FROM bons_commande bc JOIN contrats c ON bc.contrat_id = c.id
          ORDER BY bc.id DESC LIMIT 200"
    );
    $rows = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cart-check"></i> Bons de Commande</h1>
    <?php if (user_role()==='administrateur'): ?>
    <a href="?action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau BC</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr>
                <th>BC</th><th>Contrat</th><th>Date</th><th>Fournisseur</th><th>Type</th>
                <th class="text-end">Total</th><th>Statut</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Aucun BC.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?id=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= e($r['numero']) ?></a></td>
                    <td><small><?= e($r['contrat_numero']) ?></small></td>
                    <td><small><?= e(date('d/m/Y', strtotime($r['date_emission']))) ?></small></td>
                    <td><?= e($r['fournisseur']) ?></td>
                    <td><small><?= e(str_replace('_',' ',$r['type_commande'])) ?></small></td>
                    <td class="text-end font-monospace"><?= format_htg($r['montant_total']) ?></td>
                    <td><span class="badge bg-<?= ['emis'=>'warning','partiellement_recu'=>'info','recu'=>'success','annule'=>'secondary'][$r['statut']] ?? 'secondary' ?>"><?= e(str_replace('_',' ',$r['statut'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
    require __DIR__ . '/../includes/footer.php';
}
