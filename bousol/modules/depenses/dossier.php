<?php
declare(strict_types=1);

/**
 * Depenses - la fiche d'un dossier : imputation, pieces, concurrence, approbation,
 * reglement et cloture.
 *
 * Deux des neuf etapes sont bloquantes. L'imputation refuse d'aboutir si l'un des
 * sept controles budgetaires echoue. Le reglement refuse d'etre demande tant que
 * les pieces prealables au paiement ne sont pas reunies - le recu signe du
 * beneficiaire au premier chef, dont la regle ne souffre aucune derogation.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/depenses.php';
require_projet();
require_module('depenses');

$id = (int)($_GET['id'] ?? 0);
$d = dossier($id);
if ($d === null) {
    http_response_code(404);
    exit('404 - Dossier inconnu');
}
dossier_constater_reglement($id);
$d = dossier($id);

$peutSaisir = in_array(user_role(), ['coordinateur', 'raf'], true);
$erreur = null;
$alertes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutSaisir) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    require_phase_execution('Agir sur un dossier de dépense');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'imputer') {
        $res = dossier_imputer($id, (int)($_POST['ligne_id'] ?? 0),
            (float)str_replace([' ', ','], ['', '.'], (string)($_POST['quantite'] ?? '0')),
            (float)str_replace([' ', ','], ['', '.'], (string)($_POST['valeur_unitaire'] ?? '0')),
            (string)($_POST['unite'] ?? 'forfait'));
    } elseif ($action === 'deroger') {
        $res = dossier_deroger_quantite($id, (string)($_POST['motif'] ?? ''));
    } elseif ($action === 'piece') {
        $res = piece_verser((int)($_POST['piece_id'] ?? 0), $_FILES['scan'] ?? [],
            (string)($_POST['date_piece'] ?? '') ?: null);
    } elseif ($action === 'sans_objet') {
        $res = piece_sans_objet((int)($_POST['piece_id'] ?? 0), (string)($_POST['motif'] ?? ''));
    } elseif ($action === 'proforma') {
        $res = proforma_ajouter($id, (int)($_POST['fournisseur_id'] ?? 0),
            (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0')),
            $_FILES['proforma'] ?? null);
    } elseif ($action === 'retenir') {
        $res = proforma_retenir((int)($_POST['proforma_id'] ?? 0), (string)($_POST['motif'] ?? ''));
    } elseif ($action === 'approuver') {
        $res = dossier_approuver($id);
    } elseif ($action === 'reglement') {
        $res = dossier_demander_reglement($id, [
            'mode'          => (string)($_POST['mode'] ?? 'virement'),
            'numero_cheque' => (string)($_POST['numero_cheque'] ?? ''),
            'compte_id'     => (int)($_POST['compte_id'] ?? 0),
        ]);
        $alertes = $res['alertes'] ?? [];
    } elseif ($action === 'clore') {
        $res = dossier_clore($id);
    } elseif ($action === 'abandonner') {
        $res = dossier_abandonner($id, (string)($_POST['motif'] ?? ''));
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.' . ($alertes ? ' ' . implode(' ', $alertes) : ''));
        redirect(base_path('modules/depenses/dossier.php?id=' . $id));
    }
    $d = dossier($id);
}

$pieces = pieces_dossier($id);
$imputation = imputation_dossier($id);
$proformas = proformas_dossier($id);
$manquantesAvant = dossier_pieces_manquantes($id, 'avant');
$manquantes = dossier_pieces_manquantes($id);
$lignes = array_filter(budget_lignes(), fn($l) => $l['nature'] === 'imputable');
$comptesTresorerie = array_values(array_filter(comptes_plan(), fn($c) => in_array($c['type'], COMPTES_TRESORERIE, true)));
$fournisseurs = db()->query('SELECT id, nom FROM tiers WHERE actif = 1 ORDER BY nom')->fetchAll();

$ongletActif = 'tous';
page_start('Dossier ' . $d['numero'], 'depenses');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Dossier <?= e($d['numero']) ?>
        <small class="text-muted fw-normal">· <?= e(STATUTS_DOSSIER[$d['statut']] ?? $d['statut']) ?></small></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_path('modules/depenses/')) ?>">
        <i class="bi bi-arrow-left"></i> Dossiers</a>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>
<?php foreach ($alertes as $a): ?><div class="alert alert-warning py-2"><i class="bi bi-clock-history"></i> <?= e($a) ?></div><?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-folder"></i> Le dossier</div>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Type</td><td><?= e(TYPES_DOSSIER[$d['type']]['libelle'] ?? $d['type']) ?></td></tr>
                <tr><td class="text-muted">Objet</td><td><?= e($d['objet']) ?></td></tr>
                <tr><td class="text-muted">Bénéficiaire</td><td><?= e($d['tiers_nom']) ?>
                    <?php if ($d['nif']): ?><br><small class="text-muted">NIF <?= e($d['nif']) ?></small><?php endif; ?></td></tr>
                <tr><td class="text-muted">Ouvert par</td><td><?= e($d['ouvreur_nom']) ?></td></tr>
                <?php if ($d['derogation_quantite_motif']): ?>
                <tr><td class="text-muted">Dérogation</td><td class="small"><?= e($d['derogation_quantite_motif']) ?></td></tr>
                <?php endif; ?>
                <?php if ($d['reglement_ref']): ?>
                <tr><td class="text-muted">Règlement</td><td><?= e($d['reglement_ref']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-nested"></i> Imputation budgétaire</div>
            <div class="card-body">
                <?php if ($imputation): ?>
                <p class="mb-2"><strong><?= e($imputation['ligne_code']) ?></strong> <?= e($imputation['ligne_libelle']) ?><br>
                    <?= e(rtrim(rtrim(number_format((float)$imputation['quantite'], 2, ',', ' '), '0'), ',')) ?>
                    <?= e(UNITES[$imputation['unite']] ?? '') ?> × <?= e(htg((float)$imputation['valeur_unitaire'])) ?>
                    = <strong><?= e(htg((float)$imputation['montant'])) ?></strong>
                    <?php if ($imputation['numero_piece']): ?><br><span class="text-muted">Pièce comptable <?= e($imputation['numero_piece']) ?></span><?php endif; ?></p>
                <?php else: ?>
                <p class="small text-muted">Non imputé. L'imputation est bloquante : les sept contrôles budgétaires s'y appliquent.</p>
                <?php endif; ?>
                <?php if (user_role() === 'raf' && !in_array($d['statut'], ['clos', 'abandonne', 'regle'], true)): ?>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="imputer">
                    <div class="col-12">
                        <label class="form-label small mb-1">Ligne budgétaire</label>
                        <select class="form-select form-select-sm" name="ligne_id" required>
                            <?php foreach ($lignes as $code => $l): ?>
                            <option value="<?= (int)$l['id'] ?>" <?= $imputation && (int)$imputation['ligne_id'] === (int)$l['id'] ? 'selected' : '' ?>>
                                <?= e($l['code'] . ' — ' . $l['libelle']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Unité</label>
                        <select class="form-select form-select-sm" name="unite">
                            <?php foreach (UNITES as $u => $lib): ?>
                            <option value="<?= e($u) ?>" <?= $imputation && $imputation['unite'] === $u ? 'selected' : '' ?>><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="form-label small mb-1">Qté</label>
                        <input class="form-control form-control-sm text-end" name="quantite" inputmode="decimal" required
                               value="<?= $imputation ? e(rtrim(rtrim(number_format((float)$imputation['quantite'], 2, '.', ''), '0'), '.')) : '1' ?>">
                    </div>
                    <div class="col-5">
                        <label class="form-label small mb-1">Valeur unitaire</label>
                        <input class="form-control form-control-sm text-end" name="valeur_unitaire" inputmode="decimal" required
                               value="<?= $imputation ? e(number_format((float)$imputation['valeur_unitaire'], 2, '.', '')) : '' ?>">
                    </div>
                    <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Imputer</button></div>
                </form>
                <?php endif; ?>

                <?php if (user_role() === 'coordinateur' && !in_array($d['statut'], ['clos', 'abandonne', 'regle'], true)): ?>
                <hr>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deroger">
                    <div class="col-8">
                        <label class="form-label small mb-1">Déroger au contrôle de quantité
                            <span class="text-muted">— motif écrit</span></label>
                        <input class="form-control form-control-sm" name="motif" maxlength="255"
                               value="<?= e($d['derogation_quantite_motif'] ?? '') ?>">
                    </div>
                    <div class="col-4"><button class="btn btn-outline-secondary btn-sm w-100">Accorder</button></div>
                    <div class="col-12"><div class="form-text">La dérogation s'accorde avant l'imputation.
                        Celui qui lève le contrôle n'est pas celui qui impute.</div></div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($proformas || array_filter($pieces, fn($p) => $p['type'] === 'proforma' && $p['obligatoire'])): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-files"></i> Mise en concurrence</div>
            <table class="table table-sm mb-0">
                <?php foreach ($proformas as $p): ?>
                <tr class="<?= $p['retenu'] ? 'fw-semibold' : '' ?>">
                    <td class="small"><?= e($p['fournisseur_nom']) ?>
                        <?php if ($p['motif_choix']): ?><br><span class="text-muted"><?= e($p['motif_choix']) ?></span><?php endif; ?></td>
                    <td class="text-end"><?= e(htg((float)$p['montant'])) ?></td>
                    <td class="text-end">
                        <?php if ($p['retenu']): ?><span class="badge text-bg-light border">retenue</span>
                        <?php elseif ($peutSaisir): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="retenir">
                            <input type="hidden" name="proforma_id" value="<?= (int)$p['id'] ?>">
                            <input class="form-control form-control-sm d-inline-block" style="width:9rem" name="motif" placeholder="motif si non moins-disante">
                            <button class="btn btn-sm btn-link p-0">retenir</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if ($peutSaisir && !in_array($d['statut'], ['clos', 'abandonne', 'regle'], true)): ?>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="proforma">
                    <div class="col-5">
                        <label class="form-label small mb-1">Fournisseur</label>
                        <select class="form-select form-select-sm" name="fournisseur_id" required>
                            <option value="">—</option>
                            <?php foreach ($fournisseurs as $f): ?>
                            <option value="<?= (int)$f['id'] ?>"><?= e($f['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="form-label small mb-1">Montant</label>
                        <input class="form-control form-control-sm text-end" name="montant" inputmode="decimal" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Scan</label>
                        <input type="file" class="form-control form-control-sm" name="proforma" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Ajouter le proforma</button></div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-check2-square"></i> Pièces du dossier</span>
                <span class="small fw-normal text-muted"><?= count($manquantes) ?> obligatoire(s) attendue(s)</span>
            </div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <?php foreach ($pieces as $p): ?>
                <tr>
                    <td>
                        <?= e($p['libelle']) ?>
                        <?php if (!$p['obligatoire']): ?><span class="badge text-bg-light border">facultative</span><?php endif; ?>
                        <?php if ($p['moment'] === 'apres'): ?><span class="badge text-bg-light border">après paiement</span><?php endif; ?>
                        <?php if ($p['date_piece']): ?><br><small class="text-muted"><?= e(date_fr($p['date_piece'])) ?></small><?php endif; ?>
                    </td>
                    <td class="text-end" style="width:20rem">
                        <?php if ($p['statut'] === 'recue'): ?>
                            <span class="text-success"><i class="bi bi-check2"></i> reçue</span>
                            <?php if ($p['empreinte']): ?><br><small class="text-muted"><?= e(substr($p['empreinte'], 0, 12)) ?>…</small><?php endif; ?>
                        <?php elseif ($p['statut'] === 'sans_objet'): ?>
                            <span class="text-muted">sans objet</span>
                        <?php elseif ($peutSaisir && !in_array($d['statut'], ['clos', 'abandonne'], true)): ?>
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-1 justify-content-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="piece">
                                <input type="hidden" name="piece_id" value="<?= (int)$p['id'] ?>">
                                <input type="date" class="form-control form-control-sm" style="width:9rem" name="date_piece" value="<?= e(date('Y-m-d')) ?>">
                                <input type="file" class="form-control form-control-sm" name="scan" accept=".pdf,.jpg,.jpeg,.png" required>
                                <button class="btn btn-sm btn-outline-secondary">Verser</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">attendue</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-right-circle"></i> Cycle</div>
            <div class="card-body">
                <?php if ($d['statut'] === 'abandonne'): ?>
                <p class="text-muted mb-0">Dossier abandonné. Il n'a consommé aucun numéro de pièce comptable.</p>

                <?php elseif ($d['statut'] === 'clos'): ?>
                <p class="text-muted mb-0">Dossier clos, toutes ses pièces réunies.</p>

                <?php else: ?>
                    <?php if ($d['statut'] !== 'approuve' && $d['statut'] !== 'regle' && user_role() === 'coordinateur'): ?>
                    <form method="post" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approuver">
                        <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle"></i> Approuver le dossier</button>
                        <span class="form-text ms-2">L'approbation revient au Coordinateur, et jamais au bénéficiaire.</span>
                    </form>
                    <?php endif; ?>

                    <?php if ($d['statut'] === 'approuve'): ?>
                        <?php if ($manquantesAvant): ?>
                        <div class="alert alert-warning py-2 small">
                            <i class="bi bi-exclamation-triangle"></i>
                            Règlement impossible tant que ces pièces manquent : <?= e(implode(', ', $manquantesAvant)) ?>.
                            Le reçu du bénéficiaire est signé puis scanné <strong>avant</strong> tout mouvement de fonds,
                            et aucun rôle ne peut lever cette règle.
                        </div>
                        <?php else: ?>
                        <form method="post" class="row g-2 align-items-end mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reglement">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Mode</label>
                                <select class="form-select form-select-sm" name="mode">
                                    <?php foreach (MODES_REGLEMENT as $k => $lib): ?>
                                    <option value="<?= e($k) ?>" <?= $k === param('mode_reglement_defaut', 'virement') ? 'selected' : '' ?>><?= e($lib) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Compte</label>
                                <select class="form-select form-select-sm" name="compte_id" required>
                                    <?php foreach ($comptesTresorerie as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' — ' . $c['libelle']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1">N° chèque</label>
                                <input class="form-control form-control-sm" name="numero_cheque" maxlength="25">
                            </div>
                            <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Demander</button></div>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($d['statut'] === 'regle'): ?>
                    <form method="post" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clore">
                        <button class="btn btn-primary btn-sm" <?= $manquantes ? 'disabled' : '' ?>><i class="bi bi-lock"></i> Clore le dossier</button>
                        <?php if ($manquantes): ?>
                        <span class="form-text ms-2">Encore attendues : <?= e(implode(', ', $manquantes)) ?>.</span>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>

                    <?php if ($peutSaisir && $d['statut'] !== 'regle'): ?>
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="abandonner">
                        <div class="col-md-8">
                            <label class="form-label small mb-1">Abandonner, avec son motif</label>
                            <input class="form-control form-control-sm" name="motif" maxlength="255">
                        </div>
                        <div class="col-md-4"><button class="btn btn-outline-secondary btn-sm w-100">Abandonner</button></div>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php page_end();
