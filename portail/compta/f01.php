<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/DecaissementModel.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$action = (string)($_GET['action'] ?? 'list');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];

// =====================================================================
// Action POST : Rappeler un F01
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rappeler') {
    try {
        verify_csrf();
        check_role(['administrateur', 'comptable']);
        $rid = (int)($_POST['id'] ?? 0);
        $imp = ImputationModel::find($rid);
        if (!$imp) {
            flash_set('danger', 'F01 introuvable.');
        } elseif ((int)$imp['peut_rappeler'] !== 1 || $imp['statut'] !== 'soumis') {
            flash_set('warning', 'Ce F01 ne peut plus etre rappele (un F02 a deja ete cree).');
        } else {
            ImputationModel::rappeler($rid);
            audit_log('f01_rappele', 'Rappel F01 ' . $imp['numero'], 'imputations', $rid);
            flash_set('success', 'F01 ' . $imp['numero'] . ' rappele en brouillon.');
        }
    } catch (Throwable $e) {
        error_log('f01 rappeler error: ' . $e->getMessage());
        flash_set('danger', 'Erreur technique.');
    }
    redirect('/portail/compta/f01.php');
}

// =====================================================================
// Action POST : Creer un F01 (brouillon ou soumis)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur', 'comptable']);
    try {
        verify_csrf();

        $contratId   = (int)($_POST['contrat_id'] ?? 0);
        $ligneId     = (int)($_POST['ligne_budgetaire_id'] ?? 0);
        $rubrique    = (string)($_POST['rubrique'] ?? '');
        $nature      = (string)($_POST['nature_paiement'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $montant     = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['montant'] ?? '0'));
        $montantAlloc = isset($_POST['montant_allocation']) && $_POST['montant_allocation'] !== ''
            ? (float)str_replace([',', ' '], ['.', ''], (string)$_POST['montant_allocation'])
            : null;
        $dateDepense = (string)($_POST['date_depense'] ?? '');
        $statut      = (string)($_POST['statut'] ?? 'brouillon');

        // Gel Petite Caisse (AJUST-02)
        if (ImputationModel::renflouementEnCours() && $nature === 'renflouement_petite_caisse') {
            $errors[] = 'Renflouement Petite Caisse en cours - nouvelle depense PC bloquee (AJUST-02).';
        }

        $rubriques = ['personnel','achats_services','terrain','communication','autre'];
        $natures   = ['honoraires','allocation_deplacement','achat_service','renflouement_petite_caisse','autre'];

        if ($contratId <= 0)            $errors[] = 'Contrat obligatoire.';
        if ($ligneId <= 0)              $errors[] = 'Ligne budgetaire obligatoire.';
        if (!in_whitelist($rubrique, $rubriques)) $errors[] = 'Rubrique invalide.';
        if (!in_whitelist($nature, $natures))     $errors[] = 'Nature paiement invalide.';
        if ($description === '')        $errors[] = 'Description obligatoire.';
        if ($montant <= 0)              $errors[] = 'Montant doit etre positif.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDepense)) {
            $errors[] = 'Date de depense invalide.';
        }
        if (!in_whitelist($statut, ['brouillon','soumis'])) $errors[] = 'Statut invalide.';

        // Recuperation du contrat (verif actif + is_cps01)
        $contrat = null;
        foreach (ImputationModel::contratsActifs() as $c) {
            if ((int)$c['id'] === $contratId) { $contrat = $c; break; }
        }
        if (!$contrat) {
            $errors[] = 'Contrat introuvable ou non actif.';
        }

        // CPS-ACP-01 : double bloc obligatoire
        if ($contrat && (int)$contrat['is_cps01'] === 1) {
            if ($montantAlloc === null || $montantAlloc <= 0) {
                $errors[] = 'CPS-ACP-01 (Coordinateur) : allocation deplacements obligatoire.';
            }
        } else {
            $montantAlloc = null;
        }

        // Blocage CASI biens materiels sans BR conforme
        if ($contrat) {
            $bcOk = ImputationModel::casiBiensBcOk($contratId);
            if ($bcOk === false) {
                $errors[] = 'CASI biens materiels : un Bon de Reception conforme est requis avant la soumission du F01.';
            }
        }

        if (!$errors) {
            $numero = generate_numero('F01', 'imputations');
            $newId = ImputationModel::create([
                'numero'              => $numero,
                'date_depense'        => $dateDepense,
                'contrat_id'          => $contratId,
                'ligne_budgetaire_id' => $ligneId,
                'rubrique'            => $rubrique,
                'nature_paiement'     => $nature,
                'description'         => $description,
                'montant'             => $montant,
                'montant_allocation'  => $montantAlloc,
                'statut'              => $statut,
                'soumis_par'          => (int)user_id(),
            ]);

            audit_log('f01_soumis', "Creation F01 $numero (statut=$statut)", 'imputations', $newId);

            if ($statut === 'soumis') {
                notifier_admins_f01_soumis($numero, $contrat, $montant, $description, $newId);
            }

            flash_set('success', "F01 $numero cree" . ($statut === 'soumis' ? ' et soumis a l Administrateur.' : ' en brouillon.'));
            redirect('/portail/compta/f01.php?action=view&id=' . $newId);
        }
    } catch (Throwable $e) {
        error_log('f01 create error: ' . $e->getMessage());
        $errors[] = 'Erreur technique : ' . $e->getMessage();
    }
}

