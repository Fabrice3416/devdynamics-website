<?php
declare(strict_types=1);

/** Noyau - consultation du journal d'audit (lecture seule pour tous les roles). */

require_once __DIR__ . '/../../includes/layout.php';
require_login();

$fModule = (string)($_GET['module'] ?? '');
$fAction = trim((string)($_GET['action'] ?? ''));
$fUser   = trim((string)($_GET['utilisateur'] ?? ''));
$fDu     = (string)($_GET['du'] ?? '');
$fAu     = (string)($_GET['au'] ?? '');
$page    = max(1, (int)($_GET['p'] ?? 1));
$parPage = 50;

$where = []; $args = [];
if ($fModule !== '' && isset(MODULES[$fModule])) { $where[] = 'module = ?'; $args[] = $fModule; }
if ($fAction !== '') { $where[] = 'action LIKE ?'; $args[] = '%' . $fAction . '%'; }
if ($fUser !== '')   { $where[] = 'utilisateur_nom LIKE ?'; $args[] = '%' . $fUser . '%'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDu)) { $where[] = 'horodatage >= ?'; $args[] = $fDu . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fAu)) { $where[] = 'horodatage <= ?'; $args[] = $fAu . ' 23:59:59'; }
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare('SELECT COUNT(*) FROM journal_audit' . $sqlWhere);
$st->execute($args);
$total = (int)$st->fetchColumn();
$st = db()->prepare('SELECT * FROM journal_audit' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $parPage . ' OFFSET ' . (($page - 1) * $parPage));
$st->execute($args);
$lignes = $st->fetchAll();
$pages = max(1, (int)ceil($total / $parPage));

page_start('Journal d\'audit', 'noyau');
$ongletActif = 'audit';
require __DIR__ . '/_nav.php';
?>
<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-2"><label class="form-label small">Module</label>
        <select name="module" class="form-select form-select-sm"><option value="">Tous</option>
        <?php foreach (MODULES as $k => [$l]): ?><option value="<?= e($k) ?>" <?= $fModule === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small">Action</label><input name="action" class="form-control form-control-sm" value="<?= e($fAction) ?>"></div>
    <div class="col-md-2"><label class="form-label small">Utilisateur</label><input name="utilisateur" class="form-control form-control-sm" value="<?= e($fUser) ?>"></div>
    <div class="col-md-2"><label class="form-label small">Du</label><input type="date" name="du" class="form-control form-control-sm" value="<?= e($fDu) ?>"></div>
    <div class="col-md-2"><label class="form-label small">Au</label><input type="date" name="au" class="form-control form-control-sm" value="<?= e($fAu) ?>"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary">Filtrer</button> <a class="btn btn-sm btn-link" href="audit.php">Effacer</a></div>
</form>
<div class="card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between"><span>Journal en ajout seul</span><small class="fw-normal text-muted"><?= $total ?> entrée(s) · page <?= $page ?>/<?= $pages ?></small></div>
    <div class="table-responsive">
    <table class="table table-sm table-striped mb-0 small">
        <thead><tr><th>#</th><th>Horodatage</th><th>Module</th><th>Action</th><th>Objet</th><th>Détail</th><th>Utilisateur</th><th>IP</th><th>Empreintes</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
        <tr>
            <td class="text-muted"><?= (int)$l['id'] ?></td>
            <td class="text-nowrap"><?= e(datetime_fr($l['horodatage'])) ?></td>
            <td><span class="badge text-bg-secondary"><?= e($l['module']) ?></span></td>
            <td><?= e($l['action']) ?></td>
            <td class="text-nowrap"><?= e(trim(($l['objet_type'] ?? '') . ' ' . ($l['objet_id'] ?? ''))) ?></td>
            <td><?= e($l['detail'] ?? '') ?></td>
            <td><?= e($l['utilisateur_nom'] ?? '—') ?></td>
            <td class="text-muted"><?= e($l['ip'] ?? '') ?></td>
            <td class="text-muted" style="font-family:monospace;font-size:.7rem"><?= $l['empreinte_avant'] ? e(substr($l['empreinte_avant'], 0, 10)) . '… → ' : '' ?><?= $l['empreinte_apres'] ? e(substr($l['empreinte_apres'], 0, 10)) . '…' : '' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1): $q = $_GET; ?>
    <div class="card-footer bg-white d-flex gap-2 justify-content-center">
        <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): $q['p'] = $i; ?>
            <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= e(http_build_query($q)) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php page_end();
