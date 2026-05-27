<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../models/CaisseModel.php';
require_once __DIR__ . '/../models/ImputationModel.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$cfg = config();
$plafondOp = (float)$cfg['app']['caisse_plafond_op'];
$seuil     = (float)$cfg['app']['caisse_seuil'];
$fonds     = (float)$cfg['app']['caisse_fonds'];
$errors = [];

$renflouement = CaisseModel::renflouementEnCours();
$gelActif = $renflouement && $renflouement['statut'] !== 'verse';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur', 'comptable']);
    try {
        verify_csrf();
        if ($gelActif) {
            $errors[] = 'Renflouement en cours : nouvelle depense bloquee (AJUST-02).';
        }

        $date = (string)($_POST['date_depense'] ?? '');
        $desc = trim((string)($_POST['description'] ?? ''));
        $rubrique = (string)($_POST['rubrique'] ?? '');
        $ligneId = (int)($_POST['ligne_budgetaire_id'] ?? 0);
        $montant = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['montant'] ?? '0'));
        $numRecu = trim((string)($_POST['numero_recu'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Date invalide.';
        if ($desc === '') $errors[] = 'Description obligatoire.';
        if (!in_whitelist($rubrique, ['personnel','achats_services','terrain','communication','autre'])) $errors[] = 'Rubrique invalide.';
        if ($ligneId <= 0) $errors[] = 'Ligne budgetaire obligatoire.';
        if ($montant <= 0) $errors[] = 'Montant doit etre positif.';
        if ($montant > $plafondOp) {
            $errors[] = 'Plafond depasse (' . format_htg($plafondOp) . '/operation). Utilisez le circuit F01/F02 avec cheque.';
        }
        if ($numRecu === '') $errors[] = 'Numero de recu obligatoire.';

        // Solde insuffisant ?
        $soldeActuel = CaisseModel::solde();
        if ($soldeActuel - $montant < 0) {
            $errors[] = 'Solde insuffisant (solde actuel : ' . format_htg($soldeActuel) . ').';
        }

        // Upload recu obligatoire
        $recuPath = null; $recuTaille = null; $recuType = null;
        if (isset($_FILES['recu']) && $_FILES['recu']['error'] !== UPLOAD_ERR_NO_FILE) {
            $destDir = __DIR__ . '/../storage/caisse/recus/' . date('Y') . '/M' . str_pad((string)date('m'),2,'0',STR_PAD_LEFT);
            $up = handle_upload($_FILES['recu'], $destDir);
            if (!$up['success']) {
                $errors[] = 'Recu : ' . ($up['error'] ?? 'echec upload');
            } else {
                $recuPath = storage_relative_path($up['path']);
                $recuTaille = $up['size'];
                $recuType = $up['type'];
            }
        } else {
            $errors[] = 'Scan ou photo du recu obligatoire.';
        }

        if (!$errors) {
            $numero = generate_numero('F-PC', 'caisse_transactions');
            $tid = CaisseModel::create([
                'numero' => $numero,
                'date_depense' => $date,
                'description' => $desc,
                'rubrique' => $rubrique,
                'ligne_budgetaire_id' => $ligneId,
                'montant' => $montant,
                'numero_recu' => $numRecu,
                'recu_scan' => $recuPath,
                'recu_scan_taille' => $recuTaille,
                'recu_scan_type' => $recuType,
                'created_by' => (int)user_id(),
            ]);
            audit_log('upload_fichier', "Depense Petite Caisse $numero", 'caisse_transactions', $tid);

            // Alerte seuil
            $nouveauSolde = CaisseModel::solde();
            if ($nouveauSolde < $seuil) {
                notifier_caisse_seuil($nouveauSolde, $tid);
            }

            flash_set('success', "Depense PC $numero enregistree (en attente validation Administrateur).");
            redirect('/portail/compta/petite_caisse.php');
        }
    } catch (Throwable $e) {
        error_log('PC create: ' . $e->getMessage());
        $errors[] = 'Erreur technique.';
    }
}

// POST : validation administrateur d'une transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $tid = (int)($_POST['id'] ?? 0);
        CaisseModel::validate($tid, (int)user_id());
        audit_log('upload_fichier', "Validation Admin PC #$tid", 'caisse_transactions', $tid);
        flash_set('success', 'Transaction validee.');
    } catch (Throwable $e) {
        flash_set('danger', 'Erreur.');
    }
    redirect('/portail/compta/petite_caisse.php');
}

function notifier_caisse_seuil(float $solde, int $tid): void
{
    $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role IN ('administrateur','comptable') AND actif=1");
    while ($u = $stmt->fetch()) {
        $body = '<p>Bonjour ' . e($u['nom_complet']) . ',</p>'
              . '<p>Le solde de la Petite Caisse est descendu sous le seuil de renflouement.</p>'
              . '<ul><li>Solde actuel : <strong>' . format_htg($solde) . '</strong></li>'
              . '<li>Seuil : ' . format_htg(9000) . ' (30% du fonds)</li></ul>'
              . '<p>Un renflouement doit etre demande.</p>'
              . '<p><a href="https://dev-dynamics.org/portail/compta/petite_caisse_renflouement.php">Acceder a l interface</a></p>';
        alerte_envoyer('caisse_seuil', $u['email'],
            'Petite Caisse < 9 000 HTG', $body, ['type' => 'caisse_transactions', 'id' => $tid]);
    }
}

