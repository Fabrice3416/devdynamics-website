<?php
declare(strict_types=1);

/**
 * Comptes - les reglements du projet, et la demande d'un nouveau.
 *
 * Le reglement appartient a ce module et non a Depenses : le dossier demande son
 * execution, Comptes le produit, l'enregistre et l'ecrit en partie double
 * (CDC 4.9). Tant que Depenses n'est pas livre, la demande se saisit ici, et son
 * origine reste libre - c'est le champ que Depenses remplira demain.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_projet();
require_module('comptes');

// Le RAF libelle, le Coordinateur et les mandataires lisent (annexe B).
$peutCreer = user_role() === 'raf';
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutCreer) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    require_phase_execution('Libeller un règlement');

    $fichierTaux = null;
    if (!empty($_FILES['preuve_taux']['name'])) {
        $up = enregistrer_upload($_FILES['preuve_taux'], 'scans',
            projet_code() . '-TAUX-' . date('Ymd-His') . '.pdf', ALLOWED_DOCUMENT);
        if (!$up['success']) {
            $erreur = 'Preuve du taux : ' . $up['error'];
        } else {
            $fichierTaux = (int)$up['id'];
        }
    }

    if ($erreur === null) {
        $r = reglement_creer([
            'mode'            => (string)($_POST['mode'] ?? 'virement'),
            'numero_cheque'   => (string)($_POST['numero_cheque'] ?? ''),
            'beneficiaire_id' => (int)($_POST['beneficiaire_id'] ?? 0),
            'compte_id'       => (int)($_POST['compte_id'] ?? 0),
            'montant'         => str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0')),
            'devise'          => (string)($_POST['devise'] ?? 'HTG'),
            'montant_devise'  => ($_POST['montant_devise'] ?? '') !== '' ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST['montant_devise']) : null,
            'taux_change'     => ($_POST['taux_change'] ?? '') !== '' ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST['taux_change']) : null,
            'preuve_taux_fichier_id' => $fichierTaux,
            'objet'           => trim((string)($_POST['objet'] ?? '')),
            'origine_module'  => trim((string)($_POST['origine_module'] ?? 'comptes')) ?: 'comptes',
            'origine_ref'     => trim((string)($_POST['origine_ref'] ?? '')) ?: 'saisie:manuelle',
        ]);
        if (!$r['success']) {
            $erreur = $r['error'];
        } else {
            flash_set('success', 'Règlement ' . $r['numero'] . ' enregistré. Il attend deux autorisations de mandataires.');
            redirect(base_path('modules/comptes/reglement.php?id=' . (int)$r['id']));
        }
    }
}

$filtre = (string)($_GET['statut'] ?? '');
$liste = reglements(null, in_array($filtre, ['demande', 'autorise', 'execute', 'annule'], true) ? $filtre : null);
$comptesTresorerie = array_values(array_filter(comptes_plan(), fn($c) => in_array($c['type'], COMPTES_TRESORERIE, true)));
$beneficiaires = db()->query("SELECT id, nom, type FROM tiers WHERE actif = 1 ORDER BY nom")->fetchAll();

$ongletActif = 'reglements';
page_start('Règlements', 'comptes');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Règlements</h1>
    <form method="get"><select class="form-select form-select-sm" name="statut" onchange="this.form.submit()">
        <option value="">Tous les statuts</option>
        <?php foreach (['demande' => 'En attente d\'autorisation', 'autorise' => 'Autorisés', 'execute' => 'Exécutés', 'annule' => 'Annulés'] as $k => $lib): ?>
        <option value="<?= e($k) ?>" <?= $filtre === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
        <?php endforeach; ?>
    </select></form>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th>Numéro</th><th>Bénéficiaire</th><th>Objet</th><th>Mode</th>
            <th class="text-end">Montant</th><th>Autorisations</th><th>Statut</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $r): ?>
        <tr>
            <td><a href="<?= e(base_path('modules/comptes/reglement.php?id=' . (int)$r['id'])) ?>"><?= e($r['numero']) ?></a>
                <?php if ($r['numero_cheque']): ?><br><small class="text-muted">chèque <?= e($r['numero_cheque']) ?></small><?php endif; ?></td>
            <td><?= e($r['beneficiaire_nom']) ?></td>
            <td class="small"><?= e($r['objet']) ?></td>
            <td class="small text-muted"><?= e(MODES_REGLEMENT[$r['mode']] ?? $r['mode']) ?><br><?= e($r['compte_code']) ?></td>
            <td class="text-end"><?= e(htg((float)$r['montant'])) ?>
                <?php if ($r['devise'] !== 'HTG'): ?><br><small class="text-muted"><?= e($r['devise']) ?> <?= e(number_format((float)$r['montant_devise'], 2, ',', ' ')) ?></small><?php endif; ?></td>
            <td class="small text-muted"><?= (int)$r['nb_mandataires'] ?> / 2</td>
            <td><span class="badge text-bg-light border"><?= e($r['statut']) ?></span>
                <?php if ($r['statut'] === 'annule' && $r['motif_annulation']): ?><br><small class="text-muted"><?= e($r['motif_annulation']) ?></small><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="7" class="text-muted p-3">Aucun règlement.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($peutCreer): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Libeller un règlement</div>
    <div class="card-body">
        <p class="text-muted small">Tout décaissement se fait par chèque ou virement ; l'espèce n'est admise que
            par la petite caisse. Tous les paiements sont en gourdes : une devise d'origine se documente,
            elle ne devient jamais l'unité de compte.</p>
        <form method="post" enctype="multipart/form-data" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label small mb-1">Bénéficiaire</label>
                <select class="form-select form-select-sm" name="beneficiaire_id" required>
                    <option value="">—</option>
                    <?php foreach ($beneficiaires as $b): ?>
                    <option value="<?= (int)$b['id'] ?>"><?= e($b['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1">Objet</label>
                <input class="form-control form-control-sm" name="objet" required maxlength="255">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Montant en gourdes</label>
                <input class="form-control form-control-sm text-end" name="montant" inputmode="decimal" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Mode</label>
                <select class="form-select form-select-sm" name="mode">
                    <?php foreach (MODES_REGLEMENT as $k => $lib): ?>
                    <option value="<?= e($k) ?>" <?= $k === param('mode_reglement_defaut', 'virement') ? 'selected' : '' ?>><?= e($lib) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Compte de trésorerie</label>
                <select class="form-select form-select-sm" name="compte_id" required>
                    <?php foreach ($comptesTresorerie as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' — ' . $c['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Numéro de chèque</label>
                <input class="form-control form-control-sm" name="numero_cheque" maxlength="25">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Origine <span class="text-muted">module:référence</span></label>
                <input class="form-control form-control-sm" name="origine_ref" placeholder="dossier:12" maxlength="40">
            </div>
            <div class="col-12"><hr class="my-2"></div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Devise d'origine</label>
                <select class="form-select form-select-sm" name="devise">
                    <option value="HTG">HTG</option><option value="USD">USD</option><option value="EUR">EUR</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Montant en devise</label>
                <input class="form-control form-control-sm text-end" name="montant_devise" inputmode="decimal">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Taux appliqué</label>
                <input class="form-control form-control-sm text-end" name="taux_change" inputmode="decimal">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Preuve du taux</label>
                <input type="file" class="form-control form-control-sm" name="preuve_taux" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <div class="col-12 mt-3">
                <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer la demande</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php page_end();