// =====================================================================
// Action POST : Soumettre un brouillon
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    try {
        verify_csrf();
        check_role(['administrateur', 'comptable']);
        $sid = (int)($_POST['id'] ?? 0);
        $imp = ImputationModel::find($sid);
        if (!$imp || $imp['statut'] !== 'brouillon') {
            flash_set('danger', 'F01 introuvable ou pas au statut brouillon.');
        } else {
            ImputationModel::submit($sid);
            audit_log('f01_soumis', 'Soumission F01 ' . $imp['numero'], 'imputations', $sid);
            $contratStub = [
                'numero' => $imp['contrat_numero'],
                'type_contrat' => $imp['type_contrat'],
                'prestataire' => $imp['prestataire'],
            ];
            notifier_admins_f01_soumis(
                $imp['numero'], $contratStub, (float)$imp['montant'], $imp['description'], $sid
            );
            flash_set('success', 'F01 soumis.');
        }
    } catch (Throwable $e) {
        error_log('f01 submit error: ' . $e->getMessage());
        flash_set('danger', 'Erreur technique.');
    }
    redirect('/portail/compta/f01.php?action=view&id=' . ($sid ?? 0));
}

// =====================================================================
// Notification email Admin lors d'une soumission
// =====================================================================
function notifier_admins_f01_soumis(string $numero, array $contrat, float $montant, string $description, int $impId): void
{
    try {
        $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role='administrateur' AND actif=1");
        while ($admin = $stmt->fetch()) {
            $body = '<p>Bonjour ' . e($admin['nom_complet']) . ',</p>'
                  . '<p>Un nouveau F01 a ete soumis et attend votre validation :</p>'
                  . '<ul>'
                  . '<li><strong>Numero :</strong> ' . e($numero) . '</li>'
                  . '<li><strong>Contrat :</strong> ' . e($contrat['numero']) . ' (' . e($contrat['type_contrat']) . ')</li>'
                  . '<li><strong>Beneficiaire :</strong> ' . e($contrat['prestataire']) . '</li>'
                  . '<li><strong>Montant :</strong> ' . format_htg($montant) . '</li>'
                  . '<li><strong>Description :</strong> ' . e($description) . '</li>'
                  . '</ul>'
                  . '<p><a href="https://dev-dynamics.org/portail/compta/f02.php">Acceder au portail</a></p>';
            alerte_envoyer('f01_soumis', $admin['email'],
                'Nouveau F01 a valider : ' . $numero, $body,
                ['type' => 'imputations', 'id' => $impId]);
        }
    } catch (Throwable $e) {
        error_log('notifier_admins_f01_soumis: ' . $e->getMessage());
    }
}

