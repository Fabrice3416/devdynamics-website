<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/DecaissementModel.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$action = (string)($_GET['action'] ?? 'list');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$impId  = isset($_GET['imputation_id']) ? (int)$_GET['imputation_id'] : 0;
$errors = [];

// =====================================================================
// Action POST : Creer un F02 (validation administrateur)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur']);
    try {
        verify_csrf();

        $impId = (int)($_POST['imputation_id'] ?? 0);
        $imp = ImputationModel::find($impId);
        if (!$imp) {
            $errors[] = 'Imputation introuvable.';
        } elseif ($imp['statut'] !== 'soumis') {
            $errors[] = 'L imputation doit etre au statut "soumis" pour creer un F02.';
        }

        // Verifier qu'aucun F02 n'existe deja
        if ($imp && DecaissementModel::findByImputation($impId) !== null) {
            $errors[] = 'Un F02 existe deja pour cette imputation.';
        }

        // Blocage : BR non conforme pour CASI biens materiels
        if ($imp && $imp['type_contrat'] === 'CASI') {
            $stmt = db()->prepare(
                "SELECT bc.type_commande, bc.statut AS bc_statut, br.statut_livraison
                   FROM bons_commande bc
                   LEFT JOIN bons_reception br ON br.bon_commande_id = bc.id
                  WHERE bc.contrat_id = ?
                  ORDER BY bc.id DESC LIMIT 1"
            );
            $stmt->execute([(int)$imp['contrat_id']]);
            $bcInfo = $stmt->fetch();
            if ($bcInfo && $bcInfo['type_commande'] === 'biens_materiels') {
                if ($bcInfo['statut_livraison'] === 'non_conforme') {
                    $errors[] = 'Bon de Reception non conforme - F02 bloque jusqu a resolution (br_non_conforme).';
                } elseif ($bcInfo['bc_statut'] !== 'recu' && $bcInfo['bc_statut'] !== 'partiellement_recu') {
                    $errors[] = 'CASI biens materiels : Bon de Reception requis avant F02.';
                }
            }
        }

        $modePaiement = (string)($_POST['mode_paiement'] ?? 'cheque');
        $numCheque    = trim((string)($_POST['numero_cheque'] ?? ''));
        $numChequeAlloc = trim((string)($_POST['numero_cheque_allocation'] ?? ''));
        $justifVirement = trim((string)($_POST['justification_virement'] ?? ''));
        $observations = trim((string)($_POST['observations'] ?? ''));

        if (!in_whitelist($modePaiement, ['cheque', 'virement'])) {
            $errors[] = 'Mode de paiement invalide.';
        }

        // Politique : cheque par defaut
        if ($modePaiement === 'cheque') {
            if ($numCheque === '') {
                $errors[] = 'Numero de cheque obligatoire.';
            }
            if ($imp && (int)$imp['is_cps01'] === 1) {
                if ($numChequeAlloc === '') {
                    $errors[] = 'CPS-ACP-01 : second numero de cheque (allocation) obligatoire.';
                }
                if ($numCheque === $numChequeAlloc) {
                    $errors[] = 'Les deux numeros de cheque doivent etre differents.';
                }
            }
        } else {
            // virement : justification obligatoire
            if ($justifVirement === '') {
                $errors[] = 'Justification du virement obligatoire (cheque par defaut).';
            }
            $numCheque = null;
            $numChequeAlloc = null;
        }

        // Upload : preuve de paiement OBLIGATOIRE
        $preuvePath = null;
        if (isset($_FILES['preuve_paiement']) && $_FILES['preuve_paiement']['error'] !== UPLOAD_ERR_NO_FILE) {
            $destDir = __DIR__ . '/../storage/preuves_paiement/' . date('Y') . '/M' . str_pad((string)date('m'), 2, '0', STR_PAD_LEFT);
            $up = handle_upload($_FILES['preuve_paiement'], $destDir);
            if (!$up['success']) {
                $errors[] = 'Preuve de paiement : ' . ($up['error'] ?? 'echec upload');
            } else {
                $preuvePath = storage_relative_path($up['path']);
            }
        } else {
            $errors[] = 'Preuve de paiement obligatoire (scan cheque signe ou confirmation virement).';
        }

        // Upload : facture (obligatoire pour CASI et CPSP)
        $facturePath = null;
        $factureRequise = $imp && in_array($imp['type_contrat'], ['CASI', 'CPSP'], true);
        if (isset($_FILES['facture']) && $_FILES['facture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $destDir = __DIR__ . '/../storage/factures/' . ($imp['numero'] ?? 'unknown');
            $up = handle_upload($_FILES['facture'], $destDir);
            if (!$up['success']) {
                $errors[] = 'Facture : ' . ($up['error'] ?? 'echec upload');
            } else {
                $facturePath = storage_relative_path($up['path']);
            }
        } elseif ($factureRequise) {
            $errors[] = 'Facture obligatoire pour les contrats CASI et CPSP.';
        }

        if (!$errors && $imp) {
            // Recupere la signature de l'admin (sera integree dans le PDF)
            $stmt = db()->prepare('SELECT signature_image FROM users WHERE id=?');
            $stmt->execute([(int)user_id()]);
            $sigAdmin = $stmt->fetchColumn() ?: null;

            // Transaction PDO
            db()->beginTransaction();
            try {
                $numero = generate_numero('F02', 'decaissements');
                $f02Id = DecaissementModel::create([
                    'numero' => $numero,
                    'imputation_id' => $impId,
                    'montant_brut'  => $imp['montant'],
                    'montant_allocation' => $imp['montant_allocation'],
                    'mode_paiement' => $modePaiement,
                    'numero_cheque' => $numCheque ?: null,
                    'numero_cheque_allocation' => $numChequeAlloc ?: null,
                    'justification_virement' => $justifVirement ?: null,
                    'preuve_paiement_scan' => $preuvePath,
                    'facture_scan' => $facturePath,
                    'valide_administrateur' => 1,
                    'sig_admin_scan' => $sigAdmin,
                    'date_validation' => date('Y-m-d H:i:s'),
                    'valide_par' => (int)user_id(),
                    'observations' => $observations ?: null,
                ]);

                audit_log('f02_valide', "Validation F02 $numero (mode=$modePaiement)", 'decaissements', $f02Id);

                // Alerte virement > 30 000 HTG
                if ($modePaiement === 'virement' && (float)$imp['montant'] > 30000) {
                    notifier_virement_30k($imp, (float)$imp['montant'], $justifVirement);
                }

                // Email Coordinateur + Prestataire
                notifier_f02_valide($imp, $numero, $f02Id);

                db()->commit();
                flash_set('success', "F02 $numero cree. DGI calcule automatiquement.");
                redirect('/portail/compta/f02.php?action=view&id=' . $f02Id);

            } catch (Throwable $e) {
                db()->rollBack();
                error_log('f02 create transaction failed: ' . $e->getMessage());
                $errors[] = 'Echec creation F02 : ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        error_log('f02 create error: ' . $e->getMessage());
        $errors[] = 'Erreur technique : ' . $e->getMessage();
    }
}

// =====================================================================
// NOTIFICATIONS
// =====================================================================
function notifier_virement_30k(array $imp, float $montant, string $justif): void
{
    $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role='coordinateur' AND actif=1");
    while ($coord = $stmt->fetch()) {
        $body = '<p>Bonjour ' . e($coord['nom_complet']) . ',</p>'
              . '<p><strong>Alerte virement > 30 000 HTG</strong></p>'
              . '<ul>'
              . '<li>F01 : ' . e($imp['numero']) . '</li>'
              . '<li>Beneficiaire : ' . e($imp['prestataire']) . '</li>'
              . '<li>Montant : ' . format_htg($montant) . '</li>'
              . '<li>Justification : ' . e($justif) . '</li>'
              . '</ul>';
        alerte_envoyer('virement_30k', $coord['email'],
            'Alerte virement > 30k HTG : ' . $imp['numero'], $body,
            ['type' => 'imputations', 'id' => (int)$imp['id']]);
    }
}

function notifier_f02_valide(array $imp, string $f02Numero, int $f02Id): void
{
    // Coordinateurs
    $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role='coordinateur' AND actif=1");
    while ($coord = $stmt->fetch()) {
        $body = '<p>Bonjour ' . e($coord['nom_complet']) . ',</p>'
              . '<p>Le F02 ' . e($f02Numero) . ' vient d etre valide :</p>'
              . '<ul>'
              . '<li>F01 : ' . e($imp['numero']) . '</li>'
              . '<li>Beneficiaire : ' . e($imp['prestataire']) . '</li>'
              . '<li>Montant brut : ' . format_htg((float)$imp['montant']) . '</li>'
              . '</ul>'
              . '<p>Prochaine etape : certifier l ASF.</p>';
        alerte_envoyer('f02_valide', $coord['email'],
            'F02 valide : ' . $f02Numero, $body,
            ['type' => 'decaissements', 'id' => $f02Id]);
    }
}

// =====================================================================
// ROUTAGE
// =====================================================================
$pageTitle  = 'F02 - Bons de Decaissement';
$activeMenu = 'compta';

if ($action === 'view' && $id > 0) {
    $f02 = DecaissementModel::find($id);
    if (!$f02) {
        flash_set('danger', 'F02 introuvable.');
        redirect('/portail/compta/f02.php');
    }
    require __DIR__ . '/../includes/header.php';
    render_view($f02);
    require __DIR__ . '/../includes/footer.php';

} elseif ($action === 'new' && $impId > 0) {
    check_role(['administrateur']);
    $imp = ImputationModel::find($impId);
    if (!$imp || $imp['statut'] !== 'soumis') {
        flash_set('danger', 'F01 introuvable ou pas au statut "soumis".');
        redirect('/portail/compta/f02.php');
    }
    if (DecaissementModel::findByImputation($impId) !== null) {
        flash_set('warning', 'Un F02 existe deja pour ce F01.');
        redirect('/portail/compta/f02.php?action=view&id=' . DecaissementModel::findByImputation($impId)['id']);
    }
    require __DIR__ . '/../includes/header.php';
    render_form($imp, $errors, $_POST);
    require __DIR__ . '/../includes/footer.php';

} else {
    // Liste : F01 en attente de F02 + F02 deja crees (selon role)
    require __DIR__ . '/../includes/header.php';
    render_list();
    require __DIR__ . '/../includes/footer.php';
}

// =====================================================================
// VIEWS
// =====================================================================
function render_list(): void
{
    $role = user_role();
    $sql = "SELECT i.id, i.numero AS f01_numero, i.date_depense, i.montant, i.is_retroactif,
                   c.numero AS contrat_numero, c.type_contrat, c.is_cps01,
                   p.nom_complet AS prestataire,
                   d.id AS f02_id, d.numero AS f02_numero, d.dgi_2pct, d.total_net_a_verser,
                   d.mode_paiement, d.date_validation
              FROM imputations i
              JOIN contrats c ON i.contrat_id = c.id
              JOIN prestataires p ON c.prestataire_id = p.id
              LEFT JOIN decaissements d ON d.imputation_id = i.id
             WHERE i.statut IN ('soumis','valide')
             ORDER BY i.id DESC LIMIT 100";
    $rows = db()->query($sql)->fetchAll();

    $attente = array_filter($rows, fn($r) => !$r['f02_id']);
    $valides = array_filter($rows, fn($r) => !!$r['f02_id']);
?>
<h1 class="h3 mb-3"><i class="bi bi-cash-coin"></i> F02 - Bons de Decaissement</h1>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-attente">
            En attente <span class="badge bg-warning text-dark"><?= count($attente) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-valides">
            Valides <span class="badge bg-success"><?= count($valides) ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-attente">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>F01</th><th>Date</th><th>Beneficiaire</th><th>Type</th>
                        <th class="text-end">Montant</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$attente): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucun F01 en attente.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($attente as $r): ?>
                        <tr>
                            <td><?= e($r['f01_numero']) ?>
                                <?php if ((int)$r['is_cps01']===1): ?><span class="badge bg-info">CPS-01</span><?php endif; ?>
                            </td>
                            <td><?= e(date('d/m/Y', strtotime($r['date_depense']))) ?></td>
                            <td><?= e($r['prestataire']) ?></td>
                            <td><span class="badge bg-light text-dark"><?= e($r['type_contrat']) ?></span></td>
                            <td class="text-end font-monospace"><?= format_htg($r['montant']) ?></td>
                            <td>
                                <?php if ($role === 'administrateur'): ?>
                                <a href="?action=new&imputation_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                                    Valider F02
                                </a>
                                <?php else: ?>
                                <a href="/portail/compta/f01.php?action=view&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">Voir F01</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-valides">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>F02</th><th>F01</th><th>Date validation</th><th>Beneficiaire</th>
                        <th>Mode</th><th class="text-end">DGI 2%</th><th class="text-end">Net verse</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$valides): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Aucun F02 valide.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($valides as $r): ?>
                        <tr>
                            <td><a href="?action=view&id=<?= (int)$r['f02_id'] ?>" class="fw-bold text-decoration-none"><?= e($r['f02_numero']) ?></a></td>
                            <td><small><?= e($r['f01_numero']) ?></small></td>
                            <td><?= $r['date_validation'] ? e(date('d/m/Y', strtotime($r['date_validation']))) : '-' ?></td>
                            <td><?= e($r['prestataire']) ?></td>
                            <td><span class="badge bg-<?= $r['mode_paiement']==='cheque'?'success':'warning' ?>"><?= e($r['mode_paiement']) ?></span></td>
                            <td class="text-end font-monospace"><?= format_htg($r['dgi_2pct']) ?></td>
                            <td class="text-end font-monospace"><?= format_htg($r['total_net_a_verser']) ?></td>
                            <td><a href="?action=view&id=<?= (int)$r['f02_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
}

