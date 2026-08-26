<?php
declare(strict_types=1);

/** Noyau - parametrage (annexe F) et calendrier relatif. Coordinateur seulement. */

require_once __DIR__ . '/../../includes/layout.php';
require_role(['coordinateur']);
require_module('noyau');

$cleEdit = (string)($_GET['cle'] ?? '');
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cle    = (string)($_POST['cle'] ?? '');
    $valeur = trim((string)($_POST['valeur'] ?? ''));
    $motif  = trim((string)($_POST['motif'] ?? ''));
    $effet  = trim((string)($_POST['date_effet'] ?? '')) ?: date('Y-m-d');
    $def    = PARAMETRES_REGISTRE[$cle] ?? null;

    if ($def === null) {
        $erreur = 'Paramètre inconnu.';
    } elseif ($motif === '') {
        $erreur = 'Le motif est obligatoire : chaque version est historisée avec son auteur et sa raison.';
    } elseif (($err = valider_param($cle, $valeur)) !== null) {
        $erreur = $err;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effet)) {
        $erreur = 'Date d\'effet invalide.';
    } else {
        if ($def[1] === 'decimal' && $valeur !== '') {
            $valeur = number_format((float)str_replace(',', '.', $valeur), 2, '.', '');
        }
        param_set($cle, $valeur === '' ? null : $valeur, $motif, $effet);
        if (in_array($cle, ['date_debut_execution', 'duree_execution_mois'], true)) {
            // La date de debut vient de changer : le calendrier est reconstruit (periodes) si rien n'est fige.
            $ok = generer_periodes();
            flash_set($ok ? 'success' : 'warning', $ok ? 'Paramètre enregistré et périodes régénérées.' : 'Paramètre enregistré, mais les périodes n\'ont pas pu être régénérées (période figée ou écritures existantes).');
        } else {
            flash_set('success', 'Paramètre enregistré (nouvelle version historisée).');
        }
        redirect(base_path('modules/noyau/'));
    }
    $cleEdit = $cle;
}

$enVigueur = [];
foreach (PARAMETRES_REGISTRE as $cle => $def) {
    $hist = param_historique($cle);
    $enVigueur[$cle] = $hist[0] ?? null;
}
$verrou = calendrier_verrouille();
$periodesListe = periodes();

