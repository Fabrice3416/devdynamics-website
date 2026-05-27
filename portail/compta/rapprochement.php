<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
check_role(['administrateur', 'coordinateur']);

$action = (string)($_GET['action'] ?? 'list');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $mois  = (int)($_POST['mois'] ?? 0);
        $annee = (int)($_POST['annee'] ?? 0);
        $solde1 = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['solde1'] ?? '0'));
        $solde2 = (float)str_replace([',', ' '], ['.', ''], (string)($_POST['solde2'] ?? '0'));
        $observations = trim((string)($_POST['observations'] ?? ''));

        if ($mois < 1 || $mois > 12) $errors[] = 'Mois invalide.';
        if ($annee < 2020 || $annee > 2030) $errors[] = 'Annee invalide.';
        if (abs($solde1 - $solde2) > 0.01) $errors[] = 'Les deux soldes saisis ne correspondent pas (double saisie).';

        // Verifier unicite mois/annee
        $stmt = db()->prepare('SELECT id FROM rapprochements_bancaires WHERE mois=? AND annee=?');
        $stmt->execute([$mois, $annee]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'Un rapprochement existe deja pour cette periode.';
        }

        // Upload obligatoire releve SOGEBANK
        $relevePath = null;
        if (isset($_FILES['releve']) && $_FILES['releve']['error'] !== UPLOAD_ERR_NO_FILE) {
            $destDir = __DIR__ . '/../storage/rapprochements/' . $annee . '/M' . str_pad((string)$mois,2,'0',STR_PAD_LEFT);
            $up = handle_upload($_FILES['releve'], $destDir);
            if (!$up['success']) {
                $errors[] = 'Releve SOGEBANK : ' . ($up['error'] ?? 'echec');
            } else {
                $relevePath = storage_relative_path($up['path']);
            }
        } else {
            $errors[] = 'Scan du releve SOGEBANK obligatoire.';
        }

        if (!$errors) {
            // Total paiements de la periode
            $stmt = db()->prepare(
                "SELECT COALESCE(SUM(d.total_net_a_verser), 0)
                   FROM decaissements d
                   JOIN imputations i ON d.imputation_id = i.id
                  WHERE MONTH(i.date_depense) = ? AND YEAR(i.date_depense) = ?"
            );
            $stmt->execute([$mois, $annee]);
            $totalApp = (float)$stmt->fetchColumn();
            $ecart = $solde1 - $totalApp;

            if (abs($ecart) > 0.01 && $observations === '') {
                $errors[] = 'Observations obligatoires si ecart non nul (' . format_htg($ecart) . ').';
            }

            if (!$errors) {
                $stmt = db()->prepare(
                    'INSERT INTO rapprochements_bancaires
                        (mois, annee, releve_scan, solde_releve_sogebank, total_paiements_app,
                         ecart, observations, valide_administrateur, date_validation, valide_par)
                     VALUES (?,?,?,?,?,?,?,1,NOW(),?)'
                );
                $stmt->execute([
                    $mois, $annee, $relevePath, $solde1, $totalApp, $ecart,
                    $observations ?: null, (int)user_id()
                ]);
                $id = (int)db()->lastInsertId();
                audit_log('rapport_genere', "Rapprochement bancaire M$mois/$annee", 'rapprochements_bancaires', $id);
                flash_set('success', 'Rapprochement enregistre. Ecart : ' . format_htg($ecart));
                redirect('/portail/compta/rapprochement.php');
            }
        }
    } catch (Throwable $e) {
        error_log('rapprochement create: ' . $e->getMessage());
        $errors[] = 'Erreur technique.';
    }
}

$pageTitle = 'Rapprochement bancaire';
$activeMenu = 'compta';

if ($action === 'new') {
    check_role(['administrateur']);
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="bi bi-bank"></i> Nouveau rapprochement bancaire</h1>
        <a href="/portail/compta/rapprochement.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
        <div class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Mois <span class="text-danger">*</span></label>
                    <select name="mois" class="form-select" required>
                        <?php for ($m=1;$m<=12;$m++): ?>
                            <option value="<?= $m ?>" <?= ($m===(int)date('m')-1)?'selected':'' ?>><?= e(mois_fr($m)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Annee <span class="text-danger">*</span></label>
                    <input type="number" name="annee" class="form-control" value="<?= date('Y') ?>" min="2020" max="2030">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Scan du releve SOGEBANK <span class="text-danger">*</span></label>
                    <input type="file" name="releve" class="form-control" accept="application/pdf,image/jpeg,image/png" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Solde du releve (saisie 1) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="solde1" class="form-control text-end" step="0.01" required>
                        <span class="input-group-text">HTG</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirmer solde (saisie 2) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="solde2" class="form-control text-end" step="0.01" required>
                        <span class="input-group-text">HTG</span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Observations (obligatoire si ecart)</label>
                    <textarea name="observations" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <hr>
            <button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer le rapprochement</button>
        </div>
    </form>
    <?php
    require __DIR__ . '/../includes/footer.php';
} else {
    $stmt = db()->query(
        "SELECT r.*, u.nom_complet AS valide_par_nom
           FROM rapprochements_bancaires r
           LEFT JOIN users u ON r.valide_par = u.id
          ORDER BY r.annee DESC, r.mois DESC"
    );
    $rows = $stmt->fetchAll();
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="bi bi-bank"></i> Rapprochements bancaires</h1>
        <?php if (user_role() === 'administrateur'): ?>
        <a href="?action=new" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Nouveau rapprochement</a>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr>
                    <th>Periode</th><th class="text-end">Solde releve</th>
                    <th class="text-end">Total paiements app</th><th class="text-end">Ecart</th>
                    <th>Valide par</th><th>Releve</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucun rapprochement enregistre.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong>M<?= str_pad((string)$r['mois'],2,'0',STR_PAD_LEFT) ?> / <?= e((string)$r['annee']) ?></strong></td>
                        <td class="text-end font-monospace"><?= format_htg($r['solde_releve_sogebank']) ?></td>
                        <td class="text-end font-monospace"><?= format_htg($r['total_paiements_app']) ?></td>
                        <td class="text-end font-monospace <?= abs((float)$r['ecart']) > 0.01 ? 'text-danger' : 'text-success' ?>"><?= format_htg($r['ecart']) ?></td>
                        <td><small><?= e($r['valide_par_nom'] ?? '-') ?></small></td>
                        <td>
                            <?php if ($r['releve_scan']): ?>
                                <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$r['releve_scan'])) ?>&type=pdf" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-pdf"></i></a>
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
    require __DIR__ . '/../includes/footer.php';
}
