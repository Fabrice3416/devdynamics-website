<?php
declare(strict_types=1);

/**
 * Budget - reallocation et mobilisation de la provision.
 *
 * Une reallocation deplace du budget de gestion d'une ligne vers une autre ; elle
 * ne touche jamais le budget contractuel, qui ne bouge que par avenant signe.
 * L'ecran ne force pas l'equilibre des mouvements : c'est au controle 7, le plafond
 * contractuel, de refuser une somme qui deborderait la convention, et non a la
 * saisie de rendre le cas impossible a exprimer.
 *
 * Les quatre controles qui jouent ici sont ceux du CDC 2.3 : plancher de
 * reallocation, variation par rubrique ou par ligne, plafond contractuel, et
 * mobilisation de la provision sur autorisation.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/budget.php';
require_role(['coordinateur']);
require_module('budget');
require_phase_execution('Réallouer le budget de gestion');

$erreurs  = [];
$alertes  = [];
$saisie   = [];
$motif    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $motif  = trim((string)($_POST['motif'] ?? ''));
    $saisie = (array)($_POST['montant'] ?? []);
    $saisieQ = (array)($_POST['quantite'] ?? []);
    $lignes = budget_lignes();

    $deltasM = [];
    $deltasQ = [];
    foreach ($lignes as $code => $l) {
        if (!array_key_exists($code, $saisie) || trim((string)$saisie[$code]) === '') {
            continue;
        }
        $val = str_replace([' ', ','], ['', '.'], trim((string)$saisie[$code]));
        if (!is_numeric($val)) {
            $erreurs[] = ['message' => $code . ' : montant illisible.'];
            continue;
        }
        $delta = round((float)$val - (float)($l['montant_gestion'] ?? 0), 2);
        if (abs($delta) >= 0.01) {
            $deltasM[$code] = $delta;
        }
    }
    foreach ($lignes as $code => $l) {
        if (!array_key_exists($code, $saisieQ) || trim((string)$saisieQ[$code]) === '' || $l['quantite_gestion'] === null) {
            continue;
        }
        $val = str_replace([' ', ','], ['', '.'], trim((string)$saisieQ[$code]));
        if (!is_numeric($val)) {
            $erreurs[] = ['message' => $code . ' : quantité illisible.'];
            continue;
        }
        $dq = round((float)$val - (float)$l['quantite_gestion'], 2);
        if (abs($dq) >= 0.01) {
            $deltasQ[$code] = $dq;
        }
    }

    if ($motif === '') {
        $erreurs[] = ['message' => 'Le motif est obligatoire : c\'est lui qui rend la réallocation lisible au journal d\'audit.'];
    }
    if (!$deltasM && !$deltasQ) {
        $erreurs[] = ['message' => 'Aucun montant ni aucune quantité n\'a changé.'];
    }

    // Les autorisations sont televersees d'abord : le controle a besoin de savoir
    // si la piece existe, et une piece versee pour rien reste au coffre sans dommage.
    $autorisations = [];
    if (!$erreurs) {
        foreach (['provision' => 'MOBILISATION-PROVISION', 'variation' => 'AUTORISATION-VARIATION'] as $k => $prefixe) {
            if (empty($_FILES['autorisation_' . $k]['name'])) {
                continue;
            }
            $up = enregistrer_upload($_FILES['autorisation_' . $k], 'coffre',
                projet_code() . '-' . $prefixe . '-' . date('Ymd-His') . '.pdf', ALLOWED_DOCUMENT, true);
            if (!$up['success']) {
                $erreurs[] = ['message' => 'Autorisation (' . $k . ') : ' . $up['error']];
            } else {
                $autorisations[$k] = (int)$up['id'];
            }
        }
    }

    if (!$erreurs) {
        $verdict = budget_controle_reallocation($deltasM, $deltasQ, $autorisations);
        $erreurs = $verdict['refus'];
        $alertes = $verdict['alertes'];
        if (!$erreurs) {
            try {
                budget_appliquer_reallocation($deltasM, $deltasQ, $motif, $autorisations);
                $n = count($deltasM);
                flash_set('success', $n . ' ligne(s) réallouée(s). Le détail est au journal d\'audit.'
                    . ($alertes ? ' ' . implode(' ', $alertes) : ''));
                redirect(base_path('modules/budget/realloc.php'));
            } catch (Throwable $e) {
                error_log('reallocation: ' . $e->getMessage());
                $erreurs[] = ['message' => 'Réallocation impossible.'];
            }
        }
    }
}

$lignes    = budget_lignes();
$consomme  = budget_consomme();
$provision = budget_ligne_provision();
$plafond   = plafond_contractuel();
$totalGestion = budget_total_gestion();
$regime    = param('regime_provision', 'ligne_dediee');

$ongletActif = 'realloc';
page_start('Réallocation budgétaire', 'budget');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-1">Réallocation du budget de gestion</h1>
<p class="text-muted">
    Saisir le <strong>nouveau</strong> montant de gestion des seules lignes qui changent ; les autres restent en place.
    Le budget contractuel n'est jamais touché ici.
    <?php if ($plafond !== null): ?>
    Total actuel <?= e(htg($totalGestion)) ?> pour un plafond contractuel de <?= e(htg($plafond)) ?>.
    <?php endif; ?>
</p>

<?php foreach ($erreurs as $r): ?>
<div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i>
    <?php if (!empty($r['regle'])): ?><strong><?= e(ucfirst($r['regle'])) ?>.</strong> <?php endif; ?><?= e($r['message']) ?></div>
<?php endforeach; ?>
<?php foreach ($alertes as $a): ?>
<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= e($a) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right"></i> Mouvements</div>
        <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr class="small text-muted">
                <th style="min-width:20rem">Ligne</th>
                <th class="text-end">Consommé</th>
                <th class="text-end">Gestion actuelle</th>
                <th style="width:11rem">Nouveau montant</th>
                <th style="width:8rem">Nouvelle qté</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lignes as $code => $l):
                $estProvision = $provision !== null && (int)$provision['id'] === (int)$l['id'];
                if ($l['nature'] !== 'imputable' && !$estProvision) continue;
                $c = $consomme[(int)$l['id']] ?? ['montant' => 0.0, 'quantite' => 0.0]; ?>
                <tr>
                    <td><span class="text-muted small me-2"><?= e($l["code"]) ?></span><?= e($l['libelle']) ?>
                        <?php if ($estProvision): ?><span class="badge text-bg-light border">provision</span><?php endif; ?></td>
                    <td class="text-end text-muted small"><?= e(htg($c['montant'])) ?></td>
                    <td class="text-end"><?= e(htg((float)($l['montant_gestion'] ?? 0))) ?></td>
                    <td><input class="form-control form-control-sm text-end" name="montant[<?= e($l["code"]) ?>]"
                               value="<?= e((string)($saisie[$code] ?? '')) ?>" placeholder="inchangé" inputmode="decimal"></td>
                    <td><?php if ($l['quantite_gestion'] !== null): ?>
                        <input class="form-control form-control-sm text-end" name="quantite[<?= e($l["code"]) ?>]"
                               value="" placeholder="<?= e(rtrim(rtrim(number_format((float)$l['quantite_gestion'], 2, ',', ' '), '0'), ',')) ?>" inputmode="decimal">
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <label class="form-label fw-semibold">Motif</label>
                <textarea class="form-control" name="motif" rows="3" required
                          placeholder="Ce qui justifie le mouvement. Enregistré au journal d'audit avec l'auteur et l'horodatage."><?= e($motif) ?></textarea>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <label class="form-label fw-semibold">Autorisations écrites</label>
                <div class="mb-2">
                    <label class="form-label small mb-1">Autorisation de variation
                        <span class="text-muted">— exigée au-delà du seuil de blocage</span></label>
                    <input type="file" class="form-control form-control-sm" name="autorisation_variation" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <?php if ($regime === 'ligne_dediee'): ?>
                <div>
                    <label class="form-label small mb-1">Autorisation de mobilisation de la provision</label>
                    <input type="file" class="form-control form-control-sm" name="autorisation_provision" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text">Celle qui libère la provision ne vaut pas accord sur la réallocation : au-delà du seuil, les deux sont exigées séparément.</div>
                </div>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="mt-3">
        <button class="btn btn-primary"><i class="bi bi-check2"></i> Contrôler et enregistrer</button>
        <a class="btn btn-link" href="<?= e(base_path('modules/budget/')) ?>">Annuler</a>
    </div>
</form>
<?php page_end();