page_start('Paramétrage', 'noyau');
$ongletActif = 'parametres';
require __DIR__ . '/_nav.php';
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Paramètres (annexe F)</span>
                <small class="text-muted fw-normal">Chaque modification crée une version datée ; les dossiers antérieurs ne sont pas affectés.</small>
            </div>
            <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Paramètre</th><th>Valeur en vigueur</th><th>Depuis</th><th></th></tr></thead>
                <tbody>
                <?php foreach (PARAMETRES_REGISTRE as $cle => [$lib, $type, $options, $modif]):
                    $v = param($cle);
                    $affiche = $v === null ? null : ($type === 'choix' ? ($options[$v] ?? $v) : ($type === 'decimal' ? htg($v) : ($type === 'date' ? date_fr($v) : $v)));
                    $peut = $modif === true || ($modif === 'avant_ecriture' && !$verrou);
                ?>
                <tr class="<?= $cleEdit === $cle ? 'table-active' : '' ?>">
                    <td><?= e($lib) ?><br><small class="text-muted"><code><?= e($cle) ?></code></small></td>
                    <td><?= $affiche === null ? '<span class="badge badge-a-definir">à définir</span>' : e((string)$affiche) ?></td>
                    <td class="small text-muted"><?= e(date_fr($enVigueur[$cle]['date_effet'] ?? null)) ?></td>
                    <td class="text-end">
                        <?php if ($peut): ?><a class="btn btn-sm btn-outline-primary" href="?cle=<?= e($cle) ?>">Modifier</a>
                        <?php elseif ($modif === false): ?><small class="text-muted">non modifiable</small>
                        <?php else: ?><small class="text-muted">verrouillé (écritures existantes)</small><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($cleEdit !== '' && isset(PARAMETRES_REGISTRE[$cleEdit])):
            [$lib, $type, $options, $modif] = PARAMETRES_REGISTRE[$cleEdit];
            $courant = param($cleEdit); ?>
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold">Modifier : <?= e($lib) ?></div>
            <div class="card-body">
                <?php if ($erreur): ?><div class="alert alert-danger py-2"><?= e($erreur) ?></div><?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="cle" value="<?= e($cleEdit) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nouvelle valeur</label>
                        <?php if ($type === 'choix'): ?>
                            <select name="valeur" class="form-select">
                                <?php foreach ($options as $k => $l): ?><option value="<?= e((string)$k) ?>" <?= (string)$k === (string)$courant ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        <?php elseif ($type === 'date'): ?>
                            <input type="date" name="valeur" class="form-control" value="<?= e($courant ?? '') ?>">
                        <?php elseif ($type === 'int'): ?>
                            <input type="number" name="valeur" class="form-control" min="0" step="1" value="<?= e($courant ?? '') ?>">
                        <?php elseif ($type === 'decimal'): ?>
                            <input type="number" name="valeur" class="form-control" min="0" step="0.01" value="<?= e($courant ?? '') ?>">
                        <?php else: ?>
                            <input type="text" name="valeur" class="form-control" maxlength="255" value="<?= e($courant ?? '') ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date d'effet</label>
                        <input type="date" name="date_effet" class="form-control" value="<?= date('Y-m-d') ?>">
                        <div class="form-text">Ne s'applique qu'aux dossiers créés à partir de cette date.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Motif <span class="text-danger">*</span></label>
                        <input type="text" name="motif" class="form-control" maxlength="255" required>
                    </div>
                    <button class="btn btn-primary">Enregistrer</button>
                    <a class="btn btn-link" href="<?= e(base_path('modules/noyau/')) ?>">Annuler</a>
                </form>
                <?php $hist = param_historique($cleEdit); if (count($hist) > 1): ?>
                <hr>
                <h3 class="h6">Historique</h3>
                <ul class="small mb-0">
                    <?php foreach ($hist as $h): ?>
                    <li><?= e(date_fr($h['date_effet'])) ?> — <?= e($h['valeur'] ?? '(vide)') ?> <span class="text-muted">· <?= e($h['motif'] ?? '') ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-white fw-semibold">Calendrier relatif</div>
            <div class="card-body small">
                <?php if (!date_debut()): ?>
                    <p class="mb-0 text-muted">Saisissez la date de début d'exécution pour dériver la fin, les <?= duree_mois() ?> mois de projet et les périodes de rapport.</p>
                <?php else: ?>
                    <dl class="row mb-2">
                        <dt class="col-6">Début</dt><dd class="col-6"><?= e(date_fr(date_debut())) ?></dd>
                        <dt class="col-6">Fin d'exécution</dt><dd class="col-6"><?= e(date_fr(date_fin())) ?></dd>
                        <dt class="col-6">Rapport intermédiaire</dt><dd class="col-6"><?php $pi = periode_intermediaire(); ?><?= $pi ? e(date_fr($pi['debut'])) . ' → ' . e(date_fr($pi['fin'])) : '—' ?></dd>
                        <dt class="col-6">Seconde borne</dt><dd class="col-6"><?= e(date_fr(seconde_borne())) ?></dd>
                        <dt class="col-6">Phase 2 résiduelle</dt><dd class="col-6"><?= (int)duree_residuelle_phase2() ?> mois</dd>
                    </dl>
                    <?php if ($periodesListe): ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Mois</th><th>Du</th><th>Au</th><th>Statut</th></tr></thead>
                        <tbody>
                        <?php foreach ($periodesListe as $p): ?>
                        <tr><td>M<?= str_pad((string)$p['numero'], 2, '0', STR_PAD_LEFT) ?></td><td><?= e(date_fr($p['date_debut'])) ?></td><td><?= e(date_fr($p['date_fin'])) ?></td><td><?= e($p['statut']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <form method="post" action="<?= e(base_path('modules/noyau/index.php')) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cle" value="duree_execution_mois"><input type="hidden" name="valeur" value="<?= duree_mois() ?>"><input type="hidden" name="motif" value="Génération des périodes">
                        <button class="btn btn-sm btn-outline-primary">Générer les périodes</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php page_end();
