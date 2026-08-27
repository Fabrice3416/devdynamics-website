<?php
declare(strict_types=1);

/** Signature - apposition du specimen sur un document en attente, apres reauthentification. */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/signature.php';
require_projet();
require_module('signature');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT d.*, f.nom_genere, f.empreinte, (SELECT COUNT(*) FROM appositions a WHERE a.document_id = d.id) AS nb FROM documents d LEFT JOIN fichiers f ON f.id = d.fichier_id WHERE d.id = ?');
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) {
    http_response_code(404);
    exit('404 - document introuvable');
}
$specimen = specimen_actif(user_id());
$resultat = null; $erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $qualite = (string)($_POST['qualite'] ?? 'approbation');
    $r = apposer($id, $qualite, (string)($_POST['password'] ?? ''));
    if ($r['success']) {
        $resultat = $r;
        $st->execute([$id]);
        $doc = $st->fetch();
    } else {
        $erreur = $r['error'];
    }
}

page_start('Apposer ma signature', 'signature');
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><?= e(DOCUMENTS_GENERES[$doc['type']][0] ?? $doc['type']) ?> · v<?= (int)$doc['version'] ?></div>
            <div class="card-body">
                <dl class="row small">
                    <dt class="col-4">Objet</dt><dd class="col-8"><?= e($doc['module']) ?> · <?= e($doc['objet_type']) ?> #<?= (int)$doc['objet_id'] ?></dd>
                    <dt class="col-4">Fichier</dt><dd class="col-8"><?php if ($doc['fichier_id']): ?><a target="_blank" href="<?= e(base_path('pdf/serve.php?id=' . (int)$doc['fichier_id'])) ?>"><?= e($doc['nom_genere']) ?></a><?php else: ?><span class="text-muted">aucun rendu</span><?php endif; ?></dd>
                    <dt class="col-4">Empreinte actuelle</dt><dd class="col-8" style="font-family:monospace;font-size:.7rem;word-break:break-all"><?= e($doc['empreinte'] ?? '') ?></dd>
                    <dt class="col-4">Statut</dt><dd class="col-8"><?= e($doc['statut']) ?> · <?= (int)$doc['nb'] ?> / <?= signatures_attendues($doc['type']) ?> signature(s)</dd>
                </dl>

                <?php if ($resultat): ?>
                    <div class="alert alert-success">
                        <b>Signature apposée.</b> Code de vérification : <span class="code-verification"><?= e($resultat['code']) ?></span><br>
                        <small>Le document est passé en statut <b><?= e($resultat['statut']) ?></b>. Ce code est imprimé sous votre bloc de signature.</small>
                    </div>
                    <a class="btn btn-outline-primary btn-sm" href="<?= e(base_path('modules/signature/')) ?>">Retour à la file</a>
                    <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= e(base_path('pdf/serve.php?id=' . (int)$doc['fichier_id'])) ?>">Voir le document signé</a>
                <?php elseif ($doc['statut'] !== 'a_signer'): ?>
                    <div class="alert alert-info py-2 small">Ce document n'est pas en attente de signature.</div>
                <?php elseif (!$specimen): ?>
                    <div class="alert alert-warning py-2 small">Aucun spécimen actif avec acte de dépôt : <a href="<?= e(base_path('modules/signature/specimen.php')) ?>">déposer un spécimen</a>.</div>
                <?php else: ?>
                    <?php if ($erreur): ?><div class="alert alert-danger py-2"><?= e($erreur) ?></div><?php endif; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="mb-3">
                            <label class="form-label">Qualité de la signature</label>
                            <select name="qualite" class="form-select">
                                <option value="approbation">Signature d'approbation interne</option>
                                <?php if (user_est_mandataire()): ?><option value="reglement">Signature de règlement (mandataire du compte)</option><?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mot de passe (réauthentification)</label>
                            <input type="password" name="password" class="form-control" required autocomplete="current-password">
                            <div class="form-text">Distincte de l'ouverture de session : elle vaut acte de signature, horodaté et nominatif.</div>
                        </div>
                        <button class="btn btn-primary"><i class="bi bi-pen"></i> Apposer ma signature</button>
                        <a class="btn btn-link" href="<?= e(base_path('modules/signature/')) ?>">Annuler</a>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php page_end();
