<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/DecaissementModel.php';
require_once __DIR__ . '/../models/AsfModel.php';
require_once __DIR__ . '/../models/NoteHonoraireModel.php';
require_once __DIR__ . '/../models/FicheReglementModel.php';

check_role(['administrateur']);

const DATE_PROJET_DEBUT = '2026-04-24';
const MAX_LINES = 50;

$errors = [];
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        verify_csrf();
        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Fichier CSV manquant.';
        } else {
            $rows = [];
            if (($h = fopen($_FILES['csv']['tmp_name'], 'r')) !== false) {
                $header = fgetcsv($h, 0, ';');
                while (($line = fgetcsv($h, 0, ';')) !== false) {
                    if (count($line) === count($header)) {
                        $rows[] = array_combine($header, $line);
                    }
                }
                fclose($h);
            }
            if (count($rows) > MAX_LINES) {
                $errors[] = 'Maximum ' . MAX_LINES . ' lignes par import. Decoupez le fichier.';
            }

            if (!$errors) {
                $ok = 0; $ko = 0; $errs = [];
                foreach ($rows as $idx => $r) {
                    try {
                        // Validation date
                        $date = (string)($r['date_depense'] ?? '');
                        // formats : "DD/MM/YYYY" ou "YYYY-MM-DD"
                        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m)) {
                            $date = $m[3] . '-' . $m[2] . '-' . $m[1];
                        }
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) < strtotime(DATE_PROJET_DEBUT)) {
                            throw new RuntimeException("Date invalide (ligne " . ($idx+1) . ")");
                        }

                        // Lookups
                        $stmt = db()->prepare('SELECT id, is_cps01 FROM contrats WHERE numero = ?');
                        $stmt->execute([trim((string)$r['contrat_numero'])]);
                        $contrat = $stmt->fetch();
                        if (!$contrat) throw new RuntimeException("Contrat inconnu (ligne " . ($idx+1) . ")");

                        $stmt = db()->prepare('SELECT id FROM lignes_budgetaires WHERE code = ?');
                        $stmt->execute([trim((string)$r['ligne_budgetaire_code'])]);
                        $ligneId = (int)$stmt->fetchColumn();
                        if (!$ligneId) throw new RuntimeException("Ligne inconnue (ligne " . ($idx+1) . ")");

                        $montant = (float)str_replace([',', ' '], ['.', ''], (string)($r['montant'] ?? '0'));
                        if ($montant <= 0) throw new RuntimeException("Montant invalide (ligne " . ($idx+1) . ")");

                        db()->beginTransaction();
                        $numF01 = generate_numero('F01', 'imputations');
                        $impId = ImputationModel::create([
                            'numero' => $numF01,
                            'date_depense' => $date,
                            'contrat_id' => (int)$contrat['id'],
                            'ligne_budgetaire_id' => $ligneId,
                            'rubrique' => $r['rubrique'] ?? 'autre',
                            'nature_paiement' => $r['nature_paiement'] ?? 'autre',
                            'description' => $r['description'] ?? '(import CSV)',
                            'montant' => $montant,
                            'statut' => 'soumis',
                            'is_retroactif' => 1,
                            'soumis_par' => (int)user_id(),
                        ]);

                        DecaissementModel::create([
                            'numero' => generate_numero('F02', 'decaissements'),
                            'imputation_id' => $impId,
                            'montant_brut' => $montant,
                            'mode_paiement' => 'cheque',
                            'numero_cheque' => $r['numero_cheque'] ?? null,
                            'valide_administrateur' => 1,
                            'date_validation' => date('Y-m-d H:i:s'),
                            'valide_par' => (int)user_id(),
                        ]);

                        AsfModel::create([
                            'numero' => generate_numero('ASF', 'attestations_service_fait'),
                            'imputation_id' => $impId,
                            'livrables_realises' => $r['livrables_realises'] ?? $r['description'] ?? '(import CSV)',
                            'statut_livrables' => $r['statut_livrables'] ?? 'conformes',
                            'certifie_coordinateur' => 1,
                            'date_certification' => date('Y-m-d H:i:s'),
                            'certifie_par' => (int)user_id(),
                        ]);

                        NoteHonoraireModel::create([
                            'numero' => generate_numero('NH', 'notes_honoraires'),
                            'imputation_id' => $impId,
                            'description_prestation' => $r['description'] ?? '(import CSV)',
                            'montant_brut' => $montant,
                            'mode_paiement' => 'cheque',
                            'certifie_prestataire' => 1,
                            'date_soumission' => date('Y-m-d H:i:s'),
                        ]);

                        $numFrp = generate_numero_mensuel('FRP',
                            (int)db()->query("SELECT COUNT(*)+1 FROM fiches_reglement WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn(),
                            (int)date('m', strtotime($date))
                        );
                        $frpId = FicheReglementModel::create([
                            'numero' => $numFrp,
                            'imputation_id' => $impId,
                            'date_paiement' => $date,
                            'numero_cheque' => $r['numero_cheque'] ?? null,
                            'evaluation_livrables' => $r['statut_livrables'] ?? 'conformes',
                        ]);
                        FicheReglementModel::updateSignatures($frpId, [
                            'sig_prestataire' => 1, 'sig_administrateur' => 1, 'sig_coordinateur' => 1,
                        ]);

                        db()->commit();
                        $ok++;
                    } catch (Throwable $e) {
                        if (db()->inTransaction()) db()->rollBack();
                        $ko++;
                        $errs[] = $e->getMessage();
                    }
                }
                audit_log('import_retroactif', "Import CSV retroactif : $ok OK, $ko erreurs", null, null);
                $summary = ['ok' => $ok, 'ko' => $ko, 'errs' => array_slice($errs, 0, 10)];
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Erreur: ' . $e->getMessage();
    }
}

$pageTitle = 'Import CSV retroactif';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-file-spreadsheet"></i> Import CSV retroactif</h1>

<div class="alert alert-warning">
    <strong>Max <?= MAX_LINES ?> lignes par import.</strong> Au-dela : decoupez en plusieurs fichiers.<br>
    Format : UTF-8, separateur <code>;</code>. Colonnes obligatoires :
    <code>date_depense, contrat_numero, ligne_budgetaire_code, rubrique, nature_paiement, montant, description, numero_cheque, livrables_realises, statut_livrables</code>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($summary): ?>
    <div class="alert alert-<?= $summary['ko'] > 0 ? 'warning' : 'success' ?>">
        <strong>Import termine.</strong> <?= $summary['ok'] ?> dossier(s) crees, <?= $summary['ko'] ?> erreur(s).
        <?php if ($summary['errs']): ?>
            <ul class="mb-0 small mt-2">
                <?php foreach ($summary['errs'] as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">

        <label class="form-label">Fichier CSV (UTF-8, ; separateur)</label>
        <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>

        <hr>
        <button class="btn btn-primary"><i class="bi bi-upload"></i> Importer</button>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php';
