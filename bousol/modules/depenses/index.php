<?php
declare(strict_types=1);

/**
 * Depenses - les dossiers du projet, et l'ouverture d'un nouveau.
 *
 * Sept types sont actifs, chacun portant sa liste de pieces obligatoires et le
 * moment ou chacune est exigee (annexe D). Le type contrat de travail existe dans
 * le modele mais reste desactive, l'ensemble des intervenants etant sous contrat
 * de service : sa reactivation entrainerait le retour des charges sociales.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/depenses.php';
require_projet();
require_module('depenses');

// « Ouvrir et constituer un dossier de depense : RAF » (annexe B).
$peutOuvrir = user_role() === 'raf';
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutOuvrir) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    require_phase_execution('Ouvrir un dossier de dépense');
    $res = dossier_ouvrir([
        'type'          => (string)($_POST['type'] ?? ''),
        'tiers_id'      => (int)($_POST['tiers_id'] ?? 0),
        'objet'         => (string)($_POST['objet'] ?? ''),
        'montant_prevu' => (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant_prevu'] ?? '0')),
    ]);
    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Dossier ' . $res['numero'] . ' ouvert.'
            . (!empty($res['concurrence']) ? ' Le montant prévu dépasse le seuil : trois proformas sont exigés.' : ''));
        redirect(base_path('modules/depenses/dossier.php?id=' . (int)$res['id']));
    }
}

$filtre = (string)($_GET['statut'] ?? '');
$liste = dossiers(array_key_exists($filtre, STATUTS_DOSSIER) ? $filtre : null);
if ($filtre === 'ouverts') {
    $liste = array_values(array_filter($liste, fn($d) => !in_array($d['statut'], ['clos', 'abandonne'], true)));
}
$beneficiaires = db()->query('SELECT id, nom, type FROM tiers WHERE actif = 1 ORDER BY nom')->fetchAll();
$seuil = param('seuil_proformas');

$ongletActif = $filtre === 'ouverts' ? 'ouverts' : 'tous';
page_start('Dépenses', 'depenses');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Dossiers de dépense</h1>
    <span class="text-muted small"><?= count($liste) ?> dossier(s)
        <?php if ($seuil !== null): ?>· seuil de mise en concurrence <?= e(htg((float)$seuil)) ?><?php endif; ?></span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($seuil === null): ?>
<div class="alert alert-warning py-2"><i class="bi bi-sliders"></i>
    Le seuil déclenchant trois proformas n'est pas saisi : aucune mise en concurrence ne sera exigée.
    <?php if (user_role() === 'coordinateur'): ?><a href="<?= e(base_path('modules/noyau/')) ?>">Paramétrer</a>.<?php endif; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th>Numéro</th><th>Objet</th><th>Bénéficiaire</th><th>Ligne</th>
            <th class="text-end">Montant</th><th>Pièces</th><th>Statut</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $d): ?>
        <tr>
            <td><a href="<?= e(base_path('modules/depenses/dossier.php?id=' . (int)$d['id'])) ?>"><?= e($d['numero']) ?></a>
                <br><small class="text-muted"><?= e(TYPES_DOSSIER[$d['type']]['libelle'] ?? $d['type']) ?></small></td>
            <td class="small"><?= e($d['objet']) ?>
                <?php if ($d['numero_piece']): ?><br><span class="text-muted">pièce <?= e($d['numero_piece']) ?></span><?php endif; ?></td>
            <td class="small"><?= e($d['tiers_nom']) ?></td>
            <td class="small text-muted"><?= $d['ligne_code'] ? e($d['ligne_code']) : '—' ?></td>
            <td class="text-end"><?= $d['montant_impute'] !== null ? e(htg((float)$d['montant_impute'])) : '' ?></td>
            <td class="small text-muted"><?= (int)$d['pieces_manquantes'] > 0
                ? (int)$d['pieces_manquantes'] . ' attendue(s)'
                : '<span class="text-success">complètes</span>' ?></td>
            <td><span class="badge text-bg-light border"><?= e(STATUTS_DOSSIER[$d['statut']] ?? $d['statut']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="7" class="text-muted p-3">Aucun dossier.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($peutOuvrir): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-folder-plus"></i> Ouvrir un dossier</div>
    <div class="card-body">
        <p class="small text-muted">Le type commande la liste des pièces obligatoires et le moment où chacune
            est exigée. Le montant prévu ne sert qu'à savoir si la mise en concurrence s'applique ;
            le montant réel viendra de l'imputation.</p>
        <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label small mb-1">Type de dossier</label>
                <select class="form-select form-select-sm" name="type" required>
                    <?php foreach (TYPES_DOSSIER as $code => $def): if (empty($def['actif'])) continue; ?>
                    <option value="<?= e($code) ?>"><?= e($def['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Bénéficiaire</label>
                <select class="form-select form-select-sm" name="tiers_id" required>
                    <option value="">—</option>
                    <?php foreach ($beneficiaires as $b): ?>
                    <option value="<?= (int)$b['id'] ?>"><?= e($b['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Montant prévu</label>
                <input class="form-control form-control-sm text-end" name="montant_prevu" inputmode="decimal">
            </div>
            <div class="col-12">
                <label class="form-label small mb-1">Objet</label>
                <input class="form-control form-control-sm" name="objet" required maxlength="255">
            </div>
            <div class="col-12 mt-3"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Ouvrir</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php page_end();