function render_form(array $imp, array $errors, array $post): void
{
    $isCps01 = (int)$imp['is_cps01'] === 1;
    $factureRequise = in_array($imp['type_contrat'], ['CASI', 'CPSP'], true);
    // Pre-calcul DGI cote PHP (le trigger MySQL le recalculera de facon authoritaire)
    $montant = (float)$imp['montant'];
    $dgi = round($montant * 0.02, 2);
    $net = $montant - $dgi;
    $allocation = (float)($imp['montant_allocation'] ?? 0);
    $totalNet = $net + $allocation;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cash-coin"></i> Nouveau F02 - Validation</h1>
    <a href="/portail/compta/f01.php?action=view&id=<?= (int)$imp['id'] ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Retour F01
    </a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-3">
    <!-- Recap F01 -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white"><strong>F01 source</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Numero</dt><dd class="col-7"><?= e($imp['numero']) ?></dd>
                    <dt class="col-5">Beneficiaire</dt><dd class="col-7"><?= e($imp['prestataire']) ?></dd>
                    <dt class="col-5">Contrat</dt><dd class="col-7"><?= e($imp['contrat_numero']) ?> (<?= e($imp['type_contrat']) ?>)</dd>
                    <dt class="col-5">Description</dt><dd class="col-7"><small><?= nl2br(e($imp['description'])) ?></small></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Calcul DGI 2% (automatique)</strong></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Montant brut</th><td class="text-end font-monospace"><?= format_htg($montant) ?></td></tr>
                    <tr><th>DGI 2%</th><td class="text-end font-monospace text-danger">- <?= format_htg($dgi) ?></td></tr>
                    <tr class="table-light"><th>Net honoraires</th><td class="text-end font-monospace fw-bold"><?= format_htg($net) ?></td></tr>
                    <?php if ($isCps01 && $allocation > 0): ?>
                    <tr><th>+ Allocation (hors DGI)</th><td class="text-end font-monospace text-success">+ <?= format_htg($allocation) ?></td></tr>
                    <tr class="table-warning"><th><strong>Total net a verser</strong></th><td class="text-end font-monospace fw-bold"><?= format_htg($totalNet) ?></td></tr>
                    <?php endif; ?>
                </table>
                <small class="text-muted mt-2 d-block">
                    Le calcul est garanti par le trigger MySQL <code>trg_dgi_insert</code>.
                </small>
            </div>
        </div>
    </div>

    <!-- Formulaire -->
    <div class="col-lg-7">
        <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="imputation_id" value="<?= (int)$imp['id'] ?>">

                <h6 class="text-uppercase text-muted small mb-3">Mode de paiement</h6>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode_paiement" id="mp_cheque" value="cheque"
                               <?= (($post['mode_paiement'] ?? 'cheque')==='cheque')?'checked':'' ?> required>
                        <label class="form-check-label" for="mp_cheque">
                            <strong>Cheque SOGEBANK</strong> <span class="badge bg-success">par defaut</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode_paiement" id="mp_virement" value="virement"
                               <?= (($post['mode_paiement'] ?? '')==='virement')?'checked':'' ?>>
                        <label class="form-check-label" for="mp_virement">
                            Virement (exceptionnel) <small class="text-warning">justification obligatoire</small>
                        </label>
                    </div>
                </div>

                <div id="bloc-cheque">
                    <div class="row g-3 mb-3">
                        <div class="col-md-<?= $isCps01 ? '6' : '12' ?>">
                            <label class="form-label">N° de cheque <?= $isCps01 ? '(honoraires)' : '' ?> <span class="text-danger">*</span></label>
                            <input type="text" name="numero_cheque" class="form-control" maxlength="25"
                                   value="<?= e($post['numero_cheque'] ?? '') ?>">
                        </div>
                        <?php if ($isCps01): ?>
                        <div class="col-md-6">
                            <label class="form-label">N° de cheque (allocation) <span class="text-danger">*</span></label>
                            <input type="text" name="numero_cheque_allocation" class="form-control" maxlength="25"
                                   value="<?= e($post['numero_cheque_allocation'] ?? '') ?>">
                            <small class="text-muted">Doit etre different du premier.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="bloc-virement" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label">Justification du virement <span class="text-danger">*</span></label>
                        <textarea name="justification_virement" class="form-control" rows="3"><?= e($post['justification_virement'] ?? '') ?></textarea>
                        <small class="text-warning">Si montant > 30 000 HTG : alerte automatique au Coordinateur.</small>
                    </div>
                </div>

                <h6 class="text-uppercase text-muted small mt-4 mb-3">Pieces justificatives</h6>

                <div class="mb-3">
                    <label class="form-label">
                        Preuve de paiement <span class="text-danger">*</span>
                    </label>
                    <input type="file" name="preuve_paiement" class="form-control" accept="application/pdf,image/jpeg,image/png" required>
                    <small class="text-muted">Scan du talonnaire cheque signe ou confirmation de virement. PDF/JPG/PNG max 5 Mo.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Facture <?= $factureRequise ? '<span class="text-danger">*</span>' : '(optionnel)' ?>
                    </label>
                    <input type="file" name="facture" class="form-control" accept="application/pdf,image/jpeg,image/png" <?= $factureRequise ? 'required' : '' ?>>
                    <small class="text-muted">
                        <?= $factureRequise
                            ? 'Obligatoire pour les contrats CASI et CPSP.'
                            : 'Non applicable aux contrats CPS.' ?>
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Observations (optionnel)</label>
                    <textarea name="observations" class="form-control" rows="2"><?= e($post['observations'] ?? '') ?></textarea>
                </div>

                <hr>
                <button class="btn btn-primary w-100" onclick="return confirm('Valider definitivement ce F02 ?');">
                    <i class="bi bi-check2-circle"></i> Valider et generer le F02
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const radios = document.querySelectorAll('input[name="mode_paiement"]');
    const bChq = document.getElementById('bloc-cheque');
    const bVir = document.getElementById('bloc-virement');

    function refresh() {
        const v = document.querySelector('input[name="mode_paiement"]:checked').value;
        bChq.style.display = (v==='cheque') ? '' : 'none';
        bVir.style.display = (v==='virement') ? '' : 'none';
        bChq.querySelectorAll('input').forEach(i => i.required = (v==='cheque'));
        bVir.querySelectorAll('textarea').forEach(t => t.required = (v==='virement'));
    }
    radios.forEach(r => r.addEventListener('change', refresh));
    refresh();
})();
</script>
<?php
}

