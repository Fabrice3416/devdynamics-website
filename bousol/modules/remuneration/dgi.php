<?php
declare(strict_types=1);

/**
 * Remuneration - versement mensuel des acomptes retenus a la DGI.
 *
 * « Le versement est mensuel et s'insere dans la sequence de cloture, une periode
 * ne pouvant etre figee tant que la dette nee dans cette periode n'est pas soldee.
 * Il ne consomme aucune ligne budgetaire puisque l'acompte est deja compris dans
 * le brut impute. Sa fiche d'imputation existe a titre de memoire » (CDC 4.4).
 *
 * Et c'est un reglement comme un autre : deux signatures de mandataires.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/remuneration.php';
require_role(['coordinateur', 'raf']);
require_module('remuneration');

$erreur = null;
$mois = (int)($_GET['mois'] ?? mois_projet() ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Préparer un versement à la DGI');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'preparer') {
        $res = versement_dgi_preparer((int)($_POST['mois'] ?? 0));
        if (!empty($res['success'])) {
            flash_set('success', 'Versement de ' . htg((float)$res['montant']) . ' préparé, dossier ' . $res['numero']
                . '. Son imputation est pour mémoire : elle ne consomme aucune ligne.');
            redirect(base_path('modules/depenses/dossier.php?id=' . (int)$res['dossier_id']));
        }
    } elseif ($action === 'justifier') {
        $res = versement_dgi_justifier((int)($_POST['versement_id'] ?? 0), $_FILES['recu'] ?? null);
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/remuneration/dgi.php?mois=' . $mois));
    }
}

$liste = versements_dgi();
foreach ($liste as $v) {
    versement_dgi_constater((int)$v['id']);
}
$liste = versements_dgi();
$attente = acomptes_du_mois($mois);
$dgiCompte = compte_par_code('DGI');
$dette = $dgiCompte ? -solde_compte((int)$dgiCompte['id']) : 0.0;
$statutsVersement = ['a_verser' => 'À verser', 'verse' => 'Versé', 'justifie' => 'Justifié'];

$ongletActif = 'dgi';
page_start('Versements à la DGI', 'remuneration');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Versements à la DGI</h1>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="form-label small mb-0 text-muted">Mois de projet</label>
        <input type="number" class="form-control form-control-sm" style="width:6rem" name="mois"
               min="1" value="<?= $mois ?>" onchange="this.form.submit()">
    </form>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Dette envers la DGI</div>
        <div class="valeur fs-5"><?= e(htg($dette)) ?></div>
        <small class="text-muted">acomptes retenus, tous mois confondus</small>
    </div></div></div>
    <div class="col-6 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Mois <?= $mois ?>, en attente</div>
        <div class="valeur fs-5"><?= e(htg(array_sum(array_map(fn($a) => (float)$a['acompte'], $attente)))) ?></div>
        <small class="text-muted"><?= count($attente) ?> prestation(s) sans versement</small>
    </div></div></div>
    <div class="col-12 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Clôture du mois <?= $mois ?></div>
        <?php $soldee = dette_dgi_soldee($mois); ?>
        <div class="valeur fs-5"><?= $soldee['soldee'] ? 'possible' : 'bloquée' ?></div>
        <small class="text-muted"><?= e($soldee['motif'] ?? 'la dette du mois est soldée') ?></small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-receipt-cutoff"></i> Versements</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr class="small text-muted">
                    <th>Mois</th><th class="text-end">Montant</th><th>Dossier</th><th>Statut</th><th class="text-end">Reçu scellé</th>
                </tr></thead>
                <tbody>
                <?php foreach ($liste as $v): ?>
                <tr>
                    <td><?= (int)$v['mois'] ?></td>
                    <td class="text-end"><?= e(htg((float)$v['montant_total'])) ?></td>
                    <td class="small text-muted"><?= e($v['dossier_ref'] ?? '—') ?></td>
                    <td><span class="badge text-bg-light border"><?= e($statutsVersement[$v['statut']] ?? $v['statut']) ?></span></td>
                    <td class="text-end">
                        <?php if ($v['statut'] === 'verse'): ?>
                        <form method="post" enctype="multipart/form-data" class="d-flex gap-1 justify-content-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="justifier">
                            <input type="hidden" name="versement_id" value="<?= (int)$v['id'] ?>">
                            <input type="file" class="form-control form-control-sm" name="recu" accept=".pdf,.jpg,.jpeg,.png" required>
                            <button class="btn btn-sm btn-outline-secondary">Verser</button>
                        </form>
                        <?php elseif ($v['recu_scelle_fichier_id']): ?>
                        <span class="text-success"><i class="bi bi-check2"></i> reçu au dossier</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$liste): ?><tr><td colspan="5" class="text-muted p-3">Aucun versement préparé.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul"></i>
                Acomptes du mois <?= $mois ?> en attente</div>
            <table class="table table-sm mb-0">
                <?php foreach ($attente as $a): ?>
                <tr>
                    <td class="small"><?= e($a['intervenant']) ?>
                        <?php if ($a['ligne_code']): ?><br><span class="text-muted">ligne <?= e($a['ligne_code']) ?></span><?php endif; ?></td>
                    <td class="text-end"><?= e(htg((float)$a['acompte'])) ?>
                        <br><small class="text-muted">sur <?= e(htg((float)$a['brut'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$attente): ?><tr><td class="text-muted p-3">Aucun acompte en attente pour ce mois.</td></tr><?php endif; ?>
            </table>
            <?php if ($attente && user_role() === 'raf'): ?>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preparer">
                    <input type="hidden" name="mois" value="<?= $mois ?>">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i>
                        Préparer le versement du mois <?= $mois ?></button>
                    <div class="form-text">Ouvre un dossier de versement dont l'imputation est pour mémoire,
                        à consommation nulle, et qui listera les lignes d'origine des acomptes.</div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php page_end();
