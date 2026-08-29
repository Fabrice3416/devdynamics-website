<?php
declare(strict_types=1);

/**
 * Financement - tresorerie, sources de revenu et tranches.
 *
 * « Le suivi de tresorerie est distinct du suivi budgetaire. La disponibilite se
 * calcule sur le montant effectivement recu » (CDC 4.10) : l'ecran affiche donc
 * cote a cote ce qui est attendu et ce qui est acquis, et ne confond jamais les deux.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/financement.php';
require_projet();
require_module('financement');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Agir sur le financement');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'source') {
        $res = source_revenu_creer($_POST);
    } elseif ($action === 'contractualiser') {
        $res = tranche_contractualiser((int)($_POST['tranche_id'] ?? 0),
            ($_POST['montant'] ?? '') !== '' ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST['montant']) : null,
            (string)($_POST['declencheur'] ?? ''));
    } elseif ($action === 'encaisser') {
        $res = tranche_encaisser((int)($_POST['tranche_id'] ?? 0),
            (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0')),
            (string)($_POST['date'] ?? date('Y-m-d')), $_FILES['avis'] ?? null);
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/financement/index.php'));
    }
}

$sources = sources_revenu();
$listeTranches = tranches();
$tr = tresorerie();
$peutSaisir = in_array(user_role(), ['coordinateur', 'raf'], true);

$ongletActif = 'tresorerie';
page_start('Financement', 'financement');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-3">Trésorerie et versements attendus</h1>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Reçu</div>
        <div class="valeur fs-5"><?= e(htg($tr['recu'])) ?></div>
        <small class="text-muted">constaté sur avis de crédit</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Contractuel</div>
        <div class="valeur fs-5"><?= e(htg($tr['attendu'])) ?></div>
        <small class="text-muted">saisi à la signature</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Reste à recevoir</div>
        <div class="valeur fs-5"><?= e(htg($tr['a_recevoir'])) ?></div>
        <small class="text-muted">sans montant théorique</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Trésorerie disponible</div>
        <div class="valeur fs-5"><?= e(htg($tr['tresorerie'])) ?></div>
        <small class="text-muted">banque et petite caisse</small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-cash-stack"></i> Tranches et versements</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr class="small text-muted">
                    <th>N°</th><th>Source</th><th>Déclencheur</th>
                    <th class="text-end">Contractuel</th><th class="text-end">Reçu</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($listeTranches as $t): ?>
                <tr>
                    <td><?= (int)$t['numero'] ?><?php if ($t['taux']): ?><br><small class="text-muted"><?= e(rtrim(rtrim(number_format((float)$t['taux'], 2, ',', ' '), '0'), ',')) ?> %</small><?php endif; ?></td>
                    <td class="small"><?= e($t['source']) ?><br><span class="text-muted"><?= e(ORIGINES_REVENU[$t['origine']] ?? $t['origine']) ?></span></td>
                    <td class="small text-muted"><?= e($t['declencheur'] ?? '—') ?></td>
                    <td class="text-end"><?= $t['montant_contractuel'] === null ? '<span class="text-muted">à saisir</span>' : e(htg((float)$t['montant_contractuel'])) ?></td>
                    <td class="text-end"><?= $t['montant_recu'] === null ? '' : e(htg((float)$t['montant_recu'])) ?>
                        <?php if ($t['date_reception']): ?><br><small class="text-muted"><?= e(date_fr($t['date_reception'])) ?></small><?php endif; ?></td>
                    <td class="text-end small">
                        <?php if ($t['avis_credit_fichier_id']): ?>
                        <a href="<?= e(base_path('pdf/serve.php?id=' . (int)$t['avis_credit_fichier_id'])) ?>">avis</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$listeTranches): ?><tr><td colspan="6" class="text-muted p-3">Aucune tranche.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-2"></i> Sources de revenu</div>
            <table class="table table-sm mb-0">
                <?php foreach ($sources as $s): ?>
                <tr>
                    <td class="small"><?= e($s['libelle']) ?><br><span class="text-muted"><?= e(ORIGINES_REVENU[$s['origine']]) ?></span></td>
                    <td class="text-end"><?= e(htg((float)$s['montant_acquis'])) ?>
                        <br><small class="text-muted">sur <?= e(htg((float)$s['montant_attendu'])) ?></small></td>
                    <td class="text-end"><span class="badge text-bg-light border"><?= e(STATUTS_SOURCE[$s['statut']]) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$sources): ?><tr><td class="text-muted p-3">Aucune source déclarée.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php if ($peutSaisir): ?>
    <div class="col-lg-5">
        <?php if (user_role() === 'coordinateur'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-pencil"></i> Contractualiser une tranche</div>
            <div class="card-body">
                <p class="small text-muted">Le montant se fixe à la signature. Là où le bailleur ne fixe pas de taux,
                    il se saisit avec son déclencheur.</p>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="contractualiser">
                    <div class="col-5">
                        <label class="form-label small mb-1">Tranche</label>
                        <select class="form-select form-select-sm" name="tranche_id" required>
                            <?php foreach ($listeTranches as $t): if ($t['montant_recu'] !== null) continue; ?>
                            <option value="<?= (int)$t['id'] ?>">N° <?= (int)$t['numero'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1">Montant contractuel</label>
                        <input class="form-control form-control-sm text-end" name="montant" inputmode="decimal">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Déclencheur</label>
                        <input class="form-control form-control-sm" name="declencheur" maxlength="255">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Enregistrer</button></div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Source de revenu</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="source">
                    <div class="col-5">
                        <label class="form-label small mb-1">Origine</label>
                        <select class="form-select form-select-sm" name="origine">
                            <?php foreach (ORIGINES_REVENU as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1">Montant attendu</label>
                        <input class="form-control form-control-sm text-end" name="montant_attendu" inputmode="decimal" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Libellé</label>
                        <input class="form-control form-control-sm" name="libelle" required maxlength="150">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Déclarer</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (user_role() === 'raf'): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-download"></i> Constater un encaissement</div>
            <div class="card-body">
                <p class="small text-muted">L'avis de crédit est la pièce qui constate la réception. L'encaissement
                    débite la banque et crédite le financement.</p>
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="encaisser">
                    <div class="col-5">
                        <label class="form-label small mb-1">Tranche</label>
                        <select class="form-select form-select-sm" name="tranche_id" required>
                            <?php foreach ($listeTranches as $t): if ($t['montant_recu'] !== null) continue; ?>
                            <option value="<?= (int)$t['id'] ?>">N° <?= (int)$t['numero'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1">Montant reçu</label>
                        <input class="form-control form-control-sm text-end" name="montant" inputmode="decimal" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small mb-1">Date</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1">Avis de crédit</label>
                        <input type="file" class="form-control form-control-sm" name="avis" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <div class="col-12"><button class="btn btn-primary btn-sm">Constater</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php page_end();
