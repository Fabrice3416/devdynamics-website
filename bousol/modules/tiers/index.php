<?php
declare(strict_types=1);

/**
 * Tiers - referentiel des fournisseurs, prestataires, organisations et administrations.
 *
 * Une table unique couvre les quatre, distingues par leur type (CDC 8.2). Le
 * referentiel est partage entre les projets : une meme personne intervient sur
 * plusieurs projets sans changer d'identite, et un fournisseur ne se ressaisit pas
 * projet par projet.
 *
 * Le NIF est unique dans le referentiel. Un meme tiers saisi deux fois casserait la
 * detection des doublons comme l'etat recapitulatif des acomptes : la regle est tenue
 * en base par trg_tiers_nif_insert / trg_tiers_nif_update, l'application ne faisant
 * que rendre le refus lisible.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_projet();
require_module('tiers');

$peutEcrire = in_array(user_role(), ['coordinateur', 'raf'], true);
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutEcrire) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'creer') {
        $type   = (string)($_POST['type'] ?? '');
        $nom    = trim((string)($_POST['nom'] ?? ''));
        $nif    = trim((string)($_POST['nif'] ?? ''));
        $sigle  = trim((string)($_POST['sigle'] ?? ''));
        $commune = trim((string)($_POST['commune'] ?? ''));
        $domaine = trim((string)($_POST['domaine'] ?? ''));
        $fonction = trim((string)($_POST['fonction'] ?? ''));
        $patente  = trim((string)($_POST['patente'] ?? ''));
        $telephone = trim((string)($_POST['telephone'] ?? ''));
        $email     = trim((string)($_POST['email'] ?? ''));
        $adresse   = trim((string)($_POST['adresse'] ?? ''));
        $coordonnees = trim((string)($_POST['coordonnees_reglement'] ?? ''));
        $benefPaiesc = !empty($_POST['beneficiaire_paiesc']) ? 1 : 0;

        if (!array_key_exists($type, TYPES_TIERS) || $nom === '') {
            $erreur = 'Type et nom sont obligatoires.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'Adresse électronique invalide.';
        } else {
            // Verification prealable, pour un message clair ; le garde-fou reste en base.
            if ($nif !== '') {
                $st = db()->prepare('SELECT nom FROM tiers WHERE nif = ?');
                $st->execute([$nif]);
                if ($doublon = $st->fetchColumn()) {
                    $erreur = 'NIF déjà enregistré au référentiel, sous « ' . $doublon . ' ».';
                }
            }
            if ($erreur === null) {
                try {
                    db()->prepare(
                        'INSERT INTO tiers (type, nom, sigle, fonction, nif, patente, commune, domaine,
                                            beneficiaire_paiesc, telephone, email, adresse, coordonnees_reglement)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([$type, $nom, $sigle ?: null, $fonction ?: null, $nif ?: null, $patente ?: null,
                                $commune ?: null, $domaine ?: null, $benefPaiesc, $telephone ?: null,
                                $email ?: null, $adresse ?: null, $coordonnees ?: null]);
                    $id = (int)db()->lastInsertId();
                    audit('tiers', 'tiers_cree', 'tiers', $id, TYPES_TIERS[$type] . ' · ' . $nom . ($nif !== '' ? ' · NIF ' . $nif : ''));
                    flash_set('success', $nom . ' ajouté au référentiel.');
                    redirect(base_path('modules/tiers/index.php?type=' . urlencode($type)));
                } catch (Throwable $ex) {
                    $erreur = str_contains($ex->getMessage(), 'NIF deja enregistre')
                        ? 'NIF déjà enregistré au référentiel des tiers.'
                        : 'Création impossible.';
                    if ($erreur === 'Création impossible.') {
                        error_log('creer tiers: ' . $ex->getMessage());
                    }
                }
            }
        }
    } elseif ($action === 'actif') {
        $id = (int)($_POST['id'] ?? 0);
        $v  = (int)($_POST['valeur'] ?? 0) === 1 ? 1 : 0;
        db()->prepare('UPDATE tiers SET actif = ? WHERE id = ?')->execute([$v, $id]);
        audit('tiers', $v ? 'tiers_reactive' : 'tiers_desactive', 'tiers', $id);
        flash_set('success', $v ? 'Tiers réactivé.' : 'Tiers désactivé.');
        redirect(base_path('modules/tiers/index.php'));
    }
}

$filtre = (string)($_GET['type'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$sql  = 'SELECT * FROM tiers WHERE 1=1';
$args = [];
if (array_key_exists($filtre, TYPES_TIERS)) {
    $sql .= ' AND type = ?';
    $args[] = $filtre;
}
if ($q !== '') {
    $sql .= ' AND (nom LIKE ? OR sigle LIKE ? OR nif LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
$sql .= ' ORDER BY type, nom';
$st = db()->prepare($sql);
$st->execute($args);
$tiers = $st->fetchAll();

$comptes = db()->query('SELECT type, COUNT(*) n FROM tiers GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);

$ongletActif = 'referentiel';
page_start('Référentiel des tiers', 'tiers');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Référentiel des tiers</h1>
    <span class="text-muted small">Partagé entre tous les projets</span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-auto">
        <label class="form-label small mb-1">Type</label>
        <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
            <option value="">Tous (<?= array_sum($comptes) ?>)</option>
            <?php foreach (TYPES_TIERS as $k => $lib): ?>
            <option value="<?= e($k) ?>" <?= $filtre === $k ? 'selected' : '' ?>><?= e($lib) ?> (<?= (int)($comptes[$k] ?? 0) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-1">Nom, sigle ou NIF</label>
        <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>">
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></div>
</form>

<div class="row g-3">
    <div class="col-lg-<?= $peutEcrire ? '7' : '12' ?>">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-people"></i> <?= count($tiers) ?> tiers</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <?php foreach ($tiers as $t): ?>
                <tr class="<?= $t['actif'] ? '' : 'opacity-50' ?>">
                    <td>
                        <a href="<?= e(base_path('modules/tiers/fiche.php?id=' . (int)$t['id'])) ?>"><?= e($t['nom']) ?></a>
                        <?php if ($t['sigle']): ?><span class="text-muted small">· <?= e($t['sigle']) ?></span><?php endif; ?>
                        <?php if ($t['est_mandataire']): ?><span class="badge text-bg-light border">mandataire</span><?php endif; ?>
                        <?php if ($t['beneficiaire_paiesc']): ?><span class="badge text-bg-light border">bénéficiaire bailleur</span><?php endif; ?>
                        <?php if ($t['fonction'] || $t['domaine']): ?>
                        <br><small class="text-muted"><?= e($t['fonction'] ?: $t['domaine']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e(TYPES_TIERS[$t['type']] ?? $t['type']) ?></td>
                    <td class="text-muted small"><?= e($t['nif'] ?? '') ?></td>
                    <td class="text-muted small"><?= e($t['commune'] ?? '') ?></td>
                    <?php if ($peutEcrire): ?>
                    <td class="text-end">
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="actif">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <input type="hidden" name="valeur" value="<?= $t['actif'] ? 0 : 1 ?>">
                            <button class="btn btn-sm btn-link text-muted p-0"><?= $t['actif'] ? 'désactiver' : 'réactiver' ?></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$tiers): ?><tr><td class="text-muted p-3">Aucun tiers ne correspond.</td></tr><?php endif; ?>
            </table>
            </div>
        </div>
    </div>

    <?php if ($peutEcrire): ?>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Nouveau tiers</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="creer">
                    <div class="mb-2">
                        <label class="form-label small mb-1">Type</label>
                        <select class="form-select form-select-sm" name="type" required>
                            <?php foreach (TYPES_TIERS as $k => $lib): ?>
                            <option value="<?= e($k) ?>" <?= $filtre === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Nom ou raison sociale</label>
                        <input class="form-control form-control-sm" name="nom" required maxlength="150">
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Sigle</label>
                            <input class="form-control form-control-sm" name="sigle" maxlength="30">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">NIF</label>
                            <input class="form-control form-control-sm" name="nif" maxlength="30">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Patente</label>
                            <input class="form-control form-control-sm" name="patente" maxlength="50">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Commune</label>
                            <input class="form-control form-control-sm" name="commune" maxlength="80">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Fonction contractuelle <span class="text-muted">(personne)</span></label>
                            <input class="form-control form-control-sm" name="fonction" maxlength="120">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Domaine d'intervention <span class="text-muted">(organisation)</span></label>
                            <input class="form-control form-control-sm" name="domaine" maxlength="150">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Téléphone</label>
                            <input class="form-control form-control-sm" name="telephone" maxlength="40">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Courriel</label>
                            <input class="form-control form-control-sm" name="email" maxlength="120">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Adresse</label>
                            <input class="form-control form-control-sm" name="adresse" maxlength="255">
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Coordonnées de règlement</label>
                            <input class="form-control form-control-sm" name="coordonnees_reglement" maxlength="255">
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="beneficiaire_paiesc" id="bp" value="1">
                        <label class="form-check-label small" for="bp">Déjà bénéficiaire du bailleur</label>
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php page_end();
