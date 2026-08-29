<?php
declare(strict_types=1);

/**
 * Activites - cadre logique, indicateurs et activites.
 *
 * Le cadre est versionne : chaque releve d'indicateur se rattache a la version en
 * vigueur, ce qui rend le tableau du rapport reproductible a l'identique des annees
 * plus tard. Une version figee avec un rapport transmis ne se modifie plus.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/activites.php';
require_projet();
require_module('activites');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Modifier le cadre logique');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'version') {
        $res = cadre_version_creer((string)($_POST['motif'] ?? ''));
    } elseif ($action === 'element') {
        $res = cadre_element_creer($_POST);
    } elseif ($action === 'indicateur') {
        $res = indicateur_creer($_POST);
    } elseif ($action === 'releve') {
        $res = releve_poser((int)($_POST['indicateur_id'] ?? 0), (string)($_POST['valeur'] ?? ''),
            (string)($_POST['commentaire'] ?? ''));
    } elseif ($action === 'activite') {
        $res = activite_creer($_POST);
    } elseif ($action === 'avancer') {
        $res = activite_avancer((int)($_POST['activite_id'] ?? 0), (string)($_POST['statut'] ?? ''));
    } elseif ($action === 'livrable') {
        $res = activite_livrable((int)($_POST['activite_id'] ?? 0), $_FILES['livrable'] ?? null);
    } elseif ($action === 'difficulte') {
        $res = difficulte_ajouter((int)($_POST['activite_id'] ?? 0), (string)($_POST['description'] ?? ''),
            (string)($_POST['mesure'] ?? ''));
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/activites/index.php'));
    }
}

$version = cadre_version_courante();
$historique = cadre_versions();
$elements = cadre_elements();
$indics = indicateurs();
$listeActivites = activites();
$manquants = livrables_manquants();
$lignes = array_filter(budget_lignes(), fn($l) => $l['nature'] === 'imputable');
$peutEcrire = user_role() === 'coordinateur';

$ongletActif = 'cadre';
page_start('Cadre logique', 'activites');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Cadre logique</h1>
    <span class="text-muted small">
        <?= $version ? 'Version ' . (int)$version['numero'] . ' du ' . e(date_fr($version['date']))
              . ($version['figee'] ? ' · figée' : '') : 'aucune version' ?></span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($version === null): ?>
<div class="alert alert-warning py-2"><i class="bi bi-diagram-3"></i>
    Aucune version du cadre logique n'existe encore. Un relevé d'indicateur s'y rattache toujours :
    il faut donc en ouvrir une avant de mesurer quoi que ce soit.</div>
<?php endif; ?>

<?php if ($manquants): ?>
<div class="alert alert-info py-2"><i class="bi bi-journal-text"></i>
    <strong><?= count($manquants) ?> livrable(s) attendu(s)</strong> et non encore produit(s) :
    <?= e(implode(', ', array_map(fn($m) => $m['code'], $manquants))) ?>.
    Leur état alimente la rubrique des documents produits du rapport final.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3"></i> Objectifs et résultats</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <?php foreach ($elements as $code => $el): ?>
                <tr>
                    <td style="padding-left:<?= $el['niveau'] === 'objectif_general' ? 0 : ($el['niveau'] === 'objectif_specifique' ? 1 : 2) ?>rem">
                        <span class="text-muted small me-2"><?= e($el['code']) ?></span>
                        <?= $el['niveau'] === 'objectif_general' ? '<strong>' . e($el['libelle']) . '</strong>' : e($el['libelle']) ?>
                        <br><small class="text-muted"><?= e(NIVEAUX_CADRE[$el['niveau']]) ?>
                        <?php if ($el['risque']): ?>· risque : <?= e(mb_substr($el['risque'], 0, 70)) ?><?php endif; ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$elements): ?><tr><td class="text-muted p-3">Le cadre logique n'est pas encore saisi.</td></tr><?php endif; ?>
            </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-task"></i> Activités</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr class="small text-muted">
                    <th>Code</th><th>Libellé</th><th>Période</th><th>Livrable</th><th>Statut</th>
                </tr></thead>
                <tbody>
                <?php foreach ($listeActivites as $a): ?>
                <tr>
                    <td><?= e($a['code']) ?>
                        <?php if ($a['categorie'] === 'visibilite'): ?><br><span class="badge text-bg-light border">visibilité</span><?php endif; ?></td>
                    <td class="small"><?= e($a['libelle']) ?>
                        <?php if ($a['element_code']): ?><br><span class="text-muted">→ <?= e($a['element_code']) ?></span><?php endif; ?>
                        <?php if ($a['ligne_code']): ?><span class="text-muted"> · ligne <?= e($a['ligne_code']) ?></span><?php endif; ?></td>
                    <td class="small text-muted"><?php if ($a['mois_debut']): ?>M<?= (int)$a['mois_debut'] ?>
                        <?php if ($a['mois_fin']): ?> → M<?= (int)$a['mois_fin'] ?><?php endif; ?><?php endif; ?></td>
                    <td class="small"><?php if ($a['livrable_attendu']): ?>
                        <?= $a['livrable_fichier_id'] ? '<span class="text-success"><i class="bi bi-check2"></i></span> ' : '' ?>
                        <span class="text-muted"><?= e(mb_substr($a['livrable_attendu'], 0, 34)) ?></span>
                        <?php endif; ?></td>
                    <td>
                        <?php if ($peutEcrire): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="avancer">
                            <input type="hidden" name="activite_id" value="<?= (int)$a['id'] ?>">
                            <select class="form-select form-select-sm" name="statut" onchange="this.form.submit()">
                                <?php foreach (STATUTS_ACTIVITE as $k => $lib): ?>
                                <option value="<?= e($k) ?>" <?= $a['statut'] === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php else: ?><span class="badge text-bg-light border"><?= e(STATUTS_ACTIVITE[$a['statut']]) ?></span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$listeActivites): ?><tr><td colspan="5" class="text-muted p-3">Aucune activité.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($historique): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history"></i>
                Versions du cadre logique</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($historique as $h): ?>
                <li class="list-group-item small">
                    <b>Version <?= (int)$h['numero'] ?></b> · <?= e(date_fr($h['date'])) ?>
                    <?php if ($h['figee']): ?><span class="badge text-bg-light border">figée avec un rapport</span><?php endif; ?>
                    <br><span class="text-muted"><?= e($h['motif']) ?> — <?= e($h['auteur']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up"></i> Indicateurs</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <?php foreach ($indics as $i): $ech = echeance_indicateur($i['echeance_mois'] === null ? null : (int)$i['echeance_mois']); ?>
                <tr>
                    <td class="small">
                        <span class="text-muted"><?= e($i['element_code']) ?></span> <?= e($i['libelle']) ?>
                        <br><span class="text-muted">
                            <?php if ($i['cible_valeur']): ?>cible <?= e($i['cible_valeur']) ?><?php endif; ?>
                            <?php if ($i['echeance_mois']): ?> · M<?= (int)$i['echeance_mois'] ?>
                                <?= $ech['date'] ? '(' . e(date_fr($ech['date'])) . ')' : '' ?>
                                <?php if ($ech['apres_cloture']): ?>
                                <span class="badge text-bg-light border">après clôture — enquête d'adoption</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                        <?php $rel = releves_indicateur((int)$i['id']); if ($rel): ?>
                        <br><span class="text-success">atteint : <?= e($rel[0]['valeur_atteinte']) ?>
                            <span class="text-muted">(version <?= (int)$rel[0]['version_numero'] ?>,
                            <?= e(date_fr($rel[0]['date'])) ?>)</span></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end" style="width:9rem">
                        <?php if ($peutEcrire && $version !== null): ?>
                        <form method="post"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="releve">
                            <input type="hidden" name="indicateur_id" value="<?= (int)$i['id'] ?>">
                            <div class="input-group input-group-sm">
                                <input class="form-control" name="valeur" placeholder="relevé" maxlength="60">
                                <button class="btn btn-outline-secondary">→</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$indics): ?><tr><td class="text-muted p-3">Aucun indicateur.</td></tr><?php endif; ?>
            </table>
            </div>
        </div>

        <?php if ($peutEcrire): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Saisir</div>
            <div class="card-body">
                <form method="post" class="row g-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="version">
                    <div class="col-8">
                        <label class="form-label small mb-1">Nouvelle version du cadre — motif</label>
                        <input class="form-control form-control-sm" name="motif" maxlength="255" required>
                    </div>
                    <div class="col-4 d-flex align-items-end"><button class="btn btn-outline-secondary btn-sm w-100">Ouvrir</button></div>
                </form>
                <hr>
                <form method="post" class="row g-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="element">
                    <div class="col-4">
                        <label class="form-label small mb-1">Code</label>
                        <input class="form-control form-control-sm" name="code" maxlength="10" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label small mb-1">Niveau</label>
                        <select class="form-select form-select-sm" name="niveau">
                            <?php foreach (NIVEAUX_CADRE as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Rattaché à</label>
                        <select class="form-select form-select-sm" name="parent_id">
                            <option value="">— (objectif général seulement)</option>
                            <?php foreach ($elements as $el): ?>
                            <option value="<?= (int)$el['id'] ?>"><?= e($el['code'] . ' — ' . mb_substr($el['libelle'], 0, 44)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Libellé</label>
                        <textarea class="form-control form-control-sm" name="libelle" rows="2" required></textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Ajouter au cadre</button></div>
                </form>
                <hr>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activite">
                    <div class="col-4">
                        <label class="form-label small mb-1">Code</label>
                        <input class="form-control form-control-sm" name="code" maxlength="10" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label small mb-1">Catégorie</label>
                        <select class="form-select form-select-sm" name="categorie">
                            <?php foreach (CATEGORIES_ACTIVITE as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Résultat — vide pour une activité de visibilité</label>
                        <select class="form-select form-select-sm" name="element_id">
                            <option value="">—</option>
                            <?php foreach ($elements as $el): if ($el['niveau'] !== 'resultat') continue; ?>
                            <option value="<?= (int)$el['id'] ?>"><?= e($el['code'] . ' — ' . mb_substr($el['libelle'], 0, 40)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Ligne budgétaire</label>
                        <select class="form-select form-select-sm" name="ligne_id">
                            <option value="">—</option>
                            <?php foreach ($lignes as $l): ?>
                            <option value="<?= (int)$l['id'] ?>"><?= e($l['code'] . ' — ' . mb_substr($l['libelle'], 0, 36)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Mois début</label>
                        <input type="number" class="form-control form-control-sm" name="mois_debut" min="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Mois fin</label>
                        <input type="number" class="form-control form-control-sm" name="mois_fin" min="1">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Libellé</label>
                        <input class="form-control form-control-sm" name="libelle" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Livrable attendu</label>
                        <input class="form-control form-control-sm" name="livrable_attendu" maxlength="255">
                    </div>
                    <div class="col-12"><button class="btn btn-outline-secondary btn-sm">Ajouter l'activité</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
