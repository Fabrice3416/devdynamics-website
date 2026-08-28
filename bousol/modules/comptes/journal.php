<?php
declare(strict_types=1);

/**
 * Comptes - journal des ecritures.
 *
 * L'ecriture porte le module d'origine et l'identifiant de l'objet en valeurs, ce
 * qui maintient Comptes ignorant des modules qui l'appellent (CDC 8.3) : le journal
 * les affiche tels quels, sans jointure vers eux.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_projet();
require_module('comptes');

// « Les recettes du projet, notamment les interets crediteurs du compte, sont
// enregistrees et declarees, une recette non communiquee figurant parmi les causes
// d'inegibilite » (CDC 4.1). C'est la seule ecriture que Comptes pose de sa propre
// initiative : toutes les autres lui viennent d'un module.
$peutSaisir = user_role() === 'raf';
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutSaisir) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    require_phase_execution('Enregistrer une recette');
    $montant = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0'));
    $libelle = trim((string)($_POST['libelle'] ?? ''));
    if ($montant <= 0 || $libelle === '') {
        $erreur = 'Le libellé et un montant strictement positif sont obligatoires.';
    } else {
        try {
            ecriture_recette((int)($_POST['compte_id'] ?? 0), $montant,
                (string)($_POST['date'] ?? date('Y-m-d')), $libelle, 'recette:' . date('YmdHis'));
            flash_set('success', 'Recette enregistrée. Elle sera déclarée au bailleur avec le rapport financier.');
            redirect(base_path('modules/comptes/journal.php'));
        } catch (Throwable $e) {
            $erreur = $e->getMessage();
        }
    }
}

$comptesTresorerie = array_values(array_filter(comptes_plan(), fn($c) => in_array($c['type'], COMPTES_TRESORERIE, true)));
$liste = ecritures();
$ongletActif = 'journal';
page_start('Journal des écritures', 'comptes');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Journal des écritures</h1>
    <span class="text-muted small"><?= count($liste) ?> écritures · partie double allégée</span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($peutSaisir): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Enregistrer une recette</div>
    <div class="card-body">
        <p class="small text-muted mb-2">Intérêts créditeurs du compte, produits divers. Une recette non
            communiquée au bailleur figure parmi les causes d'inéligibilité : elle se saisit dès l'avis de crédit.</p>
        <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-3">
                <label class="form-label small mb-1">Date</label>
                <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Compte encaissant</label>
                <select class="form-select form-select-sm" name="compte_id" required>
                    <?php foreach ($comptesTresorerie as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' — ' . $c['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Libellé</label>
                <input class="form-control form-control-sm" name="libelle" required maxlength="255"
                       placeholder="Intérêts créditeurs du trimestre">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Montant</label>
                <input class="form-control form-control-sm text-end" name="montant" inputmode="decimal" required>
            </div>
            <div class="col-12 mt-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th style="width:7rem">Date</th><th>Libellé</th><th>Compte</th>
            <th class="text-end" style="width:9rem">Débit</th><th class="text-end" style="width:9rem">Crédit</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $e): $mvts = mouvements_ecriture((int)$e['id']); ?>
        <tr class="fw-semibold">
            <td><?= e(date_fr($e['date'])) ?></td>
            <td colspan="4"><?= e($e['libelle']) ?>
                <span class="badge text-bg-light border"><?= e($e['type']) ?></span>
                <small class="text-muted">· <?= e($e['origine_module'] . ':' . $e['origine_ref']) ?></small></td>
        </tr>
        <?php foreach ($mvts as $m): ?>
        <tr>
            <td></td>
            <td class="small text-muted" style="padding-left:1.5rem"><?= $m['tiers_nom'] ? e($m['tiers_nom']) : '' ?>
                <?= $m['depense_reportee'] ? '<span class="badge text-bg-light border">dépense reportée</span>' : '' ?></td>
            <td class="small"><?= e($m['code']) ?> <span class="text-muted"><?= e($m['libelle']) ?></span></td>
            <td class="text-end"><?= $m['sens'] === 'D' ? e(htg((float)$m['montant'])) : '' ?></td>
            <td class="text-end"><?= $m['sens'] === 'C' ? e(htg((float)$m['montant'])) : '' ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="5" class="text-muted p-3">Aucune écriture. Le journal se remplit à l'exécution des règlements.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php page_end();
