<?php
declare(strict_types=1);

/**
 * Comptes - fiche d'un reglement : autorisations, execution, annulation.
 *
 * « Toute sortie de fonds est autorisee par deux mandataires, distincts de la
 * personne qui libelle le reglement » (CDC 4.1), et « un mandataire beneficiaire
 * est exclu du couple signataire » (annexe H). Le reglement portant son
 * beneficiaire, la regle se verifie au moment de la signature et non apres coup.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_projet();
require_module('comptes');

$id = (int)($_GET['id'] ?? 0);
$r = reglement($id);
if ($r === null) {
    http_response_code(404);
    exit('404 - Reglement inconnu');
}
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Agir sur un règlement');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'valider') {
        if (!user_est_mandataire()) {
            $erreur = 'Seul un mandataire du compte autorise une sortie de fonds.';
        } else {
            $res = reglement_valider($id, (int)user_tiers_id(), (string)($_POST['nature'] ?? 'signature_bancaire'));
            if (!$res['success']) {
                $erreur = $res['error'];
            } else {
                flash_set('success', $res['autorise']
                    ? 'Autorisation enregistrée. Les deux mandataires ont signé : le règlement peut être exécuté.'
                    : 'Autorisation enregistrée. Une seconde est attendue.');
                redirect(base_path('modules/comptes/reglement.php?id=' . $id));
            }
        }
    } elseif ($action === 'executer') {
        if (user_role() !== 'raf' && user_role() !== 'coordinateur') {
            $erreur = 'L\'exécution revient au Responsable Administratif et Financier.';
        } else {
            $res = reglement_executer($id, (string)($_POST['date_reglement'] ?? date('Y-m-d')));
            if (!$res['success']) {
                $erreur = $res['error'];
            } else {
                flash_set('success', 'Règlement exécuté, écriture ' . $res['ecriture_id'] . ' posée en partie double.');
                redirect(base_path('modules/comptes/reglement.php?id=' . $id));
            }
        }
    } elseif ($action === 'annuler') {
        $res = reglement_annuler($id, (string)($_POST['motif'] ?? ''));
        if (!$res['success']) {
            $erreur = $res['error'];
        } else {
            flash_set('success', 'Règlement annulé. Son numéro reste attribué et aucune écriture n\'a été produite.');
            redirect(base_path('modules/comptes/reglement.php?id=' . $id));
        }
    }
    $r = reglement($id);
}

$validations = validations_reglement($id);
$mesRefus = user_est_mandataire() ? reglement_controle_signature($r, (int)user_tiers_id(), 'signature_bancaire') : [];
$ecriture = null;
if ($r['statut'] === 'execute') {
    $se = db()->prepare('SELECT * FROM ecritures WHERE reglement_id = ?');
    $se->execute([$id]);
    $ecriture = $se->fetch() ?: null;
}

$ongletActif = 'reglements';
page_start('Règlement ' . $r['numero'], 'comptes');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Règlement <?= e($r['numero']) ?>
        <small class="text-muted fw-normal">· <?= e($r['statut']) ?></small></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_path('modules/comptes/reglements.php')) ?>">
        <i class="bi bi-arrow-left"></i> Règlements</a>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-receipt"></i> Le règlement</div>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Bénéficiaire</td><td><?= e($r['beneficiaire_nom']) ?>
                    <?php if ($r['beneficiaire_mandataire']): ?><span class="badge text-bg-light border">mandataire</span><?php endif; ?></td></tr>
                <tr><td class="text-muted">Objet</td><td><?= e($r['objet']) ?></td></tr>
                <tr><td class="text-muted">Montant</td><td><?= e(htg((float)$r['montant'])) ?></td></tr>
                <?php if ($r['devise'] !== 'HTG'): ?>
                <tr><td class="text-muted">Origine en devise</td><td><?= e($r['devise']) ?>
                    <?= e(number_format((float)$r['montant_devise'], 2, ',', ' ')) ?> au taux de <?= e($r['taux_change']) ?></td></tr>
                <?php endif; ?>
                <tr><td class="text-muted">Mode</td><td><?= e(MODES_REGLEMENT[$r['mode']] ?? $r['mode']) ?>
                    <?php if ($r['numero_cheque']): ?> n° <?= e($r['numero_cheque']) ?><?php endif; ?></td></tr>
                <tr><td class="text-muted">Compte</td><td><?= e($r['compte_code'] . ' — ' . $r['compte_libelle']) ?></td></tr>
                <tr><td class="text-muted">Préparé par</td><td><?= e($r['preparateur_nom']) ?></td></tr>
                <tr><td class="text-muted">Origine</td><td class="small"><?= e($r['origine_module'] . ' · ' . $r['origine_ref']) ?></td></tr>
                <?php if ($r['date_reglement']): ?>
                <tr><td class="text-muted">Réglé le</td><td><?= e(date_fr($r['date_reglement'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($r['motif_annulation']): ?>
                <tr><td class="text-muted">Annulé</td><td><?= e($r['motif_annulation']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-pen"></i> Autorisations
                <span class="text-muted fw-normal small">— deux mandataires exigés</span></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($validations as $v): ?>
                <li class="list-group-item small"><?= e($v['mandataire_nom']) ?>
                    <span class="text-muted">· <?= e(NATURES_VALIDATION[$v['nature']] ?? $v['nature']) ?>
                        · <?= e(date_fr($v['date'])) ?></span></li>
                <?php endforeach; ?>
                <?php if (!$validations): ?><li class="list-group-item small text-muted">Aucune autorisation.</li><?php endif; ?>
            </ul>
            <?php if (in_array($r['statut'], ['demande', 'autorise'], true) && user_est_mandataire()): ?>
            <div class="card-body">
                <?php if ($mesRefus): ?>
                    <?php foreach ($mesRefus as $m): ?>
                    <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e($m) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="valider">
                    <div class="col-8">
                        <label class="form-label small mb-1">Nature de la validation</label>
                        <select class="form-select form-select-sm" name="nature">
                            <?php foreach (NATURES_VALIDATION as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4"><button class="btn btn-primary btn-sm w-100">Autoriser</button></div>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($r['statut'] === 'autorise'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-play-circle"></i> Exécuter</div>
            <div class="card-body">
                <p class="small text-muted">L'exécution produit exactement une écriture en partie double :
                    le bénéficiaire au débit, la trésorerie au crédit.</p>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="executer">
                    <div class="col-7">
                        <label class="form-label small mb-1">Date du règlement</label>
                        <input type="date" class="form-control form-control-sm" name="date_reglement" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-5"><button class="btn btn-primary btn-sm w-100">Exécuter</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($ecriture): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-journal-check"></i> Écriture produite</div>
            <table class="table table-sm mb-0">
                <?php foreach (mouvements_ecriture((int)$ecriture['id']) as $m): ?>
                <tr>
                    <td class="small"><?= e($m['code']) ?> <span class="text-muted"><?= e($m['libelle']) ?></span></td>
                    <td class="text-end"><?= $m['sens'] === 'D' ? e(htg((float)$m['montant'])) : '' ?></td>
                    <td class="text-end"><?= $m['sens'] === 'C' ? e(htg((float)$m['montant'])) : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if (in_array($r['statut'], ['demande', 'autorise'], true) && in_array(user_role(), ['coordinateur', 'raf'], true)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-x-circle"></i> Annuler</div>
            <div class="card-body">
                <p class="small text-muted">Un chèque annulé est enregistré avec son numéro et son motif,
                    conservé agrafé au chéquier, et ne génère aucune écriture. Son numéro reste attribué.</p>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="annuler">
                    <div class="col-8">
                        <label class="form-label small mb-1">Motif</label>
                        <input class="form-control form-control-sm" name="motif" required maxlength="255">
                    </div>
                    <div class="col-4"><button class="btn btn-outline-secondary btn-sm w-100">Annuler</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