$solde = CaisseModel::solde();
$txs = CaisseModel::transactionsNonRenflouees();
$lignes = ImputationModel::lignesBudgetairesActives();

$pageTitle = 'Petite Caisse';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';

$soldeClass = $solde < $seuil ? 'text-danger' : ($solde < $fonds * 0.5 ? 'text-warning' : 'text-success');
?>

<h1 class="h3 mb-3"><i class="bi bi-cash"></i> Petite Caisse - Fonds Imprest <?= format_htg($fonds) ?></h1>

<?php if ($gelActif): ?>
    <div class="alert alert-warning">
        <i class="bi bi-snow"></i> <strong>Gel des nouvelles depenses</strong> -
        Un renflouement est en cours (<?= e($renflouement['numero']) ?>, statut : <?= e($renflouement['statut']) ?>). AJUST-02
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h6 class="text-uppercase small text-muted">Solde actuel</h6>
                <h2 class="<?= $soldeClass ?>"><?= format_htg($solde) ?></h2>
                <small class="text-muted">/ <?= format_htg($fonds) ?></small>
                <?php if ($solde < $seuil): ?>
                    <div class="alert alert-warning small mt-2 mb-0">
                        Solde sous le seuil (<?= format_htg($seuil) ?>). Renflouement requis.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h6 class="text-uppercase small text-muted">Transactions a renflouer</h6>
                <h2><?= count($txs) ?></h2>
                <small class="text-muted">total : <?= format_htg(array_sum(array_column($txs, 'montant'))) ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h6 class="text-uppercase small text-muted">Plafond / operation</h6>
                <h2><?= format_htg($plafondOp) ?></h2>
                <small class="text-muted">Au-dela : circuit F01/F02</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Formulaire saisie -->
    <?php if (in_array(user_role(), ['administrateur', 'comptable'], true) && !$gelActif): ?>
    <div class="col-lg-5">
        <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Nouvelle depense PC</strong></div>
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="date_depense" class="form-control" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Rubrique <span class="text-danger">*</span></label>
                        <select name="rubrique" class="form-select" required>
                            <?php foreach (['personnel','achats_services','terrain','communication','autre'] as $r): ?>
                                <option value="<?= $r ?>"><?= e(ucfirst(str_replace('_',' ',$r))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Ligne budgetaire <span class="text-danger">*</span></label>
                        <select name="ligne_budgetaire_id" class="form-select" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($lignes as $lb): ?>
                                <option value="<?= (int)$lb['id'] ?>"><?= e($lb['code']) ?> - <?= e($lb['libelle']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Montant <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="montant" class="form-control text-end" step="0.01" min="0.01" max="<?= $plafondOp ?>" required>
                            <span class="input-group-text">HTG</span>
                        </div>
                        <small class="text-muted">Max <?= format_htg($plafondOp) ?></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">N° recu <span class="text-danger">*</span></label>
                        <input type="text" name="numero_recu" class="form-control" required maxlength="50">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Scan du recu <span class="text-danger">*</span></label>
                        <input type="file" name="recu" class="form-control" accept="application/pdf,image/jpeg,image/png" required>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white">
                <button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> Enregistrer</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="col-lg-<?= (in_array(user_role(), ['administrateur', 'comptable'], true) && !$gelActif) ? '7' : '12' ?>">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Transactions a renflouer</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr>
                        <th>N°</th><th>Date</th><th>Description</th><th>Ligne</th>
                        <th class="text-end">Montant</th><th>Recu</th><th>Statut</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$txs): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">Aucune transaction.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($txs as $t): ?>
                        <tr>
                            <td><small><?= e($t['numero']) ?></small></td>
                            <td><small><?= e(date('d/m/Y', strtotime($t['date_depense']))) ?></small></td>
                            <td><small><?= e($t['description']) ?></small></td>
                            <td><small><?= e($t['ligne_code']) ?></small></td>
                            <td class="text-end font-monospace"><?= format_htg($t['montant']) ?></td>
                            <td>
                                <?php if ($t['recu_scan']): ?>
                                    <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$t['recu_scan'])) ?>&type=pdf" target="_blank"><i class="bi bi-file-pdf"></i></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$t['valide_administrateur'] === 1): ?>
                                    <small class="badge bg-success">Validee</small>
                                <?php elseif (user_role() === 'administrateur'): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="validate">
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button class="btn btn-sm btn-outline-success">Valider</button>
                                    </form>
                                <?php else: ?>
                                    <small class="badge bg-warning">Attente</small>
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
</div>

<?php require __DIR__ . '/../includes/footer.php';
