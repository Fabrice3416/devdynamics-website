<?php
declare(strict_types=1);

/**
 * Financement - demandes de versement de tranche (CDC 4.10).
 *
 * La demande reprend le mecanisme de checklist du dossier de depense, avec une
 * liste qui differe selon la tranche : la premiere ne demande que le contrat signe,
 * la demande de paiement, la fiche signaletique et les pieces d'identite des
 * signataires ; les suivantes y ajoutent les rapports figes.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/financement.php';
require_role(['coordinateur', 'raf']);
require_module('financement');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Préparer une demande de versement');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'ouvrir') {
        $res = demande_ouvrir((int)($_POST['tranche_id'] ?? 0));
    } elseif ($action === 'piece') {
        $res = piece_demande_verser((int)($_POST['piece_id'] ?? 0), $_FILES['scan'] ?? null);
    } elseif ($action === 'valider') {
        $res = demande_valider((int)($_POST['demande_id'] ?? 0),
            (int)($_POST['rapport_id'] ?? 0) ?: null);
    } elseif ($action === 'transmettre') {
        $res = demande_transmettre((int)($_POST['demande_id'] ?? 0),
            (string)($_POST['date'] ?? date('Y-m-d')), $_FILES['accuse'] ?? null);
    } elseif ($action === 'complement') {
        $res = demande_complement((int)($_POST['demande_id'] ?? 0), (string)($_POST['date'] ?? date('Y-m-d')));
    } elseif ($action === 'repondre') {
        $res = demande_repondre_complement((int)($_POST['demande_id'] ?? 0), (string)($_POST['date'] ?? date('Y-m-d')));
    } elseif ($action === 'produire') {
        $d = demande_paiement((int)($_POST['demande_id'] ?? 0));
        $res = $d === null ? ['success' => false, 'error' => 'Demande inconnue.']
            : document_generer('demande_paiement', ['demande' => $d, 'pieces' => pieces_demande((int)$d['id']),
                                                     'tresorerie' => tresorerie()],
                'demande_paiement', (int)$d['id'], 'financement', (string)($_POST['exemplaire'] ?? ''));
        if (!empty($res['success'])) {
            redirect(base_path('pdf/serve.php?id=' . (int)$res['fichier_id']));
        }
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/financement/demandes.php'));
    }
}

$liste = demandes_paiement();
foreach ($liste as $d) {
    demande_constater_paiement((int)$d['id']);
}
$liste = demandes_paiement();
$listeTranches = tranches();
$rapportsFiges = array_filter(rapports_restitution(), fn($r) => in_array($r['statut'], ['valide', 'transmis'], true));
$ouverte = (int)($_GET['demande'] ?? ($liste[0]['id'] ?? 0));
$demande = $ouverte > 0 ? demande_paiement($ouverte) : null;
$exemplaires = mentions_exemplaires();

$ongletActif = 'demandes';
page_start('Demandes de versement', 'financement');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-3">Demandes de versement</h1>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-send"></i> Demandes</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr class="small text-muted">
                    <th>Tranche</th><th class="text-end">Montant</th><th>Pièces</th><th>Statut</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($liste as $d): ?>
                <tr>
                    <td>N° <?= (int)$d['tranche_numero'] ?><br><small class="text-muted"><?= e(date_fr($d['date'])) ?></small></td>
                    <td class="text-end"><?= e(htg((float)$d['montant'])) ?></td>
                    <td class="small text-muted"><?= (int)$d['pieces_manquantes'] > 0
                        ? (int)$d['pieces_manquantes'] . ' attendue(s)' : '<span class="text-success">complètes</span>' ?></td>
                    <td><span class="badge text-bg-light border"><?= e(STATUTS_DEMANDE[$d['statut']]) ?></span>
                        <?php if ($d['date_transmission']): ?><br><small class="text-muted">transmise le <?= e(date_fr($d['date_transmission'])) ?></small><?php endif; ?>
                        <?php if ($d['date_demande_complement']): ?><br><small class="text-muted">complément le <?= e(date_fr($d['date_demande_complement'])) ?></small><?php endif; ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-link p-0" href="?demande=<?= (int)$d['id'] ?>">ouvrir</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$liste): ?><tr><td colspan="5" class="text-muted p-3">Aucune demande.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php if (user_role() === 'raf'): ?>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ouvrir">
                    <div class="col-8">
                        <label class="form-label small mb-1">Ouvrir une demande pour la tranche</label>
                        <select class="form-select form-select-sm" name="tranche_id" required>
                            <?php foreach ($listeTranches as $t): if ($t['montant_recu'] !== null) continue; ?>
                            <option value="<?= (int)$t['id'] ?>">N° <?= (int)$t['numero'] ?>
                                <?= $t['montant_contractuel'] === null ? '(montant à saisir)' : '— ' . e(htg((float)$t['montant_contractuel'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4"><button class="btn btn-primary btn-sm w-100">Ouvrir</button></div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <?php if ($demande !== null): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-square"></i>
                Pièces de la demande — tranche <?= (int)$demande['tranche_numero'] ?></div>
            <table class="table table-sm mb-0">
                <?php foreach (pieces_demande($ouverte) as $p): ?>
                <tr>
                    <td class="small"><?= e($p['libelle']) ?></td>
                    <td class="text-end" style="width:17rem">
                        <?php if ($p['statut'] === 'recue'): ?>
                            <a href="<?= e(base_path('pdf/serve.php?id=' . (int)$p['fichier_id'])) ?>">
                                <i class="bi bi-check2"></i> reçue</a>
                        <?php elseif (!in_array($demande['statut'], ['transmise', 'payee'], true)): ?>
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-1 justify-content-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="piece">
                                <input type="hidden" name="piece_id" value="<?= (int)$p['id'] ?>">
                                <input type="file" class="form-control form-control-sm" name="scan" accept=".pdf,.jpg,.jpeg,.png" required>
                                <button class="btn btn-sm btn-outline-secondary">Verser</button>
                            </form>
                        <?php else: ?><span class="text-muted small">attendue</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-right-circle"></i> Circuit</div>
            <div class="card-body">
                <?php if ($demande['statut'] === 'preparation'): ?>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="valider">
                    <input type="hidden" name="demande_id" value="<?= $ouverte ?>">
                    <?php if ((int)$demande['tranche_numero'] !== 1): ?>
                    <div class="col-8">
                        <label class="form-label small mb-1">Rapport figé à joindre</label>
                        <select class="form-select form-select-sm" name="rapport_id" required>
                            <option value="">—</option>
                            <?php foreach ($rapportsFiges as $rf): ?>
                            <option value="<?= (int)$rf['id'] ?>"><?= e(TYPES_RAPPORT[$rf['type']]) ?>
                                — <?= e(date_fr($rf['periode_fin'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="col-8"><div class="form-text">Première tranche : elle ne demande aucun rapport,
                        seulement le contrat signé, la demande de paiement, la fiche signalétique et les pièces
                        d'identité des signataires.</div></div>
                    <?php endif; ?>
                    <div class="col-4"><button class="btn btn-primary btn-sm w-100">Valider</button></div>
                </form>

                <?php elseif ($demande['statut'] === 'validee'): ?>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="transmettre">
                    <input type="hidden" name="demande_id" value="<?= $ouverte ?>">
                    <div class="col-4">
                        <label class="form-label small mb-1">Date de transmission</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small mb-1">Accusé de réception</label>
                        <input type="file" class="form-control form-control-sm" name="accuse" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-3"><button class="btn btn-primary btn-sm w-100">Transmettre</button></div>
                    <div class="col-12"><div class="form-text">La date de transmission ouvre le délai contractuel
                        de cinq jours et se conserve avec l'accusé.</div></div>
                </form>

                <?php elseif ($demande['statut'] === 'transmise'): ?>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="complement">
                    <input type="hidden" name="demande_id" value="<?= $ouverte ?>">
                    <div class="col-8">
                        <label class="form-label small mb-1">Complément réclamé par l'UGP, le</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-4"><button class="btn btn-outline-secondary btn-sm w-100">Enregistrer</button></div>
                    <div class="col-12"><div class="form-text">L'article 4.3 autorise l'UGP à réclamer des
                        informations complémentaires sous trente jours.</div></div>
                </form>

                <?php elseif ($demande['statut'] === 'complement_demande'): ?>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="repondre">
                    <input type="hidden" name="demande_id" value="<?= $ouverte ?>">
                    <div class="col-8">
                        <label class="form-label small mb-1">Réponse produite le</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-4"><button class="btn btn-primary btn-sm w-100">Enregistrer</button></div>
                </form>

                <?php else: ?>
                <p class="text-muted mb-0">Demande <?= e(STATUTS_DEMANDE[$demande['statut']]) ?>.
                    <?php if ($demande['rapport_ref']): ?>Rapport joint : <?= e($demande['rapport_ref']) ?>.<?php endif; ?></p>
                <?php endif; ?>

                <hr>
                <form method="post" class="d-flex gap-2 align-items-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="produire">
                    <input type="hidden" name="demande_id" value="<?= $ouverte ?>">
                    <?php if ($exemplaires): ?>
                    <select class="form-select form-select-sm" style="width:16rem" name="exemplaire">
                        <option value="">sans mention</option>
                        <?php foreach ($exemplaires as $ex): ?>
                        <option value="<?= e($ex) ?>"><?= e($ex) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button class="btn btn-link btn-sm p-0"><i class="bi bi-file-earmark-pdf"></i>
                        Produire la demande de paiement</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
