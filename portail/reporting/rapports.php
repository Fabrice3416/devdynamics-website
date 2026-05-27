<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
check_role(['administrateur', 'coordinateur']);

$rapports = db()->query(
    "SELECT r.*, u.nom_complet AS genere_par_nom
       FROM rapports_generes r
       LEFT JOIN users u ON r.genere_par = u.id
      ORDER BY r.id DESC LIMIT 100"
)->fetchAll();

$pageTitle = 'Reporting';
$activeMenu = 'reporting';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-3"><i class="bi bi-file-earmark-bar-graph"></i> Reporting financier</h1>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title text-primary"><i class="bi bi-file-pdf"></i> RFM</h5>
                <p class="text-muted small">Rapport Financier Mensuel - synthese PDF (budget + journal + rapprochement).</p>
                <form method="post" action="export.php" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="rfm">
                    <div class="col-7">
                        <select name="mois" class="form-select form-select-sm">
                            <?php for ($m=1;$m<=12;$m++): ?>
                                <option value="<?= $m ?>" <?= ($m === (int)date('m')-1)?'selected':'' ?>><?= e(mois_fr($m)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <input type="number" name="annee" class="form-control form-control-sm" value="<?= date('Y') ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-download"></i> Generer RFM</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title text-primary"><i class="bi bi-file-zip"></i> DJ - Dossier Justificatifs</h5>
                <p class="text-muted small">Archive ZIP : tous les dossiers de la periode + index PDF. <strong>Max 30 dossiers/export</strong>.</p>
                <form method="post" action="export.php" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="dj">
                    <div class="col-7">
                        <select name="mois" class="form-select form-select-sm">
                            <?php for ($m=1;$m<=12;$m++): ?>
                                <option value="<?= $m ?>" <?= ($m === (int)date('m')-1)?'selected':'' ?>><?= e(mois_fr($m)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <input type="number" name="annee" class="form-control form-control-sm" value="<?= date('Y') ?>">
                    </div>
                    <div class="col-12">
                        <select name="tri" class="form-select form-select-sm">
                            <option value="chronologique">Tri chronologique</option>
                            <option value="ligne_budgetaire">Par ligne budgetaire</option>
                            <option value="type_contrat">Par type de contrat</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-download"></i> Generer DJ (ZIP)</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title text-primary"><i class="bi bi-graph-up"></i> Rapport Cumule</h5>
                <p class="text-muted small">Synthese cumulee sur plage de mois (graphique + tableau cumulatif).</p>
                <form method="post" action="export.php" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="cumule">
                    <div class="col-6">
                        <label class="form-label small">De</label>
                        <input type="month" name="periode_debut" class="form-control form-control-sm" value="<?= date('Y-01') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">A</label>
                        <input type="month" name="periode_fin" class="form-control form-control-sm" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-download"></i> Generer Cumule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>Historique des rapports generes</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr>
                <th>Numero</th><th>Type</th><th>Periode</th><th class="text-end">Dossiers</th>
                <th class="text-end">Montant total</th><th>Genere par</th><th>Statut</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rapports): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucun rapport genere.</td></tr><?php endif; ?>
            <?php foreach ($rapports as $r): ?>
                <tr>
                    <td><strong><?= e($r['numero']) ?></strong></td>
                    <td><span class="badge bg-light text-dark"><?= e(strtoupper($r['type_rapport'])) ?></span></td>
                    <td><small><?= e(date('d/m/Y', strtotime($r['periode_debut']))) ?> -> <?= e(date('d/m/Y', strtotime($r['periode_fin']))) ?></small></td>
                    <td class="text-end"><?= (int)$r['nb_dossiers'] ?></td>
                    <td class="text-end font-monospace"><?= format_htg($r['montant_total_htg']) ?></td>
                    <td><small><?= e($r['genere_par_nom'] ?? '-') ?></small></td>
                    <td><span class="badge bg-<?= $r['statut']==='genere'?'success':($r['statut']==='erreur'?'danger':'warning') ?>"><?= e($r['statut']) ?></span></td>
                    <td>
                        <?php if ($r['statut']==='genere'): ?>
                            <?php if ($r['fichier_zip']): ?>
                                <a href="serve_rapport.php?id=<?= (int)$r['id'] ?>&format=zip" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-download"></i> ZIP</a>
                            <?php endif; ?>
                            <?php if ($r['fichier_pdf']): ?>
                                <a href="serve_rapport.php?id=<?= (int)$r['id'] ?>&format=pdf" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-file-pdf"></i></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
