<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../models/ImputationModel.php';
require_once __DIR__ . '/../models/AsfModel.php';
require_once __DIR__ . '/../services/TokenService.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$action = (string)($_GET['action'] ?? 'list');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$impId  = isset($_GET['imputation_id']) ? (int)$_GET['imputation_id'] : 0;
$errors = [];

// =====================================================================
// POST : Certifier un ASF
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'certify') {
    check_role(['coordinateur']);
    try {
        verify_csrf();
        $impId = (int)($_POST['imputation_id'] ?? 0);
        $imp = ImputationModel::find($impId);
        if (!$imp) {
            $errors[] = 'Imputation introuvable.';
        } elseif (AsfModel::findByImputation($impId)) {
            $errors[] = 'Un ASF existe deja pour cette imputation.';
        }

        $livrables = trim((string)($_POST['livrables_realises'] ?? ''));
        $statut    = (string)($_POST['statut_livrables'] ?? '');
        $taux      = isset($_POST['taux_presence']) && $_POST['taux_presence'] !== ''
            ? (int)$_POST['taux_presence'] : null;
        $observations = trim((string)($_POST['observations'] ?? ''));

        if ($livrables === '')                $errors[] = 'Livrables realises obligatoire.';
        if (!in_whitelist($statut, ['conformes','partiels','non_conformes'])) {
            $errors[] = 'Statut livrables invalide.';
        }
        if ($taux !== null && ($taux < 0 || $taux > 100)) {
            $errors[] = 'Taux de presence entre 0 et 100.';
        }

        // Upload pieces jointes (optionnel, JSON)
        $piecesJointes = [];
        if (!empty($_FILES['pieces']['name'][0] ?? null)) {
            $destDir = __DIR__ . '/../storage/asf/' . date('Y') . '/M' . str_pad((string)date('m'),2,'0',STR_PAD_LEFT) . '/' . ($imp['numero'] ?? 'unknown');
            $count = count($_FILES['pieces']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['pieces']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $singleFile = [
                    'name'     => $_FILES['pieces']['name'][$i],
                    'type'     => $_FILES['pieces']['type'][$i],
                    'tmp_name' => $_FILES['pieces']['tmp_name'][$i],
                    'error'    => $_FILES['pieces']['error'][$i],
                    'size'     => $_FILES['pieces']['size'][$i],
                ];
                $typeLabel = $_POST['piece_types'][$i] ?? 'autre';
                $up = handle_upload($singleFile, $destDir);
                if (!$up['success']) {
                    $errors[] = 'Piece "' . e($singleFile['name']) . '" : ' . ($up['error'] ?? 'echec');
                } else {
                    $piecesJointes[] = ['type' => $typeLabel, 'fichier' => storage_relative_path($up['path'])];
                }
            }
        }

        if (!$errors && $imp) {
            // Recupere la signature du Coordinateur
            $stmt = db()->prepare('SELECT signature_image FROM users WHERE id=?');
            $stmt->execute([(int)user_id()]);
            $sigCoord = $stmt->fetchColumn() ?: null;

            // Transaction PDO
            db()->beginTransaction();
            try {
                $numero = generate_numero('ASF', 'attestations_service_fait');
                $asfId = AsfModel::create([
                    'numero' => $numero,
                    'imputation_id' => $impId,
                    'livrables_realises' => $livrables,
                    'statut_livrables' => $statut,
                    'taux_presence' => $taux,
                    'pieces_jointes_json' => $piecesJointes ?: null,
                    'observations' => $observations ?: null,
                    'certifie_coordinateur' => 1,
                    'sig_coord_scan' => $sigCoord,
                    'date_certification' => date('Y-m-d H:i:s'),
                    'certifie_par' => (int)user_id(),
                ]);

                audit_log('asf_certifiee', "Certification ASF $numero", 'attestations_service_fait', $asfId);

                // Generation du token NH (72h) pour le prestataire
                $stmt = db()->prepare(
                    "SELECT p.email, p.nom_complet
                       FROM contrats c
                       JOIN prestataires p ON c.prestataire_id = p.id
                      WHERE c.id = ?"
                );
                $stmt->execute([(int)$imp['contrat_id']]);
                $prestataire = $stmt->fetch();

                if ($prestataire && !empty($prestataire['email'])) {
                    TokenService::create(
                        'note_honoraires',
                        $impId,
                        $prestataire['email'],
                        $prestataire['nom_complet'],
                        'Votre ASF (Attestation de Service Fait) vient d\'etre certifie pour le dossier '
                        . $imp['numero'] . '. Vous pouvez maintenant soumettre votre Note d\'Honoraires.'
                    );
                }

                db()->commit();
                flash_set('success', "ASF $numero certifie. Lien NH envoye au prestataire" . ($prestataire ? ' (' . e($prestataire['email']) . ')' : ''));
                redirect('/portail/compta/asf.php?action=view&id=' . $asfId);
            } catch (Throwable $e) {
                db()->rollBack();
                error_log('asf certify failed: ' . $e->getMessage());
                $errors[] = 'Echec certification : ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        error_log('asf POST error: ' . $e->getMessage());
        $errors[] = 'Erreur technique.';
    }
}

// =====================================================================
// ROUTAGE
// =====================================================================
$pageTitle  = 'ASF - Attestations de Service Fait';
$activeMenu = 'compta';

if ($action === 'view' && $id > 0) {
    $asf = AsfModel::find($id);
    if (!$asf) {
        flash_set('danger', 'ASF introuvable.');
        redirect('/portail/compta/asf.php');
    }
    require __DIR__ . '/../includes/header.php';
    render_view($asf);
    require __DIR__ . '/../includes/footer.php';

} elseif ($action === 'new' && $impId > 0) {
    check_role(['coordinateur']);
    $imp = ImputationModel::find($impId);
    if (!$imp) { flash_set('danger', 'Imputation introuvable.'); redirect('/portail/compta/asf.php'); }

    require_once __DIR__ . '/../models/DecaissementModel.php';
    $f02 = DecaissementModel::findByImputation($impId);
    if (!$f02) {
        flash_set('warning', 'F02 doit etre valide avant la certification ASF.');
        redirect('/portail/compta/asf.php');
    }
    if (AsfModel::findByImputation($impId)) {
        flash_set('info', 'ASF deja certifie pour cette imputation.');
        redirect('/portail/compta/asf.php?action=view&id=' . AsfModel::findByImputation($impId)['id']);
    }

    require __DIR__ . '/../includes/header.php';
    render_form($imp, $f02, $errors, $_POST);
    require __DIR__ . '/../includes/footer.php';

} else {
    $pending = AsfModel::pendingList();
    $sql = "SELECT a.*, i.numero AS f01_numero, p.nom_complet AS prestataire,
                   c.numero AS contrat_numero
              FROM attestations_service_fait a
              JOIN imputations i  ON a.imputation_id = i.id
              JOIN contrats c     ON i.contrat_id = c.id
              JOIN prestataires p ON c.prestataire_id = p.id
             ORDER BY a.date_certification DESC LIMIT 100";
    $certified = db()->query($sql)->fetchAll();

    require __DIR__ . '/../includes/header.php';
    render_list($pending, $certified);
    require __DIR__ . '/../includes/footer.php';
}

// =====================================================================
function render_list(array $pending, array $certified): void
{
    $role = user_role();
?>
<h1 class="h3 mb-3"><i class="bi bi-patch-check"></i> ASF - Attestations de Service Fait</h1>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-pending">
            En attente <span class="badge bg-warning text-dark"><?= count($pending) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-certified">
            Certifies <span class="badge bg-success"><?= count($certified) ?></span>
        </a>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-pending">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>F02</th><th>F01</th><th>Date</th><th>Beneficiaire</th>
                        <th class="text-end">Montant</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$pending): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucun F02 en attente d'ASF.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pending as $r): ?>
                        <tr>
                            <td><?= e($r['f02_numero']) ?></td>
                            <td><small><?= e($r['f01_numero']) ?></small></td>
                            <td><?= e(date('d/m/Y', strtotime($r['date_depense']))) ?></td>
                            <td><?= e($r['prestataire']) ?></td>
                            <td class="text-end font-monospace"><?= format_htg($r['montant']) ?></td>
                            <td>
                                <?php if ($role === 'coordinateur'): ?>
                                <a href="?action=new&imputation_id=<?= (int)$r['imputation_id'] ?>" class="btn btn-sm btn-primary">
                                    Certifier ASF
                                </a>
                                <?php else: ?>
                                <a href="/portail/compta/f02.php?action=view&id=<?= (int)$r['f02_id'] ?>" class="btn btn-sm btn-outline-secondary">Voir F02</a>
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

    <div class="tab-pane fade" id="tab-certified">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>ASF</th><th>F01</th><th>Beneficiaire</th><th>Statut livrables</th><th>Certifie le</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$certified): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucun ASF certifie.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($certified as $r):
                        $bd = ['conformes'=>'success','partiels'=>'warning','non_conformes'=>'danger'][$r['statut_livrables']] ?? 'secondary';
                    ?>
                        <tr>
                            <td><a href="?action=view&id=<?= (int)$r['id'] ?>" class="fw-bold text-decoration-none"><?= e($r['numero']) ?></a></td>
                            <td><small><?= e($r['f01_numero']) ?></small></td>
                            <td><?= e($r['prestataire']) ?></td>
                            <td><span class="badge bg-<?= $bd ?>"><?= e($r['statut_livrables']) ?></span></td>
                            <td><?= $r['date_certification'] ? e(date('d/m/Y', strtotime($r['date_certification']))) : '-' ?></td>
                            <td><a href="?action=view&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
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

function render_form(array $imp, array $f02, array $errors, array $post): void
{
    $typeContrat = $imp['type_contrat'];
    $tauxRequis = $typeContrat !== 'CPSP'; // CPSP = prestation ponctuelle, pas de taux
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-patch-check"></i> Certifier un ASF</h1>
    <a href="/portail/compta/asf.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Dossier</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">F01</dt><dd class="col-7"><?= e($imp['numero']) ?></dd>
                    <dt class="col-5">F02</dt><dd class="col-7"><?= e($f02['numero']) ?> (valide)</dd>
                    <dt class="col-5">Beneficiaire</dt><dd class="col-7"><?= e($imp['prestataire']) ?></dd>
                    <dt class="col-5">Contrat</dt><dd class="col-7"><?= e($imp['contrat_numero']) ?> (<?= e($typeContrat) ?>)</dd>
                    <dt class="col-5">Montant net</dt><dd class="col-7 font-monospace"><?= format_htg($f02['total_net_a_verser']) ?></dd>
                    <dt class="col-5">Description</dt><dd class="col-7"><small><?= nl2br(e($imp['description'])) ?></small></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="certify">
                <input type="hidden" name="imputation_id" value="<?= (int)$imp['id'] ?>">

                <div class="mb-3">
                    <label class="form-label">Livrables realises <span class="text-danger">*</span></label>
                    <textarea name="livrables_realises" class="form-control" rows="4" required><?= e($post['livrables_realises'] ?? '') ?></textarea>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Statut des livrables <span class="text-danger">*</span></label>
                        <select name="statut_livrables" class="form-select" required>
                            <option value="conformes" <?= (($post['statut_livrables'] ?? '')==='conformes')?'selected':'' ?>>Conformes</option>
                            <option value="partiels" <?= (($post['statut_livrables'] ?? '')==='partiels')?'selected':'' ?>>Partiels</option>
                            <option value="non_conformes" <?= (($post['statut_livrables'] ?? '')==='non_conformes')?'selected':'' ?>>Non conformes</option>
                        </select>
                    </div>
                    <?php if ($tauxRequis): ?>
                    <div class="col-md-6">
                        <label class="form-label">Taux de presence (%)</label>
                        <input type="number" name="taux_presence" class="form-control" min="0" max="100"
                               value="<?= e((string)($post['taux_presence'] ?? '')) ?>">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label">Pieces jointes (optionnel - listes presence, photos terrain)</label>
                    <div id="pieces-container">
                        <div class="input-group mb-2">
                            <select name="piece_types[]" class="form-select" style="max-width:200px;">
                                <option value="liste_presence">Liste de presence</option>
                                <option value="photo_terrain">Photo terrain</option>
                                <option value="autre">Autre</option>
                            </select>
                            <input type="file" name="pieces[]" class="form-control" accept="application/pdf,image/jpeg,image/png">
                        </div>
                    </div>
                    <button type="button" id="add-piece" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-plus"></i> Ajouter une piece
                    </button>
                </div>

                <div class="mb-3">
                    <label class="form-label">Observations (optionnel)</label>
                    <textarea name="observations" class="form-control" rows="2"><?= e($post['observations'] ?? '') ?></textarea>
                </div>

                <hr>
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle"></i> A la certification, un lien tokenise (valide 72h) sera envoye automatiquement
                    par email au prestataire <strong><?= e($imp['prestataire']) ?></strong> pour qu'il soumette sa Note d'Honoraires.
                </div>

                <button class="btn btn-primary w-100" onclick="return confirm('Certifier definitivement cet ASF ?');">
                    <i class="bi bi-patch-check"></i> Certifier et generer le lien NH
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('add-piece').addEventListener('click', () => {
    const c = document.getElementById('pieces-container');
    const row = c.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    c.appendChild(row);
});
</script>
<?php
}

function render_view(array $asf): void
{
    $pieces = $asf['pieces_jointes_json'] ? json_decode($asf['pieces_jointes_json'], true) : [];
    $bd = ['conformes'=>'success','partiels'=>'warning','non_conformes'=>'danger'][$asf['statut_livrables']] ?? 'secondary';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">ASF <?= e($asf['numero']) ?>
            <span class="badge bg-<?= $bd ?>"><?= e($asf['statut_livrables']) ?></span>
        </h1>
        <small class="text-muted">F01 source : <a href="/portail/compta/f01.php?action=view&id=<?= (int)$asf['imputation_id'] ?>"><?= e($asf['imputation_numero']) ?></a></small>
    </div>
    <a href="/portail/compta/asf.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-4">Beneficiaire</dt><dd class="col-8"><?= e($asf['prestataire']) ?></dd>
                    <dt class="col-4">Contrat</dt><dd class="col-8"><?= e($asf['contrat_numero']) ?> (<?= e($asf['type_contrat']) ?>)</dd>
                    <dt class="col-4">Livrables realises</dt><dd class="col-8"><?= nl2br(e($asf['livrables_realises'])) ?></dd>
                    <dt class="col-4">Statut livrables</dt><dd class="col-8"><span class="badge bg-<?= $bd ?>"><?= e($asf['statut_livrables']) ?></span></dd>
                    <?php if ($asf['taux_presence'] !== null): ?>
                        <dt class="col-4">Taux de presence</dt><dd class="col-8"><?= (int)$asf['taux_presence'] ?> %</dd>
                    <?php endif; ?>
                    <?php if ($asf['observations']): ?>
                        <dt class="col-4">Observations</dt><dd class="col-8"><?= nl2br(e((string)$asf['observations'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($pieces): ?>
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-white"><strong>Pieces jointes</strong></div>
            <div class="card-body">
                <ul class="list-group">
                    <?php foreach ($pieces as $p): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="bi bi-paperclip"></i> <?= e($p['type']) ?></span>
                            <a href="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$p['fichier'])) ?>&type=pdf" target="_blank">Voir</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><strong>Certification</strong></div>
            <div class="card-body">
                <p class="mb-1"><strong>Certifie par</strong> : <?= e($asf['certifie_par_nom'] ?? '-') ?></p>
                <p class="mb-1"><strong>Date</strong> : <?= $asf['date_certification'] ? e(date('d/m/Y H:i', strtotime($asf['date_certification']))) : '-' ?></p>
                <?php if ($asf['sig_coord_scan'] && is_file(storage_absolute_path($asf['sig_coord_scan']))): ?>
                    <img src="/portail/pdf/serve.php?path=<?= urlencode(str_replace('storage/','',$asf['sig_coord_scan'])) ?>&type=sig"
                         alt="Signature" style="max-height:60px; background:#f8f9fa; padding:4px;">
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Affichage statut NH (token + soumission)
        $stmt = db()->prepare(
            "SELECT t.id, t.utilise, t.expire_at, t.email_destinataire, nh.id AS nh_id, nh.numero AS nh_numero
               FROM tokens t
               LEFT JOIN notes_honoraires nh ON nh.imputation_id = t.imputation_id
              WHERE t.imputation_id = ? AND t.type = 'note_honoraires'
              ORDER BY t.id DESC LIMIT 1"
        );
        $stmt->execute([(int)$asf['imputation_id']]);
        $tk = $stmt->fetch();
        ?>
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-white"><strong>Note d'Honoraires</strong></div>
            <div class="card-body">
                <?php if (!$tk): ?>
                    <p class="text-muted small mb-0">Aucun token NH genere.</p>
                <?php elseif ($tk['nh_id']): ?>
                    <p class="mb-1"><i class="bi bi-check-circle text-success"></i> NH soumise par le prestataire</p>
                    <p class="mb-0 small">N° <?= e($tk['nh_numero']) ?></p>
                <?php elseif ((int)$tk['utilise'] === 0 && strtotime($tk['expire_at']) > time()): ?>
                    <p class="mb-1"><i class="bi bi-hourglass-split text-warning"></i> En attente du prestataire</p>
                    <p class="mb-0 small">Lien envoye a <?= e($tk['email_destinataire']) ?>, expire le <?= e(date('d/m/Y H:i', strtotime($tk['expire_at']))) ?></p>
                <?php else: ?>
                    <p class="mb-1"><i class="bi bi-x-circle text-danger"></i> Token expire ou utilise</p>
                    <?php if (user_role() === 'administrateur'): ?>
                        <a href="?action=regen_nh&token_id=<?= (int)$tk['id'] ?>" class="btn btn-sm btn-outline-warning">Renvoyer le lien</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
}