function render_view(array $f02): void
{
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">F02 <?= e($f02['numero']) ?>
            <span class="badge bg-success">Valide</span>
            <?php if ((int)$f02['is_cps01']===1): ?><span class="badge bg-info">CPS-01</span><?php endif; ?>
        </h1>
        <small class="text-muted">F01 source : <a href="/portail/compta/f01.php?action=view&id=<?= (int)$f02['imputation_id'] ?>"><?= e($f02['imputation_numero']) ?></a></small>
    </div>
    <div>
        <a href="/portail/pdf/render.php?type=f02&id=<?= (int)$f02['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
            <i class="bi bi-file-earmark-pdf"></i> PDF
        </a>
        <a href="/portail/compta/f02.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Decaissement</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Beneficiaire</dt><dd class="col-7"><?= e($f02['prestataire']) ?></dd>
                    <dt class="col-5">Date validation</dt><dd class="col-7"><?= $f02['date_validation'] ? e(date('d/m/Y H:i', strtotime($f02['date_validation']))) : '-' ?></dd>
                    <dt class="col-5">Valide par</dt><dd class="col-7"><?= e($f02['valide_par_nom'] ?? '-') ?></dd>
                    <dt class="col-5">Mode</dt><dd class="col-7"><span class="badge bg-<?= $f02['mode_paiement']==='cheque'?'success':'warning' ?>"><?= e($f02['mode_paiement']) ?></span></dd>
                    <?php if ($f02['mode_paiement']==='cheque'): ?>
                        <dt class="col-5">N° cheque<?= (int)$f02['is_cps01']===1 ? ' (honoraires)' : '' ?></dt>
                        <dd class="col-7 font-monospace"><?= e($f02['numero_cheque']) ?></dd>
                        <?php if ($f02['numero_cheque_allocation']): ?>
                            <dt class="col-5">N° cheque (allocation)</dt>
                            <dd class="col-7 font-monospace"><?= e($f02['numero_cheque_allocation']) ?></dd>
                        <?php endif; ?>
                    <?php else: ?>
                        <dt class="col-5">Justification</dt>
                        <dd class="col-7"><?= nl2br(e((string)$f02['justification_virement'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($f02['observations']): ?>
                        <dt class="col-5">Observations</dt><dd class="col-7"><?= nl2br(e((string)$f02['observations'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Calcul DGI (par trigger)</strong></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Montant brut</th><td class="text-end font-monospace"><?= format_htg($f02['montant_brut']) ?></td></tr>
                    <tr><th>DGI 2%</th><td class="text-end font-monospace text-danger">- <?= format_htg($f02['dgi_2pct']) ?></td></tr>
                    <tr class="table-light"><th>Net honoraires</th><td class="text-end font-monospace fw-bold"><?= format_htg($f02['net_honoraires']) ?></td></tr>
                    <?php if ($f02['montant_allocation'] && (float)$f02['montant_allocation'] > 0): ?>
                    <tr><th>+ Allocation (hors DGI)</th><td class="text-end font-monospace text-success">+ <?= format_htg($f02['montant_allocation']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="table-warning"><th><strong>Total net a verser</strong></th><td class="text-end font-monospace fw-bold"><?= format_htg($f02['total_net_a_verser']) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-white"><strong>Pieces jointes</strong></div>
            <div class="card-body">
                <?php if ($f02['preuve_paiement_scan']): ?>
                    <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$f02['preuve_paiement_scan'])) ?>&type=pdf"
                       class="btn btn-outline-primary w-100 mb-2" target="_blank">
                        <i class="bi bi-paperclip"></i> Preuve de paiement
                    </a>
                <?php endif; ?>
                <?php if ($f02['facture_scan']): ?>
                    <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$f02['facture_scan'])) ?>&type=pdf"
                       class="btn btn-outline-primary w-100" target="_blank">
                        <i class="bi bi-receipt"></i> Facture
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
}
