<?php
declare(strict_types=1);

/**
 * Activites - sessions de formation, presences et evaluations.
 *
 * La feuille de presence et les fiches d'evaluation sont etablies sur papier,
 * signees a la main, puis numerisees : elles constituent les pieces probantes, la
 * table des participations n'etant que la donnee saisie qui en derive. Le dossier
 * de session ne se clot qu'au retour des deux flux (CDC 3.3).
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/activites.php';
require_role(['coordinateur', 'raf']);
require_module('activites');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Tenir une session de formation');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'session') {
        $res = session_creer($_POST);
    } elseif ($action === 'feuille') {
        $res = session_feuille_presence((int)($_POST['session_id'] ?? 0), $_FILES['feuille'] ?? null);
    } elseif ($action === 'participation') {
        $res = participation_saisir((int)($_POST['session_id'] ?? 0), (int)($_POST['beneficiaire_id'] ?? 0),
            (string)($_POST['jour'] ?? date('Y-m-d')), !empty($_POST['present']),
            (string)($_POST['resultat'] ?? '') ?: null, $_FILES['fiche'] ?? null);
    } elseif ($action === 'clore') {
        $res = session_clore((int)($_POST['session_id'] ?? 0));
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/activites/sessions.php'));
    }
}

$liste = sessions();
$reussite = taux_reussite();
$coherences = coherence_lignes_physiques();
$listeActivites = activites();
$formateurs = db()->query("SELECT id, nom FROM tiers WHERE type = 'personne' AND actif = 1 ORDER BY nom")->fetchAll();
$sb = db()->prepare('SELECT id, nom FROM beneficiaires WHERE projet_id = ? AND actif = 1 ORDER BY nom');
$sb->execute([projet_id()]);
$beneficiaires = $sb->fetchAll();
$sessionOuverte = (int)($_GET['session'] ?? 0);

$ongletActif = 'sessions';
page_start('Sessions de formation', 'activites');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-3">Sessions de formation</h1>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Taux de réussite</div>
        <div class="valeur fs-5"><?= $reussite['taux'] === null ? '—' : number_format($reussite['taux'], 1, ',', ' ') . ' %' ?></div>
        <small class="text-muted"><?= (int)$reussite['reussites'] ?> réussites sur <?= (int)$reussite['evalues'] ?> évaluées
            · cible 80 % au résultat 2.2</small>
    </div></div></div>
    <?php foreach ($coherences as $c): ?>
    <div class="col-6 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Cohérence physique · <?= e(mb_substr($c['ligne'], 0, 28)) ?></div>
        <div class="valeur fs-5"><?= abs($c['ecart']) < 0.01 ? 'conforme' : (($c['ecart'] > 0 ? '+' : '') . rtrim(rtrim(number_format($c['ecart'], 2, ',', ' '), '0'), ',')) ?></div>
        <small class="text-muted"><?= e($c['detail']) ?> · <?= rtrim(rtrim(number_format($c['impute'], 2, ',', ' '), '0'), ',') ?> imputée(s)</small>
    </div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-people"></i> Sessions</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr class="small text-muted">
                    <th>N°</th><th>Dates et lieu</th><th>Formateur</th><th>Présents</th><th>Pièces</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($liste as $s): $coh = session_coherence((int)$s['id']); ?>
                <tr>
                    <td><?= (int)$s['numero'] ?><br><span class="badge text-bg-light border"><?= e($s['statut']) ?></span></td>
                    <td class="small"><?= e(date_fr($s['date_debut'])) ?> → <?= e(date_fr($s['date_fin'])) ?>
                        <br><span class="text-muted"><?= e($s['lieu']) ?> · <?= e($s['activite_code']) ?></span></td>
                    <td class="small"><?= e($s['formateur']) ?></td>
                    <td class="small"><?= (int)$s['presents'] ?>
                        <?php if ((int)$s['fiches_manquantes'] > 0): ?>
                        <br><span class="text-danger"><?= (int)$s['fiches_manquantes'] ?> sans fiche</span><?php endif; ?></td>
                    <td class="small">
                        <?php if ($s['feuille_presence_fichier_id']): ?>
                        <a href="<?= e(base_path('pdf/serve.php?id=' . (int)$s['feuille_presence_fichier_id'])) ?>">feuille</a>
                        <?php else: ?><span class="text-muted">feuille attendue</span><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-link p-0" href="?session=<?= (int)$s['id'] ?>">saisir</a>
                        <?php if ($s['statut'] !== 'close'): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="clore">
                            <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm btn-link p-0" <?= $coh['ok'] ? '' : 'disabled title="' . e(implode(' ', $coh['motifs'])) . '"' ?>>clore</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$liste): ?><tr><td colspan="6" class="text-muted p-3">Aucune session.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($sessionOuverte > 0 && ($s = session($sessionOuverte)) !== null): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-square"></i>
                Présences et évaluations de la session <?= (int)$s['numero'] ?></div>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr class="small text-muted"><th>Jour</th><th>Bénéficiaire</th><th>Présent</th><th>Résultat</th><th>Fiche</th></tr></thead>
                <?php foreach (participations_session($sessionOuverte) as $p): ?>
                <tr>
                    <td class="small"><?= e(date_fr($p['jour'])) ?></td>
                    <td class="small"><?= e($p['nom']) ?>
                        <?php if ($p['organisation']): ?><br><span class="text-muted"><?= e($p['organisation']) ?></span><?php endif; ?></td>
                    <td class="small"><?= $p['present'] ? 'oui' : 'non' ?></td>
                    <td class="small"><?= e($p['resultat'] ?? '') ?></td>
                    <td class="small"><?= $p['fiche_evaluation_fichier_id']
                        ? '<a href="' . e(base_path('pdf/serve.php?id=' . (int)$p['fiche_evaluation_fichier_id'])) . '">fiche</a>'
                        : '<span class="text-muted">attendue</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="participation">
                    <input type="hidden" name="session_id" value="<?= $sessionOuverte ?>">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Jour</label>
                        <input type="date" class="form-control form-control-sm" name="jour"
                               min="<?= e($s['date_debut']) ?>" max="<?= e($s['date_fin']) ?>"
                               value="<?= e($s['date_debut']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Bénéficiaire</label>
                        <select class="form-select form-select-sm" name="beneficiaire_id" required>
                            <option value="">—</option>
                            <?php foreach ($beneficiaires as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Résultat</label>
                        <select class="form-select form-select-sm" name="resultat">
                            <option value="">—</option>
                            <option value="reussite">Réussite</option>
                            <option value="echec">Échec</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Fiche d'évaluation</label>
                        <input type="file" class="form-control form-control-sm" name="fiche" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="present" id="pr" value="1" checked>
                            <label class="form-check-label small" for="pr">Présent</label>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Nouvelle session</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="session">
                    <div class="col-4">
                        <label class="form-label small mb-1">Numéro</label>
                        <input type="number" class="form-control form-control-sm" name="numero" min="1" value="1" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label small mb-1">Activité réalisée</label>
                        <select class="form-select form-select-sm" name="activite_id" required>
                            <option value="">—</option>
                            <?php foreach ($listeActivites as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' — ' . mb_substr($a['libelle'], 0, 36)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Du</label>
                        <input type="date" class="form-control form-control-sm" name="date_debut" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Au</label>
                        <input type="date" class="form-control form-control-sm" name="date_fin" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Lieu</label>
                        <input class="form-control form-control-sm" name="lieu" required maxlength="150">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Formateur</label>
                        <select class="form-select form-select-sm" name="formateur_id" required>
                            <option value="">—</option>
                            <?php foreach ($formateurs as $f): ?>
                            <option value="<?= (int)$f['id'] ?>"><?= e($f['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><button class="btn btn-primary btn-sm">Créer la session</button></div>
                </form>
            </div>
        </div>

        <?php if ($sessionOuverte > 0): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-upload"></i> Feuille de présence</div>
            <div class="card-body">
                <p class="small text-muted">Établie sur papier, signée à la main, puis numérisée. C'est la pièce
                    probante : la saisie des présences n'en est que la dérivée.</p>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="feuille">
                    <input type="hidden" name="session_id" value="<?= $sessionOuverte ?>">
                    <input type="file" class="form-control form-control-sm mb-2" name="feuille" accept=".pdf,.jpg,.jpeg,.png" required>
                    <button class="btn btn-outline-secondary btn-sm">Verser</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
