<?php
declare(strict_types=1);

/**
 * Tiers - registre des beneficiaires du projet courant.
 *
 * Le registre accepte deux formes (CDC 3.2) : la personne rattachee a une
 * organisation, et la personne qui ne represente qu'elle-meme. KesKle forme les
 * representants de douze organisations, Koule Ki Pale quinze a vingt jeunes inscrits
 * en leur nom propre. Le rattachement a une organisation est donc facultatif.
 *
 * Le sexe et la tranche d'age ne sont pas des donnees facultatives : le modele de
 * rapport d'activites exige les beneficiaires ventiles par sexe et par jeunesse, et
 * la section des questions transversales attend un rendu sur l'egalite de genre. La
 * collecte doit etre annoncee aux personnes concernees.
 *
 * L'inscription d'un beneficiaire mineur exige le televersement d'une autorisation
 * parentale, qui devient une piece du dossier au meme titre qu'une feuille de
 * presence. Aucun des deux projets ne prevoit de participants mineurs, mais la
 * regle evite d'avoir a decider dans l'urgence si le cas se presente. La piece va
 * au coffre : elle porte le nom d'un mineur.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/tiers.php';
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

    require_phase_execution('Tenir le registre des bénéficiaires');

    if ($action === 'inscrire') {
        $res = beneficiaire_inscrire([
            'organisation_id' => (int)($_POST['organisation_id'] ?? 0) ?: null,
            'nom'             => (string)($_POST['nom'] ?? ''),
            'fonction'        => (string)($_POST['fonction'] ?? ''),
            'sexe'            => (string)($_POST['sexe'] ?? ''),
            'tranche_age'     => (string)($_POST['tranche_age'] ?? ''),
            'telephone'       => (string)($_POST['telephone'] ?? ''),
        ], $_FILES['autorisation'] ?? null);
        if (!$res['success']) {
            $erreur = $res['error'];
        } else {
            flash_set('success', trim((string)($_POST['nom'] ?? '')) . ' inscrit au registre.');
            redirect(base_path('modules/tiers/beneficiaires.php'));
        }
    } elseif ($action === 'actif') {
        $bid = (int)($_POST['id'] ?? 0);
        $v   = (int)($_POST['valeur'] ?? 0) === 1 ? 1 : 0;
        db()->prepare('UPDATE beneficiaires SET actif = ? WHERE id = ? AND projet_id = ?')->execute([$v, $bid, projet_id()]);
        audit('tiers', $v ? 'beneficiaire_reactive' : 'beneficiaire_retire', 'beneficiaire', $bid);
        redirect(base_path('modules/tiers/beneficiaires.php'));
    }
}

$sb = db()->prepare(
    'SELECT b.*, o.nom AS organisation, o.sigle, o.commune, o.domaine
       FROM beneficiaires b LEFT JOIN tiers o ON o.id = b.organisation_id
      WHERE b.projet_id = ? ORDER BY o.nom IS NULL, o.nom, b.nom'
);
$sb->execute([projet_id()]);
$beneficiaires = $sb->fetchAll();

$organisations = db()->query("SELECT id, nom, sigle, commune, domaine FROM tiers WHERE type = 'organisation' AND actif = 1 ORDER BY nom")->fetchAll();

// Les deux indicateurs suivis sans etre bloquants (CDC 3.2) : la cible de cinquante
// pour cent d'organisations oeuvrant dans l'egalite de genre et les droits humains,
// et la repartition entre les communes.
$parSexe = $parTranche = $parCommune = [];
foreach ($beneficiaires as $b) {
    if (!$b['actif']) {
        continue;
    }
    $parSexe[$b['sexe']] = ($parSexe[$b['sexe']] ?? 0) + 1;
    $parTranche[$b['tranche_age']] = ($parTranche[$b['tranche_age']] ?? 0) + 1;
    $c = $b['commune'] ?? 'Sans organisation';
    $parCommune[$c] = ($parCommune[$c] ?? 0) + 1;
}
$orgsProjet = array_unique(array_filter(array_column($beneficiaires, 'organisation_id')));
$orgsGenre = 0;
foreach ($organisations as $o) {
    if (in_array((int)$o['id'], array_map('intval', $orgsProjet), true)
        && preg_match('/genre|droits? humains?|femme|égalit|equit/i', (string)$o['domaine'])) {
        $orgsGenre++;
    }
}
$nbOrgs = count($orgsProjet);

$ongletActif = 'beneficiaires';
page_start('Bénéficiaires', 'tiers');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Registre des bénéficiaires</h1>
    <span class="text-muted small"><?= count($beneficiaires) ?> inscrits · <?= $nbOrgs ?> organisation(s)</span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Ventilation par sexe</div>
        <div class="valeur fs-5"><?= (int)($parSexe['F'] ?? 0) ?> F · <?= (int)($parSexe['M'] ?? 0) ?> H</div>
        <small class="text-muted">exigée par le modèle de rapport</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Jeunes (18-24)</div>
        <div class="valeur fs-5"><?= (int)($parTranche['18_24'] ?? 0) ?></div>
        <small class="text-muted">questions transversales du rapport</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Organisations genre et droits humains</div>
        <div class="valeur fs-5"><?= $nbOrgs > 0 ? number_format($orgsGenre / $nbOrgs * 100, 0) . ' %' : '—' ?></div>
        <small class="text-muted">cible 50 %, suivie sans blocage</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Communes</div>
        <div class="valeur fs-5"><?= count($parCommune) ?></div>
        <small class="text-muted"><?= e(implode(' · ', array_map(fn($c, $n) => $c . ' ' . $n, array_keys($parCommune), $parCommune))) ?></small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-<?= $peutEcrire ? '7' : '12' ?>">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lines-fill"></i> Inscrits</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <?php $orgCourante = '__'; foreach ($beneficiaires as $b):
                    $org = $b['organisation'] ?? 'Sans organisation';
                    if ($org !== $orgCourante): $orgCourante = $org; ?>
                    <tr class="fw-semibold"><td colspan="4">
                        <?php if ($b['organisation_id']): ?>
                        <a href="<?= e(base_path('modules/tiers/fiche.php?id=' . (int)$b['organisation_id'])) ?>"><?= e($org) ?></a>
                        <?php else: ?><?= e($org) ?><?php endif; ?>
                        <?php if ($b['commune']): ?><span class="text-muted small fw-normal">· <?= e($b['commune']) ?></span><?php endif; ?>
                    </td></tr>
                    <?php endif; ?>
                    <tr class="<?= $b['actif'] ? '' : 'opacity-50' ?>">
                        <td style="padding-left:1.5rem"><?= e($b['nom']) ?>
                            <?php if ($b['fonction']): ?><span class="text-muted small">· <?= e($b['fonction']) ?></span><?php endif; ?></td>
                        <td class="small text-muted"><?= e(SEXES[$b['sexe']] ?? $b['sexe']) ?></td>
                        <td class="small text-muted"><?= e(TRANCHES_AGE[$b['tranche_age']] ?? $b['tranche_age']) ?>
                            <?php if ($b['tranche_age'] === 'moins_18'): ?>
                                <?= $b['autorisation_parentale_fichier_id']
                                    ? '<i class="bi bi-paperclip" title="Autorisation parentale au coffre"></i>'
                                    : '<span class="badge text-bg-light border">autorisation manquante</span>' ?>
                            <?php endif; ?></td>
                        <td class="text-end">
                            <?php if ($peutEcrire): ?>
                            <form method="post" class="d-inline"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="actif">
                                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                <input type="hidden" name="valeur" value="<?= $b['actif'] ? 0 : 1 ?>">
                                <button class="btn btn-sm btn-link text-muted p-0"><?= $b['actif'] ? 'retirer' : 'réinscrire' ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$beneficiaires): ?><tr><td class="text-muted p-3">Aucun bénéficiaire inscrit.</td></tr><?php endif; ?>
            </table>
            </div>
        </div>
    </div>

    <?php if ($peutEcrire): ?>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Inscrire</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="inscrire">
                    <div class="mb-2">
                        <label class="form-label small mb-1">Organisation <span class="text-muted">(facultative)</span></label>
                        <select class="form-select form-select-sm" name="organisation_id">
                            <option value="">Aucune — inscrit en son nom propre</option>
                            <?php foreach ($organisations as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"><?= e($o['nom']) ?><?= $o['commune'] ? ' — ' . e($o['commune']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Nom</label>
                        <input class="form-control form-control-sm" name="nom" required maxlength="150">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Fonction dans l'organisation</label>
                        <input class="form-control form-control-sm" name="fonction" maxlength="120">
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Sexe</label>
                            <select class="form-select form-select-sm" name="sexe" required>
                                <option value="">—</option>
                                <?php foreach (SEXES as $k => $lib): ?><option value="<?= e($k) ?>"><?= e($lib) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-1">Tranche d'âge</label>
                            <select class="form-select form-select-sm" name="tranche_age" required>
                                <option value="">—</option>
                                <?php foreach (TRANCHES_AGE as $k => $lib): ?><option value="<?= e($k) ?>"><?= e($lib) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-2">
                            <label class="form-label small mb-1">Téléphone</label>
                            <input class="form-control form-control-sm" name="telephone" maxlength="40">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label small mb-1">Autorisation parentale</label>
                            <input type="file" class="form-control form-control-sm" name="autorisation" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="form-text">Exigée si la tranche d'âge est « Moins de 18 ans ». Versée au coffre.</div>
                        </div>
                    </div>
                    <p class="form-text mb-2">Le sexe et la tranche d'âge alimentent le rapport d'activités.
                        La collecte doit être annoncée aux personnes concernées.</p>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Inscrire</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php page_end();
