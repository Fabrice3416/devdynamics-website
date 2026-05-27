<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../models/CaisseModel.php';

check_role(['administrateur', 'coordinateur']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'demander') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $motif = (string)($_POST['motif'] ?? 'seuil_atteint');
        if (!in_whitelist($motif, ['seuil_atteint','cloture_mensuelle'])) {
            flash_set('danger', 'Motif invalide.');
        } else {
            $id = CaisseModel::demanderRenflouement((int)user_id(), $motif);
            audit_log('renflouement_demande', "Renflouement demande #$id ($motif)", 'caisse_renflouements', $id);
            flash_set('success', 'Demande de renflouement creee. Le gel des saisies PC est actif jusqu au versement.');
        }
    } catch (Throwable $e) {
        flash_set('danger', 'Erreur.');
    }
    redirect('/portail/compta/petite_caisse_renflouement.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'valider') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        // Valide => cree une F01 auto liee au CONTRAT-INTERNE-DEVDYN
        db()->beginTransaction();
        $stmt = db()->prepare('SELECT * FROM caisse_renflouements WHERE id=? AND statut=\'demande\'');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            db()->rollBack();
            flash_set('warning', 'Renflouement introuvable ou deja traite.');
            redirect('/portail/compta/petite_caisse_renflouement.php');
        }

        $stmt = db()->prepare("SELECT id FROM contrats WHERE numero='CONTRAT-INTERNE-DEVDYN' LIMIT 1");
        $stmt->execute();
        $contratInterneId = (int)$stmt->fetchColumn();

        $stmt = db()->prepare("SELECT id FROM lignes_budgetaires WHERE code = '3.2' OR libelle LIKE '%Petite Caisse%' LIMIT 1");
        $stmt->execute();
        $ligneId = (int)$stmt->fetchColumn();

        // Cree la F01 auto
        require_once __DIR__ . '/../models/ImputationModel.php';
        $numero = generate_numero('F01', 'imputations');
        $impId = ImputationModel::create([
            'numero' => $numero,
            'date_depense' => date('Y-m-d'),
            'contrat_id' => $contratInterneId,
            'ligne_budgetaire_id' => $ligneId,
            'rubrique' => 'autre',
            'nature_paiement' => 'renflouement_petite_caisse',
            'description' => 'Renflouement Petite Caisse ' . $r['numero'] . ' (montant = ' . format_htg($r['montant_renflouement']) . ')',
            'montant' => $r['montant_renflouement'],
            'statut' => 'soumis',
            'soumis_par' => (int)user_id(),
        ]);

        // Marque le renflouement comme valide et lie a la F01
        $stmt = db()->prepare("UPDATE caisse_renflouements SET statut='valide', imputation_id=? WHERE id=?");
        $stmt->execute([$impId, $id]);

        audit_log('renflouement_demande', "Renflouement #$id valide, F01 $numero generee", 'caisse_renflouements', $id);
        db()->commit();
        flash_set('success', "Renflouement valide. F01 $numero generee. Procedure de paiement : F02 puis FRP (2 signatures).");
        redirect('/portail/compta/petite_caisse_renflouement.php');
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('renflouement valider: ' . $e->getMessage());
        flash_set('danger', 'Erreur : ' . $e->getMessage());
        redirect('/portail/compta/petite_caisse_renflouement.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verser') {
    check_role(['administrateur']);
    try {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        db()->beginTransaction();
        $stmt = db()->prepare("UPDATE caisse_renflouements SET statut='verse', date_versement=CURDATE() WHERE id=? AND statut='valide'");
        $stmt->execute([$id]);
        // Marque toutes les transactions PC anterieures comme renflouees
        $stmt = db()->prepare("UPDATE caisse_transactions SET renflouement_id=? WHERE renflouement_id IS NULL AND valide_administrateur=1");
        $stmt->execute([$id]);
        audit_log('renflouement_verse', "Renflouement #$id verse - transactions soldees", 'caisse_renflouements', $id);
        db()->commit();
        flash_set('success', 'Renflouement verse. Le solde Petite Caisse est ramene a 30 000 HTG. Gel leve.');
    } catch (Throwable $e) {
        db()->rollBack();
        flash_set('danger', 'Erreur.');
    }
    redirect('/portail/compta/petite_caisse_renflouement.php');
}

$stmt = db()->query(
    "SELECT r.*, u.nom_complet AS demandeur, i.numero AS f01_numero, i.statut AS f01_statut
       FROM caisse_renflouements r
       LEFT JOIN users u ON r.created_by = u.id
       LEFT JOIN imputations i ON r.imputation_id = i.id
      ORDER BY r.id DESC"
);
$rows = $stmt->fetchAll();

$renflouementEnCours = CaisseModel::renflouementEnCours();
$soldeActuel = CaisseModel::solde();

$pageTitle = 'Renflouement Petite Caisse';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-droplet"></i> Renflouements Petite Caisse</h1>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100"><div class="card-body">
            <h6 class="text-uppercase small text-muted">Solde actuel</h6>
            <h3><?= format_htg($soldeActuel) ?></h3>
        </div></div>
    </div>
    <?php if (!$renflouementEnCours && user_role()==='administrateur'): ?>
    <div class="col-md-8">
        <form method="post" class="card shadow-sm border-0">
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="demander">
                <h6 class="mb-2">Demander un renflouement</h6>
                <div class="row g-2">
                    <div class="col-md-6">
                        <select name="motif" class="form-select form-select-sm">
                            <option value="seuil_atteint">Seuil atteint (< 9 000 HTG)</option>
                            <option value="cloture_mensuelle">Cloture mensuelle</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <button class="btn btn-sm btn-primary w-100"
                                onclick="return confirm('Confirmer la demande ? Les nouvelles depenses PC seront gelees.');">
                            <i class="bi bi-plus-circle"></i> Demander
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php elseif ($renflouementEnCours): ?>
    <div class="col-md-8">
        <div class="alert alert-warning mb-0">
            <i class="bi bi-snow"></i> Renflouement <strong><?= e($renflouementEnCours['numero']) ?></strong> en cours
            (statut : <?= e($renflouementEnCours['statut']) ?>). Aucune nouvelle demande possible.
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr>
                <th>N°</th><th>Date demande</th><th>Motif</th>
                <th class="text-end">Solde avant</th><th class="text-end">Montant</th>
                <th>F01 generee</th><th>Statut</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">Aucun renflouement.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['numero']) ?></strong></td>
                    <td><?= e(date('d/m/Y', strtotime($r['date_demande']))) ?></td>
                    <td><small><?= e($r['motif_declenchement']) ?></small></td>
                    <td class="text-end font-monospace"><?= format_htg($r['solde_avant']) ?></td>
                    <td class="text-end font-monospace fw-bold"><?= format_htg($r['montant_renflouement']) ?></td>
                    <td><?= $r['f01_numero'] ? '<a href="/portail/compta/f01.php?action=view&id=' . (int)$r['imputation_id'] . '">' . e($r['f01_numero']) . '</a>' : '-' ?></td>
                    <td><span class="badge bg-<?= $r['statut']==='verse'?'success':($r['statut']==='valide'?'info':'warning') ?>"><?= e($r['statut']) ?></span></td>
                    <td>
                        <?php if ($r['statut']==='demande' && user_role()==='administrateur'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Valider et generer la F01 ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="valider">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-info">Valider + F01</button>
                            </form>
                        <?php elseif ($r['statut']==='valide' && user_role()==='administrateur'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Confirmer le versement physique des especes ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="verser">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-success">Marquer verse</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