// =====================================================================
// ROUTAGE
// =====================================================================
$pageTitle  = 'F01 - Fiches d Imputation';
$activeMenu = 'compta';

if ($action === 'view' && $id > 0) {
    $imp = ImputationModel::find($id);
    if (!$imp) {
        flash_set('danger', 'F01 introuvable.');
        redirect('/portail/compta/f01.php');
    }
    $f02 = DecaissementModel::findByImputation($id);
    require __DIR__ . '/../includes/header.php';
    render_view($imp, $f02);
    require __DIR__ . '/../includes/footer.php';

} elseif ($action === 'new') {
    check_role(['administrateur', 'comptable']);
    $contrats = ImputationModel::contratsActifs();
    $lignes   = ImputationModel::lignesBudgetairesActives();
    $gelPC    = ImputationModel::renflouementEnCours();
    require __DIR__ . '/../includes/header.php';
    render_form($contrats, $lignes, $errors, $_POST, $gelPC);
    require __DIR__ . '/../includes/footer.php';

} else {
    $filters = [
        'statut'   => $_GET['statut']   ?? '',
        'mois'     => $_GET['mois']     ?? '',
        'rubrique' => $_GET['rubrique'] ?? '',
        'search'   => $_GET['search']   ?? '',
    ];
    $page = max(1, (int)($_GET['page'] ?? 1));
    $result = ImputationModel::paginate($filters, $page, 25);
    require __DIR__ . '/../includes/header.php';
    render_list($result['rows'], $result['total'], $page, $filters);
    require __DIR__ . '/../includes/footer.php';
}

