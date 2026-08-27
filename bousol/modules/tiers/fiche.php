<?php
declare(strict_types=1);

/**
 * Tiers - fiche d'un tiers : identite, piece d'identite au coffre, contrats et
 * representants.
 *
 * Le referentiel distingue trois qualites independantes (CDC 3.1) : la fonction
 * contractuelle qui determine la remuneration, le role applicatif qui determine les
 * droits, et la qualite de mandataire qui determine le pouvoir d'engagement. Seules
 * la premiere et la troisieme vivent ici ; le role est une affectation, donnee
 * projet par projet dans l'administration de l'outil.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/uploads.php';   // la piece d'identite va au coffre
require_projet();
require_module('tiers');

$peutEcrire = in_array(user_role(), ['coordinateur', 'raf'], true);
$id = (int)($_GET['id'] ?? 0);
$erreur = null;

$st = db()->prepare('SELECT * FROM tiers WHERE id = ?');
$st->execute([$id]);
$t = $st->fetch();
if ($t === false) {
    http_response_code(404);
    exit('404 - Tiers inconnu');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutEcrire) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'modifier') {
        $nom  = trim((string)($_POST['nom'] ?? ''));
        $nif  = trim((string)($_POST['nif'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $statut = (string)($_POST['statut_avancement'] ?? '');
        $dateConf = trim((string)($_POST['date_confirmation'] ?? ''));

        if ($nom === '') {
            $erreur = 'Le nom est obligatoire.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse électronique invalide.';
        } elseif ($statut !== '' && !array_key_exists($statut, STATUTS_AVANCEMENT)) {
            $erreur = 'Statut d\'avancement hors liste.';
        } else {
            try {
                db()->prepare(
                    'UPDATE tiers SET nom = ?, sigle = ?, fonction = ?, nif = ?, patente = ?, commune = ?,
                            domaine = ?, beneficiaire_paiesc = ?, date_confirmation = ?, statut_avancement = ?,
                            telephone = ?, email = ?, adresse = ?, coordonnees_reglement = ?
                      WHERE id = ?'
                )->execute([
                    $nom,
                    trim((string)($_POST['sigle'] ?? '')) ?: null,
                    trim((string)($_POST['fonction'] ?? '')) ?: null,
                    $nif ?: null,
                    trim((string)($_POST['patente'] ?? '')) ?: null,
                    trim((string)($_POST['commune'] ?? '')) ?: null,
                    trim((string)($_POST['domaine'] ?? '')) ?: null,
                    !empty($_POST['beneficiaire_paiesc']) ? 1 : 0,
                    $dateConf !== '' ? $dateConf : null,
                    $statut !== '' ? $statut : null,
                    trim((string)($_POST['telephone'] ?? '')) ?: null,
                    $email ?: null,
                    trim((string)($_POST['adresse'] ?? '')) ?: null,
                    trim((string)($_POST['coordonnees_reglement'] ?? '')) ?: null,
                    $id,
                ]);
                audit('tiers', 'tiers_modifie', 'tiers', $id, $nom . ($nif !== '' ? ' · NIF ' . $nif : ''));
                flash_set('success', 'Fiche mise à jour.');
                redirect(base_path('modules/tiers/fiche.php?id=' . $id));
            } catch (Throwable $ex) {
                $erreur = str_contains($ex->getMessage(), 'NIF deja enregistre')
                    ? 'NIF déjà enregistré au référentiel des tiers.'
                    : 'Modification impossible.';
                if ($erreur === 'Modification impossible.') {
                    error_log('modifier tiers: ' . $ex->getMessage());
                }
            }
        }
    } elseif ($action === 'piece') {
        // La piece d'identite va au coffre : elle est chiffree au repos et ne se
        // sert jamais en clair depuis la racine web (CDC 7.5).
        $up = enregistrer_upload($_FILES['piece'] ?? [], 'coffre',
            'TIERS-' . $id . '-IDENTITE.' . strtolower(pathinfo((string)($_FILES['piece']['name'] ?? ''), PATHINFO_EXTENSION)),
            ALLOWED_DOCUMENT, true, $t['piece_identite_fichier_id'] ? (int)$t['piece_identite_fichier_id'] : null);
        if (!$up['success']) {
            $erreur = 'Pièce d\'identité : ' . $up['error'];
        } else {
            db()->prepare('UPDATE tiers SET piece_identite_fichier_id = ? WHERE id = ?')->execute([(int)$up['id'], $id]);
            audit('tiers', 'piece_identite_versee', 'tiers', $id, 'Fichier #' . (int)$up['id']);
            flash_set('success', 'Pièce d\'identité versée au coffre.');
            redirect(base_path('modules/tiers/fiche.php?id=' . $id));
        }
    }
    $st->execute([$id]);
    $t = $st->fetch();
}

// Contrats du tiers, tous projets confondus mais lisibles seulement pour le projet courant.
$sc = db()->prepare(
    'SELECT c.*, l.code AS ligne_code, l.libelle AS ligne_libelle
       FROM contrats c LEFT JOIN lignes_budgetaires l ON l.id = c.ligne_id
      WHERE c.tiers_id = ? AND c.projet_id = ? ORDER BY c.date_debut DESC'
);
$sc->execute([$id, projet_id()]);
$contrats = $sc->fetchAll();

$representants = [];
if ($t['type'] === 'organisation') {
    $sr = db()->prepare('SELECT * FROM beneficiaires WHERE organisation_id = ? AND projet_id = ? ORDER BY nom');
    $sr->execute([$id, projet_id()]);
    $representants = $sr->fetchAll();
}

$piece = $t['piece_identite_fichier_id'] ? fichier((int)$t['piece_identite_fichier_id']) : null;

$ongletActif = 'referentiel';
page_start($t['nom'], 'tiers');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= e($t['nom']) ?>
        <small class="text-muted fw-normal">· <?= e(TYPES_TIERS[$t['type']] ?? $t['type']) ?></small></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_path('modules/tiers/index.php')) ?>">
        <i class="bi bi-arrow-left"></i> Référentiel</a>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-card-text"></i> Identité</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="modifier">
                    <fieldset <?= $peutEcrire ? '' : 'disabled' ?>>
                    <div class="row g-2">
                        <div class="col-8 mb-2">
                            <label class="form-label small mb-1">Nom ou raison sociale</label>
                            <input class="form-control form-control-sm" name="nom" value="<?= e($t['nom']) ?>" required maxlength="150">
                        </div>
                        <div class="col-4 mb-2">
                            <label class="form-label small mb-1">Sigle</label>
                            <input class="form-control form-control-sm" name="sigle" value="<?= e($t['sigle'] ?? '') ?>" maxlength="30">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">NIF <span class="text-muted">unique au référentiel</span></label>
                            <input class="form-control form-control-sm" name="nif" value="<?= e($t['nif'] ?? '') ?>" maxlength="30">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Patente</label>
                            <input class="form-control form-control-sm" name="patente" value="<?= e($t['patente'] ?? '') ?>" maxlength="50">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Fonction contractuelle</label>
                            <input class="form-control form-control-sm" name="fonction" value="<?= e($t['fonction'] ?? '') ?>" maxlength="120">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Commune</label>
                            <input class="form-control form-control-sm" name="commune" value="<?= e($t['commune'] ?? '') ?>" maxlength="80">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Domaine d'intervention</label>
                            <input class="form-control form-control-sm" name="domaine" value="<?= e($t['domaine'] ?? '') ?>" maxlength="150">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Statut d'avancement</label>
                            <select class="form-select form-select-sm" name="statut_avancement">
                                <option value="">—</option>
                                <?php foreach (STATUTS_AVANCEMENT as $k => $lib): ?>
                                <option value="<?= e($k) ?>" <?= (string)$t['statut_avancement'] === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Date de confirmation</label>
                            <input type="date" class="form-control form-control-sm" name="date_confirmation" value="<?= e($t['date_confirmation'] ?? '') ?>">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Téléphone</label>
                            <input class="form-control form-control-sm" name="telephone" value="<?= e($t['telephone'] ?? '') ?>" maxlength="40">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Courriel</label>
                            <input class="form-control form-control-sm" name="email" value="<?= e($t['email'] ?? '') ?>" maxlength="120">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Adresse</label>
                            <input class="form-control form-control-sm" name="adresse" value="<?= e($t['adresse'] ?? '') ?>" maxlength="255">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Coordonnées de règlement</label>
                            <input class="form-control form-control-sm" name="coordonnees_reglement" value="<?= e($t['coordonnees_reglement'] ?? '') ?>" maxlength="255">
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="beneficiaire_paiesc" id="bp" value="1" <?= $t['beneficiaire_paiesc'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="bp">Déjà bénéficiaire du bailleur</label>
                    </div>
                    <?php if ($peutEcrire): ?>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button>
                    <?php endif; ?>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-safe"></i> Pièce d'identité</div>
            <div class="card-body">
                <?php if ($piece): ?>
                <p class="small mb-2">Au coffre depuis le <?= e(date_fr(substr((string)$piece['created_at'], 0, 10))) ?>.
                    <br><span class="text-muted">Empreinte <?= e(substr($piece['empreinte'], 0, 16)) ?>…</span></p>
                <?php else: ?>
                <p class="small text-muted mb-2">Aucune pièce versée.</p>
                <?php endif; ?>
                <?php if ($peutEcrire): ?>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="piece">
                    <input type="file" class="form-control form-control-sm mb-2" name="piece" accept=".pdf,.jpg,.jpeg,.png" required>
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload"></i>
                        <?= $piece ? 'Remplacer' : 'Verser au coffre' ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-file-earmark-text"></i> Contrats sur ce projet</span>
                <a class="small fw-normal" href="<?= e(base_path('modules/tiers/contrats.php?tiers=' . $id)) ?>">Voir</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($contrats as $c): ?>
                <li class="list-group-item small">
                    <?= e(TYPES_CONTRAT[$c['type']] ?? $c['type']) ?> · <?= e($c['fonction']) ?>
                    <br><span class="text-muted"><?= e(date_fr($c['date_debut'])) ?> → <?= e(date_fr($c['date_fin'])) ?>
                        · <?= e(htg((float)$c['montant_total'])) ?>
                        <?php if ($c['ligne_code']): ?> · ligne <?= e($c['ligne_code']) ?><?php endif; ?></span>
                </li>
                <?php endforeach; ?>
                <?php if (!$contrats): ?><li class="list-group-item small text-muted">Aucun contrat sur ce projet.</li><?php endif; ?>
            </ul>
        </div>

        <?php if ($t['type'] === 'organisation'): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lines-fill"></i> Représentants</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($representants as $r): ?>
                <li class="list-group-item small"><?= e($r['nom']) ?>
                    <span class="text-muted">· <?= e(SEXES[$r['sexe']] ?? $r['sexe']) ?> · <?= e(TRANCHES_AGE[$r['tranche_age']] ?? $r['tranche_age']) ?></span></li>
                <?php endforeach; ?>
                <?php if (!$representants): ?><li class="list-group-item small text-muted">Aucun représentant inscrit sur ce projet.</li><?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
