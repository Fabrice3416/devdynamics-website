<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/DecaissementModel.php';
require_once __DIR__ . '/../models/AsfModel.php';
require_once __DIR__ . '/../models/NoteHonoraireModel.php';
require_once __DIR__ . '/../models/FicheReglementModel.php';

check_role(['administrateur']); // Restrictif Phase 6

$errors = [];
$success = null;

const DATE_PROJET_DEBUT = '2026-04-24';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        verify_csrf();

        $date = (string)($_POST['date_depense'] ?? '');
        $contratId = (int)($_POST['contrat_id'] ?? 0);
        $ligneId   = (int)($_POST['ligne_budgetaire_id'] ?? 0);
        $rubrique  = (string)($_POST['rubrique'] ?? '');
        $nature    = (string)($_POST['nature_paiement'] ?? '');
        $description = trim((string)($_POST['description'] ?? ''));
        $montant   = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['montant'] ?? '0'));

        // F02
        $mode = (string)($_POST['mode_paiement'] ?? 'cheque');
        $numCheque = trim((string)($_POST['numero_cheque'] ?? ''));

        // ASF
        $livrables = trim((string)($_POST['livrables_realises'] ?? ''));
        $statutLivrables = (string)($_POST['statut_livrables'] ?? 'conformes');

        // NH
        $descNh = trim((string)($_POST['description_nh'] ?? $description));

        // FRP
        $dateFrp = (string)($_POST['date_paiement'] ?? $date);
        $eval    = (string)($_POST['evaluation_livrables'] ?? 'conformes');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Date invalide.';
        if ($date && strtotime($date) < strtotime(DATE_PROJET_DEBUT)) {
            $errors[] = 'Date doit etre >= ' . DATE_PROJET_DEBUT . ' (debut projet).';
        }
        if ($contratId <= 0) $errors[] = 'Contrat obligatoire.';
        if ($ligneId <= 0)   $errors[] = 'Ligne budgetaire obligatoire.';
        if ($montant <= 0)   $errors[] = 'Montant doit etre positif.';

        // Upload scans (preuve paiement obligatoire)
        $destDir = __DIR__ . '/../storage/retroactif/' . date('Y',strtotime($date)) . '/M' . str_pad((string)date('m',strtotime($date)),2,'0',STR_PAD_LEFT);
        $preuvePath = null;
        if (isset($_FILES['preuve_paiement']) && $_FILES['preuve_paiement']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = handle_upload($_FILES['preuve_paiement'], $destDir);
            if (!$up['success']) $errors[] = 'Preuve paiement: ' . ($up['error'] ?? 'echec');
            else $preuvePath = storage_relative_path($up['path']);
        } else {
            $errors[] = 'Preuve de paiement obligatoire.';
        }

        $facturePath = null;
        if (isset($_FILES['facture']) && $_FILES['facture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = handle_upload($_FILES['facture'], $destDir);
            if ($up['success']) $facturePath = storage_relative_path($up['path']);
        }

        if (!$errors) {
            db()->beginTransaction();
            try {
                // 1. F01 (is_retroactif = 1)
                $numF01 = generate_numero('F01', 'imputations');
                $impId = ImputationModel::create([
                    'numero' => $numF01,
                    'date_depense' => $date,
                    'contrat_id' => $contratId,
                    'ligne_budgetaire_id' => $ligneId,
                    'rubrique' => $rubrique,
                    'nature_paiement' => $nature,
                    'description' => $description,
                    'montant' => $montant,
                    'statut' => 'soumis',
                    'is_retroactif' => 1,
                    'soumis_par' => (int)user_id(),
                ]);

                // 2. F02
                $numF02 = generate_numero('F02', 'decaissements');
                DecaissementModel::create([
                    'numero' => $numF02,
                    'imputation_id' => $impId,
                    'montant_brut' => $montant,
                    'mode_paiement' => $mode,
                    'numero_cheque' => $mode === 'cheque' ? $numCheque : null,
                    'justification_virement' => $mode === 'virement' ? 'Saisie retroactive' : null,
                    'preuve_paiement_scan' => $preuvePath,
                    'facture_scan' => $facturePath,
                    'valide_administrateur' => 1,
                    'date_validation' => date('Y-m-d H:i:s'),
                    'valide_par' => (int)user_id(),
                ]);

                // 3. ASF
                $numAsf = generate_numero('ASF', 'attestations_service_fait');
                $asfId = AsfModel::create([
                    'numero' => $numAsf,
                    'imputation_id' => $impId,
                    'livrables_realises' => $livrables ?: $description,
                    'statut_livrables' => $statutLivrables,
                    'certifie_coordinateur' => 1,
                    'date_certification' => date('Y-m-d H:i:s'),
                    'certifie_par' => (int)user_id(), // Admin saisit pour le Coord
                ]);

                // 4. NH (non applicable si renflouement)
                if ($nature !== 'renflouement_petite_caisse') {
                    $numNh = generate_numero('NH', 'notes_honoraires');
                    NoteHonoraireModel::create([
                        'numero' => $numNh,
                        'imputation_id' => $impId,
                        'description_prestation' => $descNh,
                        'montant_brut' => $montant,
                        'mode_paiement' => $mode,
                        'certifie_prestataire' => 1,
                        'date_soumission' => date('Y-m-d H:i:s'),
                    ]);
                }

                // 5. FRP - cree avec 3 signatures pour cloturer immediatement
                $numFrp = generate_numero_mensuel('FRP',
                    (int)db()->query("SELECT COUNT(*)+1 FROM fiches_reglement WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn(),
                    (int)date('m', strtotime($date))
                );
                $frpId = FicheReglementModel::create([
                    'numero' => $numFrp,
                    'imputation_id' => $impId,
                    'date_paiement' => $dateFrp,
                    'numero_cheque' => $mode === 'cheque' ? $numCheque : null,
                    'evaluation_livrables' => $eval,
                ]);

                // Triple signature pour cloturer (renflouement = 2 sigs)
                if ($nature === 'renflouement_petite_caisse') {
                    FicheReglementModel::updateSignatures($frpId, [
                        'sig_administrateur' => 1, 'sig_coordinateur' => 1,
                    ]);
                    // Renflouement : on force le sig_prestataire a 1 aussi pour declencher le trigger
                    FicheReglementModel::updateSignatures($frpId, ['sig_prestataire' => 1]);
                } else {
                    FicheReglementModel::updateSignatures($frpId, [
                        'sig_prestataire' => 1, 'sig_administrateur' => 1, 'sig_coordinateur' => 1,
                    ]);
                }

                audit_log('saisie_retroactive', "Dossier retroactif complet $numF01 - $numFrp", 'imputations', $impId);

                // Notification Coordinateur
                $stmt = db()->query("SELECT email, nom_complet FROM users WHERE role='coordinateur' AND actif=1");
                while ($coord = $stmt->fetch()) {
                    $body = '<p>Saisie retroactive enregistree :</p>'
                          . '<ul><li>F01 : ' . e($numF01) . '</li>'
                          . '<li>Date depense : ' . e($date) . '</li>'
                          . '<li>Montant : ' . format_htg($montant) . '</li></ul>'
                          . '<p>Le dossier est marque "is_retroactif=1" et porte un tampon visible.</p>';
                    alerte_envoyer('saisie_retroactive', $coord['email'],
                        'Saisie retroactive validee : ' . $numF01, $body,
                        ['type' => 'imputations', 'id' => $impId]);
                }

                db()->commit();
                $success = "Dossier retroactif complet enregistre. F01 $numF01, FRP $numFrp cloturee.";
            } catch (Throwable $e) {
                db()->rollBack();
                $errors[] = 'Erreur transaction : ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Erreur: ' . $e->getMessage();
    }
}

$contrats = ImputationModel::contratsActifs();
$lignes = ImputationModel::lignesBudgetairesActives();

$pageTitle = 'Saisie retroactive';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-clock-history"></i> Saisie retroactive (Administrateur)</h1>

<div class="alert alert-warning">
    <strong>Saisie en une seule passe.</strong> Les 5 pieces (F01, F02, ASF, NH, FRP) sont creees simultanement.
    Le dossier porte un tampon "SAISIE RETROACTIVE" et le flag <code>is_retroactif=1</code>. Date minimum : <?= DATE_PROJET_DEBUT ?>.
</div>

<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <h6 class="text-uppercase small text-muted mb-3">Identification de la depense</h6>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">Date depense <span class="text-danger">*</span></label>
                <input type="date" name="date_depense" class="form-control" required min="<?= DATE_PROJET_DEBUT ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label">Contrat <span class="text-danger">*</span></label>
                <select name="contrat_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($contrats as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['numero']) ?> - <?= e($c['prestataire']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Ligne budgetaire <span class="text-danger">*</span></label>
                <select name="ligne_budgetaire_id" class="form-select" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($lignes as $lb): ?>
                        <option value="<?= (int)$lb['id'] ?>"><?= e($lb['code']) ?> - <?= e($lb['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Rubrique <span class="text-danger">*</span></label>
                <select name="rubrique" class="form-select" required>
                    <?php foreach (['personnel','achats_services','terrain','communication','autre'] as $r): ?>
                        <option value="<?= $r ?>"><?= e(ucfirst(str_replace('_',' ',$r))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Nature <span class="text-danger">*</span></label>
                <select name="nature_paiement" class="form-select" required>
                    <?php foreach (['honoraires','allocation_deplacement','achat_service','renflouement_petite_caisse','autre'] as $n): ?>
                        <option value="<?= $n ?>"><?= e(ucfirst(str_replace('_',' ',$n))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Montant <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="number" name="montant" class="form-control text-end" step="0.01" min="0.01" required>
                    <span class="input-group-text">HTG</span>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="2" required></textarea>
            </div>
        </div>

        <h6 class="text-uppercase small text-muted mb-3 mt-4">F02 - Decaissement (deja effectue)</h6>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">Mode</label>
                <select name="mode_paiement" class="form-select">
                    <option value="cheque">Cheque</option>
                    <option value="virement">Virement</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">N° cheque</label>
                <input type="text" name="numero_cheque" class="form-control" maxlength="25">
            </div>
            <div class="col-md-6">
                <label class="form-label">Scan cheque emis <span class="text-danger">*</span></label>
                <input type="file" name="preuve_paiement" class="form-control" accept="application/pdf,image/jpeg,image/png" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Scan facture (si CASI/CPSP)</label>
                <input type="file" name="facture" class="form-control" accept="application/pdf,image/jpeg,image/png">
            </div>
        </div>

        <h6 class="text-uppercase small text-muted mb-3 mt-4">ASF - Service effectivement realise</h6>
        <div class="row g-3 mb-3">
            <div class="col-12">
                <label class="form-label">Livrables realises</label>
                <textarea name="livrables_realises" class="form-control" rows="2" placeholder="Si vide, reprend la description"></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Statut livrables</label>
                <select name="statut_livrables" class="form-select">
                    <option value="conformes">Conformes</option>
                    <option value="partiels">Partiels</option>
                    <option value="non_conformes">Non conformes</option>
                </select>
            </div>
        </div>

        <h6 class="text-uppercase small text-muted mb-3 mt-4">NH - Note d'honoraires (sera marquee signee)</h6>
        <div class="row g-3 mb-3">
            <div class="col-12">
                <label class="form-label">Description prestation (NH)</label>
                <textarea name="description_nh" class="form-control" rows="2" placeholder="Si vide, reprend la description"></textarea>
            </div>
        </div>

        <h6 class="text-uppercase small text-muted mb-3 mt-4">FRP - Reglement final</h6>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label">Date paiement effectif</label>
                <input type="date" name="date_paiement" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Evaluation livrables</label>
                <select name="evaluation_livrables" class="form-select">
                    <option value="conformes">Conformes</option>
                    <option value="partiels">Partiels</option>
                    <option value="non_conformes">Non conformes</option>
                </select>
            </div>
        </div>

        <hr>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i> En validant, vous certifiez que les pieces justificatives existent physiquement
            et que les signatures ont ete recueillies hors-systeme. Toute l'operation est tracee dans audit_log.
            Le Coordinateur recevra une alerte de validation.
        </div>

        <button class="btn btn-primary w-100" onclick="return confirm('Valider definitivement ce dossier retroactif ?');">
            <i class="bi bi-clock-history"></i> Enregistrer le dossier retroactif complet
        </button>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php';
