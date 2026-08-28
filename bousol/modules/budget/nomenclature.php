<?php
declare(strict_types=1);

/**
 * Budget - saisie du detail du budget contractuel.
 *
 * Le budget contractuel est fige et ne se modifie que par avenant signe (CDC 2.2).
 * Mais tous les bailleurs ne communiquent pas le meme niveau de detail : le budget
 * approuve de Koule Ki Pale ne donne que les six sous-totaux de rubrique, ses onze
 * lignes imputables arrivant donc sans montant. Cet ecran sert a les saisir.
 *
 * D'ou deux regimes. Une ligne sans montant se saisit librement tant qu'aucune
 * imputation n'existe sur le projet : c'est une initialisation, pas une
 * modification. Une ligne qui porte deja un montant ne change que sur avenant
 * televerse. Dans les deux cas, la somme des lignes d'une rubrique doit retomber
 * sur le sous-total du contrat, faute de quoi le budget approuve ne serait plus
 * respecte.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/budget.php';
require_role(['coordinateur']);
require_module('budget');
require_phase_execution('Modifier le budget contractuel');

$erreurs = [];
$lignes  = budget_lignes();

$st = db()->prepare('SELECT COUNT(*) FROM imputations WHERE projet_id = ?');
$st->execute([projet_id()]);
$ecritures = (int)$st->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $motif   = trim((string)($_POST['motif'] ?? ''));
    $qte     = (array)($_POST['quantite'] ?? []);
    $vu      = (array)($_POST['valeur_unitaire'] ?? []);
    $unites  = (array)($_POST['unite'] ?? []);

    $nouveau = [];   // code => [unite, quantite, valeur_unitaire, montant]
    foreach ($lignes as $code => $l) {
        if ($l['nature'] !== 'imputable') {
            continue;
        }
        $q = str_replace([' ', ','], ['', '.'], trim((string)($qte[$code] ?? '')));
        $v = str_replace([' ', ','], ['', '.'], trim((string)($vu[$code] ?? '')));
        if ($q === '' && $v === '') {
            continue;
        }
        if (!is_numeric($q) || !is_numeric($v)) {
            $erreurs[] = $code . ' : quantité et valeur unitaire doivent être des nombres.';
            continue;
        }
        $u = (string)($unites[$code] ?? $l['unite'] ?? 'forfait');
        if (!array_key_exists($u, UNITES)) {
            $erreurs[] = $code . ' : unité hors liste.';
            continue;
        }
        $montant = round((float)$q * (float)$v, 2);
        if (abs($montant - (float)($l['montant'] ?? 0)) < 0.01
            && $u === (string)$l['unite']
            && abs((float)$q - (float)($l['quantite'] ?? 0)) < 0.01) {
            continue;   // rien n'a bouge sur cette ligne
        }
        $nouveau[$code] = ['unite' => $u, 'quantite' => (float)$q, 'valeur_unitaire' => (float)$v, 'montant' => $montant];
    }

    if (!$nouveau && !$erreurs) {
        $erreurs[] = 'Aucune ligne n\'a changé.';
    }

    // Une ligne qui porte deja un montant contractuel ne se corrige que par avenant.
    $dejaChiffrees = [];
    foreach ($nouveau as $code => $n) {
        if ($lignes[$code]['montant'] !== null) {
            $dejaChiffrees[] = $code;
        }
    }
    $avenantId = null;
    if (!$erreurs && $dejaChiffrees) {
        if (empty($_FILES['avenant']['name'])) {
            $erreurs[] = 'Les lignes ' . implode(', ', $dejaChiffrees)
                . ' portent déjà un montant contractuel : leur correction exige le téléversement de l\'avenant signé.';
        } else {
            $up = enregistrer_upload($_FILES['avenant'], 'coffre',
                projet_code() . '-AVENANT-BUDGET-' . date('Ymd-His') . '.pdf', ALLOWED_DOCUMENT, true);
            if (!$up['success']) {
                $erreurs[] = 'Avenant : ' . $up['error'];
            } else {
                $avenantId = (int)$up['id'];
            }
        }
    }
    if (!$erreurs && $motif === '') {
        $erreurs[] = 'Le motif est obligatoire.';
    }

    // La somme des lignes d'une rubrique doit retomber sur le sous-total du contrat.
    // Le controle ne porte que sur les rubriques entamees : une rubrique dont aucune
    // ligne n'est encore chiffree n'est pas fausse, elle est a saisir - sans quoi la
    // premiere rubrique ventilee serait refusee tant que les trois autres ne le
    // seraient pas, et le travail deviendrait indivisible.
    if (!$erreurs) {
        $parRubrique = [];
        $entamee = [];
        foreach ($lignes as $code => $l) {
            if ($l['nature'] !== 'imputable') {
                continue;
            }
            $r = (string)($l['rubrique'] ?? '?');
            $montant = isset($nouveau[$code]) ? $nouveau[$code]['montant'] : ($l['montant'] === null ? null : (float)$l['montant']);
            $parRubrique[$r] = ($parRubrique[$r] ?? 0.0) + (float)($montant ?? 0);
            if ($montant !== null) {
                $entamee[$r] = true;
            }
        }
        foreach ($lignes as $l) {
            if ($l['nature'] !== 'rubrique' || (int)$l['niveau'] !== 1 || $l['montant'] === null) {
                continue;
            }
            $r = (string)($l['rubrique'] ?? '?');
            if (empty($entamee[$r])) {
                continue;
            }
            $ecart = round((float)$l['montant'] - ($parRubrique[$r] ?? 0.0), 2);
            if (abs($ecart) >= 0.01) {
                $erreurs[] = sprintf('Rubrique %s « %s » : le détail totalise %s pour un sous-total contractuel de %s (écart %s).',
                    $l['code'], $l['libelle'], htg($parRubrique[$r] ?? 0.0), htg((float)$l['montant']), htg($ecart));
            }
        }
    }

    if (!$erreurs) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Le budget de gestion suit le contractuel tant qu'aucune reallocation
            // n'a fait diverger les deux colonnes.
            $maj = $pdo->prepare(
                'UPDATE lignes_budgetaires
                    SET unite = ?, quantite = ?, valeur_unitaire = ?, montant = ?,
                        montant_gestion = CASE WHEN montant_gestion IS NULL OR montant_gestion <=> montant THEN ? ELSE montant_gestion END,
                        quantite_gestion = CASE WHEN quantite_gestion IS NULL OR quantite_gestion <=> quantite THEN ? ELSE quantite_gestion END
                  WHERE id = ? AND projet_id = ?'
            );
            $trace = [];
            foreach ($nouveau as $code => $n) {
                $l = $lignes[$code];
                $maj->execute([$n['unite'], $n['quantite'], $n['valeur_unitaire'], $n['montant'],
                               $n['montant'], $n['quantite'], (int)$l['id'], projet_id()]);
                $trace[] = sprintf('%s %s x %s = %s', $code, $n['quantite'], htg($n['valeur_unitaire']), htg($n['montant']));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('nomenclature: ' . $e->getMessage());
            $erreurs[] = 'Enregistrement impossible.';
        }
        if (!$erreurs) {
            audit('budget', $avenantId === null ? 'nomenclature_detaillee' : 'budget_contractuel_modifie',
                'budget', projet_id(),
                implode(' ; ', $trace) . ' · ' . $motif . ($avenantId ? ' · avenant fichier #' . $avenantId : ''));
            flash_set('success', count($nouveau) . ' ligne(s) enregistrée(s) au budget contractuel.');
            redirect(base_path('modules/budget/nomenclature.php'));
        }
    }
}

$lignes  = budget_lignes();
$manque  = budget_detail_manquant();

$ongletActif = 'detail';
page_start('Détail du budget contractuel', 'budget');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-1">Détail du budget contractuel</h1>
<p class="text-muted">
    Le budget contractuel est figé et ne se modifie que par avenant signé. Cet écran sert d'abord à
    <strong>saisir</strong> les lignes que le budget approuvé laisse sans montant, en reproduisant l'annexe du contrat
    sans réécriture ni regroupement.
    <?php if ($ecritures > 0): ?>
    <br><span class="text-danger"><?= $ecritures ?> imputation(s) existent déjà sur ce projet : toute correction devient un avenant.</span>
    <?php endif; ?>
</p>

<?php foreach ($erreurs as $r): ?>
<div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($r) ?></div>
<?php endforeach; ?>

<?php if (!$manque): ?>
<div class="alert alert-success py-2"><i class="bi bi-check2-circle"></i>
    Chaque rubrique est ventilée : le détail des lignes retombe exactement sur les sous-totaux du contrat.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr class="small text-muted">
                <th style="min-width:18rem">Ligne</th>
                <th style="width:9rem">Unité</th>
                <th style="width:7rem">Quantité</th>
                <th style="width:10rem">Valeur unitaire</th>
                <th class="text-end" style="width:9rem">Montant</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lignes as $code => $l):
                if ($l['nature'] === 'imputable'): ?>
                <tr>
                    <td><span class="text-muted small me-2"><?= e($l["code"]) ?></span><?= e($l['libelle']) ?></td>
                    <td><select class="form-select form-select-sm" name="unite[<?= e($l["code"]) ?>]">
                        <?php foreach (UNITES as $u => $lib): ?>
                        <option value="<?= e($u) ?>" <?= (string)$l['unite'] === $u ? 'selected' : '' ?>><?= e($lib) ?></option>
                        <?php endforeach; ?>
                    </select></td>
                    <td><input class="form-control form-control-sm text-end" name="quantite[<?= e($l["code"]) ?>]" inputmode="decimal"
                               value="<?= $l['quantite'] === null ? '' : e(rtrim(rtrim(number_format((float)$l['quantite'], 2, '.', ''), '0'), '.')) ?>"></td>
                    <td><input class="form-control form-control-sm text-end" name="valeur_unitaire[<?= e($l["code"]) ?>]" inputmode="decimal"
                               value="<?= $l['valeur_unitaire'] === null ? '' : e(number_format((float)$l['valeur_unitaire'], 2, '.', '')) ?>"></td>
                    <td class="text-end"><?= $l['montant'] === null ? '<span class="text-muted">à saisir</span>' : e(htg((float)$l['montant'])) ?></td>
                </tr>
            <?php else: ?>
                <tr class="fw-semibold">
                    <td colspan="4" style="padding-left:<?= (int)$l['niveau'] * 0.9 ?>rem">
                        <span class="text-muted small me-2"><?= e($l["code"]) ?></span><?= e($l['libelle']) ?></td>
                    <td class="text-end"><?= $l['montant'] === null ? '' : e(htg((float)$l['montant'])) ?></td>
                </tr>
            <?php endif; endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <label class="form-label fw-semibold">Motif</label>
            <textarea class="form-control" name="motif" rows="3" required
                      placeholder="Par exemple : détail communiqué par la FOKAL le 27/08/2026."></textarea>
        </div></div></div>
        <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <label class="form-label fw-semibold">Avenant signé</label>
            <input type="file" class="form-control form-control-sm" name="avenant" accept=".pdf,.jpg,.jpeg,.png">
            <div class="form-text">Exigé seulement pour corriger une ligne qui porte déjà un montant contractuel.</div>
        </div></div></div>
    </div>

    <div class="mt-3">
        <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
        <a class="btn btn-link" href="<?= e(base_path('modules/budget/')) ?>">Annuler</a>
    </div>
</form>
<?php page_end();