// =====================================================================
// VIEWS
// =====================================================================
function render_list(array $rows, int $total, int $page, array $filters): void
{
    $role = user_role();
?>
<div class="d-flex justify-content-between flex-wrap align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-file-earmark-plus"></i> F01 - Fiches d'Imputation</h1>
        <small class="text-muted"><?= (int)$total ?> entree(s)</small>
    </div>
    <?php if (in_array($role, ['administrateur','comptable'], true)): ?>
    <a href="?action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau F01</a>
    <?php endif; ?>
</div>

<form method="get" class="card shadow-sm border-0 mb-3">
    <div class="card-body row g-2">
        <div class="col-md-3">
            <select name="statut" class="form-select form-select-sm">
                <option value="">-- Tous statuts --</option>
                <?php foreach (['brouillon','soumis','valide','rejete'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($filters['statut']===$s)?'selected':'' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="mois" class="form-select form-select-sm">
                <option value="">-- Tous mois --</option>
                <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= ((int)$filters['mois']===$m)?'selected':'' ?>><?= e(mois_fr($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="rubrique" class="form-select form-select-sm">
                <option value="">-- Toutes rubriques --</option>
                <?php foreach (['personnel','achats_services','terrain','communication','autre'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($filters['rubrique']===$r)?'selected':'' ?>><?= e(ucfirst(str_replace('_',' ',$r))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <input type="search" name="search" class="form-control form-control-sm"
                   placeholder="Rechercher (numero, description, prestataire)"
                   value="<?= e($filters['search']) ?>">
        </div>
        <div class="col-md-1">
            <button class="btn btn-sm btn-secondary w-100"><i class="bi bi-search"></i></button>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Numero</th><th>Date</th><th>Contrat</th><th>Beneficiaire</th>
                    <th>Ligne</th><th class="text-end">Montant</th><th>Statut</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Aucun F01 enregistre.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $bd = ['brouillon'=>'secondary','soumis'=>'warning','valide'=>'success','rejete'=>'danger'][$r['statut']] ?? 'secondary';
            ?>
                <tr>
                    <td>
                        <a href="?action=view&id=<?= (int)$r['id'] ?>" class="text-decoration-none fw-bold"><?= e($r['numero']) ?></a>
                        <?php if ((int)$r['is_cps01']===1): ?><span class="badge bg-info ms-1">CPS-01</span><?php endif; ?>
                        <?php if ((int)$r['is_retroactif']===1): ?><span class="badge bg-warning ms-1">Retroactif</span><?php endif; ?>
                    </td>
                    <td><?= e(date('d/m/Y', strtotime($r['date_depense']))) ?></td>
                    <td><small><?= e($r['contrat_numero']) ?> <span class="badge bg-light text-dark"><?= e($r['type_contrat']) ?></span></small></td>
                    <td><?= e($r['prestataire']) ?></td>
                    <td><small><?= e($r['ligne_code']) ?></small></td>
                    <td class="text-end font-monospace"><?= format_htg((float)$r['montant'] + (float)($r['montant_allocation'] ?? 0)) ?></td>
                    <td><span class="badge bg-<?= $bd ?>"><?= e($r['statut']) ?></span></td>
                    <td>
                        <?php if ($r['statut']==='soumis' && (int)$r['peut_rappeler']===1 && !$r['decaissement_id']
                              && in_array(user_role(),['administrateur','comptable'],true)): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Rappeler ce F01 en brouillon ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rappeler">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning" title="Rappeler"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
$perPage = 25;
$nbPages = max(1, (int)ceil($total / $perPage));
if ($nbPages > 1):
    $qs = http_build_query(array_filter($filters));
?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
    <?php for ($p=1; $p <= $nbPages; $p++): ?>
        <li class="page-item <?= ($p===$page)?'active':'' ?>">
            <a class="page-link" href="?<?= $qs ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
    <?php endfor; ?>
</ul></nav>
<?php endif;
}

function render_form(array $contrats, array $lignes, array $errors, array $post, bool $gelPC): void
{
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-file-earmark-plus"></i> Nouveau F01</h1>
    <a href="/portail/compta/f01.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<?php if ($gelPC): ?>
    <div class="alert alert-warning">
        <i class="bi bi-snow"></i> <strong>Gel Petite Caisse en cours</strong> -
        Un renflouement est en traitement. Les nouvelles depenses de nature
        "renflouement_petite_caisse" sont bloquees jusqu'au versement (AJUST-02).
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Date de depense <span class="text-danger">*</span></label>
                <input type="date" name="date_depense" class="form-control" required
                       value="<?= e($post['date_depense'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-8">
                <label class="form-label">Contrat <span class="text-danger">*</span></label>
                <select name="contrat_id" id="contrat_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($contrats as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" data-cps01="<?= (int)$c['is_cps01'] ?>"
                                <?= ((int)($post['contrat_id'] ?? 0)===(int)$c['id'])?'selected':'' ?>>
                            <?= e($c['numero']) ?> - <?= e($c['type_contrat']) ?> - <?= e($c['prestataire']) ?>
                            <?php if ((int)$c['is_cps01']===1): ?> (CPS-01, double bloc)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">CPS-ACP-01 (Coordinateur) : deux montants obligatoires.</small>
            </div>

            <div class="col-md-6">
                <label class="form-label">Ligne budgetaire <span class="text-danger">*</span></label>
                <select name="ligne_budgetaire_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($lignes as $lb): ?>
                        <option value="<?= (int)$lb['id'] ?>"
                                <?= ((int)($post['ligne_budgetaire_id'] ?? 0)===(int)$lb['id'])?'selected':'' ?>>
                            <?= e($lb['code']) ?> - <?= e($lb['libelle']) ?> (<?= format_htg($lb['budget_initial_htg']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Rubrique <span class="text-danger">*</span></label>
                <select name="rubrique" class="form-select" required>
                    <?php foreach (['personnel','achats_services','terrain','communication','autre'] as $r): ?>
                        <option value="<?= $r ?>" <?= (($post['rubrique'] ?? '')===$r)?'selected':'' ?>><?= e(ucfirst(str_replace('_',' ',$r))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Nature paiement <span class="text-danger">*</span></label>
                <select name="nature_paiement" class="form-select" required>
                    <?php foreach (['honoraires','allocation_deplacement','achat_service','renflouement_petite_caisse','autre'] as $n): ?>
                        <option value="<?= $n ?>" <?= (($post['nature_paiement'] ?? 'honoraires')===$n)?'selected':'' ?>><?= e(ucfirst(str_replace('_',' ',$n))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="3" required><?= e($post['description'] ?? '') ?></textarea>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Montant <span id="lbl-honoraires" style="display:none;">(Honoraires - DGI 2%)</span>
                    <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <input type="number" name="montant" class="form-control text-end" step="0.01" min="0.01" required
                           value="<?= e((string)($post['montant'] ?? '')) ?>">
                    <span class="input-group-text">HTG</span>
                </div>
            </div>

            <div class="col-md-6" id="bloc-allocation" style="display:none;">
                <label class="form-label">Allocation deplacements (hors DGI) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="number" name="montant_allocation" class="form-control text-end" step="0.01" min="0"
                           value="<?= e((string)($post['montant_allocation'] ?? '')) ?>">
                    <span class="input-group-text">HTG</span>
                </div>
                <small class="text-muted">CPS-ACP-01 uniquement. Sera paye sur un cheque separe.</small>
            </div>
        </div>

        <hr class="my-4">

        <div class="d-flex justify-content-between flex-wrap gap-2">
            <button name="statut" value="brouillon" class="btn btn-outline-secondary">
                <i class="bi bi-save"></i> Enregistrer en brouillon
            </button>
            <button name="statut" value="soumis" class="btn btn-primary"
                    onclick="return confirm('Soumettre ce F01 a l Administrateur ?');">
                <i class="bi bi-send-check"></i> Soumettre a l Administrateur
            </button>
        </div>
    </div>
</form>

<script>
(function() {
    const sel = document.getElementById('contrat_id');
    const blocAlloc = document.getElementById('bloc-allocation');
    const lblHono   = document.getElementById('lbl-honoraires');
    function refresh() {
        const opt = sel.options[sel.selectedIndex];
        const cps = opt && opt.getAttribute('data-cps01') === '1';
        blocAlloc.style.display = cps ? '' : 'none';
        lblHono.style.display   = cps ? '' : 'none';
        const inp = blocAlloc.querySelector('input[name="montant_allocation"]');
        inp.required = !!cps;
        if (!cps) inp.value = '';
    }
    sel.addEventListener('change', refresh);
    refresh();
})();
</script>
<?php
}

function render_view(array $imp, ?array $f02): void
{
    $bd = ['brouillon'=>'secondary','soumis'=>'warning','valide'=>'success','rejete'=>'danger'][$imp['statut']] ?? 'secondary';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">F01 <?= e($imp['numero']) ?>
            <span class="badge bg-<?= $bd ?>"><?= e($imp['statut']) ?></span>
            <?php if ((int)$imp['is_cps01']===1): ?><span class="badge bg-info">CPS-01</span><?php endif; ?>
            <?php if ((int)$imp['is_retroactif']===1): ?><span class="badge bg-warning">Retroactif</span><?php endif; ?>
        </h1>
        <small class="text-muted">Cree le <?= e(date('d/m/Y H:i', strtotime($imp['created_at']))) ?></small>
    </div>
    <div>
        <a href="/portail/pdf/render.php?type=f01&id=<?= (int)$imp['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
            <i class="bi bi-file-earmark-pdf"></i> PDF
        </a>
        <a href="/portail/compta/f01.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Date depense</dt><dd class="col-sm-8"><?= e(date('d/m/Y', strtotime($imp['date_depense']))) ?></dd>
                    <dt class="col-sm-4">Contrat</dt><dd class="col-sm-8"><?= e($imp['contrat_numero']) ?> (<?= e($imp['type_contrat']) ?>)</dd>
                    <dt class="col-sm-4">Beneficiaire</dt><dd class="col-sm-8"><?= e($imp['prestataire']) ?></dd>
                    <dt class="col-sm-4">Ligne budgetaire</dt><dd class="col-sm-8"><?= e($imp['ligne_code']) ?> - <?= e($imp['ligne_libelle']) ?></dd>
                    <dt class="col-sm-4">Rubrique</dt><dd class="col-sm-8"><?= e(ucfirst(str_replace('_',' ',$imp['rubrique']))) ?></dd>
                    <dt class="col-sm-4">Nature</dt><dd class="col-sm-8"><?= e(ucfirst(str_replace('_',' ',$imp['nature_paiement']))) ?></dd>
                    <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(e($imp['description'])) ?></dd>
                    <dt class="col-sm-4">Montant</dt>
                    <dd class="col-sm-8 fw-bold font-monospace"><?= format_htg($imp['montant']) ?>
                        <?php if ((int)$imp['is_cps01']===1 && $imp['montant_allocation']): ?>
                            <br><small class="text-muted">+ allocation : <?= format_htg($imp['montant_allocation']) ?> (hors DGI)</small>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Workflow</strong></div>
            <div class="card-body">
                <ol class="mb-0">
                    <li>F01 (Imputation) <span class="badge bg-success">OK</span></li>
                    <li>F02 (Decaissement)
                        <?php if ($f02): ?>
                            <span class="badge bg-success">OK</span>
                            <small class="text-muted d-block ms-3">N° <?= e($f02['numero']) ?> - <?= format_htg($f02['total_net_a_verser']) ?></small>
                        <?php else: ?>
                            <span class="badge bg-secondary">En attente</span>
                        <?php endif; ?>
                    </li>
                    <li>ASF <span class="badge bg-secondary">A venir</span></li>
                    <li>NH <span class="badge bg-secondary">A venir</span></li>
                    <li>FRP <span class="badge bg-secondary">A venir</span></li>
                </ol>
            </div>
            <div class="card-footer bg-white">
                <?php if ($imp['statut']==='brouillon' && in_array(user_role(),['administrateur','comptable'],true)): ?>
                    <form method="post" class="mb-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit">
                        <input type="hidden" name="id" value="<?= (int)$imp['id'] ?>">
                        <button class="btn btn-primary w-100"><i class="bi bi-send-check"></i> Soumettre a l Administrateur</button>
                    </form>
                <?php endif; ?>

                <?php if ($imp['statut']==='soumis' && (int)$imp['peut_rappeler']===1 && !$f02
                        && in_array(user_role(),['administrateur','comptable'],true)): ?>
                    <form method="post" onsubmit="return confirm('Rappeler ce F01 en brouillon ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rappeler">
                        <input type="hidden" name="id" value="<?= (int)$imp['id'] ?>">
                        <button class="btn btn-outline-warning w-100"><i class="bi bi-arrow-counterclockwise"></i> Rappeler en brouillon</button>
                    </form>
                <?php elseif ($f02): ?>
                    <a href="/portail/compta/f02.php?action=view&id=<?= (int)$f02['id'] ?>" class="btn btn-outline-primary w-100">
                        <i class="bi bi-cash-coin"></i> Voir le F02 associe
                    </a>
                <?php elseif ($imp['statut']==='soumis' && user_role()==='administrateur'): ?>
                    <a href="/portail/compta/f02.php?action=new&imputation_id=<?= (int)$imp['id'] ?>" class="btn btn-primary w-100">
                        <i class="bi bi-cash-coin"></i> Valider F02 maintenant
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
}
