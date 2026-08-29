<?php
declare(strict_types=1);

/**
 * Remuneration - rapports d'execution, certificats et prestations.
 *
 * La sequence est contrainte : rapport, certificat, prestation, reglement,
 * versement a la DGI, puis seulement cloture (CDC 4.4). L'ecran suit cet ordre et
 * n'offre chaque geste qu'au role qui en repond.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/remuneration.php';
require_projet();
require_module('remuneration');

$erreur = null;
$mois = (int)($_GET['mois'] ?? mois_projet() ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Agir sur une rémunération');
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'rapport') {
        $res = rapport_verser((int)($_POST['contrat_id'] ?? 0), (int)($_POST['mois'] ?? 0),
            (string)($_POST['date_remise'] ?? date('Y-m-d')), $_FILES['rapport'] ?? null);
    } elseif ($action === 'accepter') {
        $res = rapport_accepter((int)($_POST['rapport_id'] ?? 0));
    } elseif ($action === 'refuser') {
        $res = rapport_refuser((int)($_POST['rapport_id'] ?? 0), (string)($_POST['motif'] ?? ''));
    } elseif ($action === 'prestation') {
        $res = prestation_calculer((int)($_POST['rapport_id'] ?? 0),
            ($_POST['quantite'] ?? '') !== '' ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST['quantite']) : null);
    } elseif ($action === 'dossier') {
        $res = prestation_ouvrir_dossier((int)($_POST['prestation_id'] ?? 0));
        if (!empty($res['success'])) {
            flash_set('success', 'Dossier ' . $res['numero'] . ' ouvert et imputé pour le brut. '
                . 'Le règlement portera le net.');
            redirect(base_path('modules/depenses/dossier.php?id=' . (int)$res['dossier_id']));
        }
    } elseif ($action === 'avance') {
        $res = avance_verser((int)($_POST['contrat_id'] ?? 0), (int)($_POST['mois'] ?? 0), $_FILES['entente'] ?? null);
    } elseif ($action === 'ratifier') {
        $res = prestation_ratifier((array)($_POST['prestations'] ?? []), $_FILES['resolution'] ?? []);
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/remuneration/index.php?mois=' . $mois));
    }
}

$liste = rapports($mois);
$nonRatifiees = prestations_non_ratifiees();
$contrats = db()->prepare(
    "SELECT c.*, t.nom AS intervenant, l.code AS ligne_code
       FROM contrats c JOIN tiers t ON t.id = c.tiers_id
       LEFT JOIN lignes_budgetaires l ON l.id = c.ligne_id
      WHERE c.projet_id = ? AND c.statut = 'actif' AND c.type <> 'convention_partenariat'
      ORDER BY t.nom"
);
$contrats->execute([projet_id()]);
$contrats = $contrats->fetchAll();
$avancesOuvertes = param('avances_honoraires', '0') === '1';

$ongletActif = 'rapports';
page_start('Rémunération', 'remuneration');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Rapports d'exécution et prestations</h1>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="form-label small mb-0 text-muted">Mois de projet</label>
        <input type="number" class="form-control form-control-sm" style="width:6rem" name="mois"
               min="1" value="<?= $mois ?>" onchange="this.form.submit()">
    </form>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($nonRatifiees): ?>
<div class="alert alert-warning py-2">
    <i class="bi bi-hourglass-split"></i>
    <strong><?= count($nonRatifiees) ?> prestation(s) provisoire(s)</strong> en attente de la résolution de
    l'Assemblée Générale : <?= e(implode(', ', array_map(fn($p) => $p['intervenant'] . ' M' . $p['mois'], $nonRatifiees))) ?>.
    La clôture finale ne peut aboutir tant qu'une résolution ne couvre pas l'ensemble de la période.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-check"></i>
        Mois <?= $mois ?> · <?= count($liste) ?> rapport(s)</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th>Intervenant</th><th>Remis le</th><th>Autorité</th><th>Statut</th>
            <th class="text-end">Brut / acompte / net</th><th class="text-end">Suite</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $r): ?>
        <tr>
            <td><?= e($r['intervenant']) ?><br><small class="text-muted"><?= e($r['fonction']) ?></small></td>
            <td class="small text-muted"><?= e(date_fr($r['date_remise'])) ?>
                <br>versé le <?= e(date_fr($r['date_versement'])) ?></td>
            <td class="small text-muted"><?= e(AUTORITES_ACCEPTATION[$r['autorite']] ?? $r['autorite']) ?></td>
            <td><span class="badge text-bg-light border"><?= e(STATUTS_RAPPORT[$r['statut']] ?? $r['statut']) ?></span>
                <?php if ($r['ratification'] === 'provisoire'): ?><br><span class="badge text-bg-light border">provisoire</span><?php endif; ?></td>
            <td class="text-end small">
                <?php if ($r['prestation_id']): ?>
                    <?php $p = prestation((int)$r['prestation_id']); ?>
                    <?= e(htg((float)$p['brut'])) ?> / <?= e(htg((float)$p['acompte'])) ?> / <strong><?= e(htg((float)$p['net'])) ?></strong>
                    <br><span class="text-muted">acompte figé à <?= e(rtrim(rtrim(number_format((float)$p['taux_acompte'], 2, ',', ' '), '0'), ',')) ?> %</span>
                <?php endif; ?>
            </td>
            <td class="text-end">
                <?php if ($r['statut'] === 'recu' && user_role() === 'coordinateur'): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="accepter">
                    <input type="hidden" name="rapport_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-primary">Délivrer le certificat</button>
                </form>
                <?php elseif ($r['statut'] === 'accepte' && !$r['prestation_id'] && user_role() === 'raf'): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="prestation">
                    <input type="hidden" name="rapport_id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary">Calculer la prestation</button>
                </form>
                <?php elseif ($r['prestation_id'] && user_role() === 'raf'): ?>
                    <?php $p = prestation((int)$r['prestation_id']); ?>
                    <?php if ($p['dossier_ref'] === null): ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="dossier">
                        <input type="hidden" name="prestation_id" value="<?= (int)$r['prestation_id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary">Ouvrir le dossier</button>
                    </form>
                    <?php else: ?><span class="text-muted small"><?= e($p['dossier_ref']) ?></span><?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="6" class="text-muted p-3">Aucun rapport pour ce mois.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="row g-3">
    <?php if (user_role() === 'raf'): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-upload"></i> Verser un rapport d'exécution</div>
            <div class="card-body">
                <p class="small text-muted">Le rapport est remis hors outil, puis versé au dossier. Sa date de remise
                    est celle de la main propre, distincte de la date de versement.</p>
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rapport">
                    <div class="col-12">
                        <label class="form-label small mb-1">Contrat</label>
                        <select class="form-select form-select-sm" name="contrat_id" required>
                            <option value="">—</option>
                            <?php foreach ($contrats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e($c['intervenant'] . ' — ' . $c['fonction']) ?>
                                <?= $c['ligne_code'] ? '(' . e($c['ligne_code']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Mois</label>
                        <input type="number" class="form-control form-control-sm" name="mois" min="1" value="<?= $mois ?>" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label small mb-1">Date de remise</label>
                        <input type="date" class="form-control form-control-sm" name="date_remise" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Rapport numérisé</label>
                        <input type="file" class="form-control form-control-sm" name="rapport" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <div class="col-12 mt-2"><button class="btn btn-primary btn-sm">Verser au dossier</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-lg-6">
        <?php if ($avancesOuvertes && user_role() === 'raf'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-cash"></i> Avance sur honoraires</div>
            <div class="card-body">
                <p class="small text-muted">Le premier versement s'appuie sur l'entente signée et non sur un
                    certificat. Réservé aux rémunérations non récurrentes : jamais un contrat mensuel.</p>
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="avance">
                    <div class="col-8">
                        <label class="form-label small mb-1">Contrat</label>
                        <select class="form-select form-select-sm" name="contrat_id" required>
                            <option value="">—</option>
                            <?php foreach ($contrats as $c): if (!$c['avance_autorisee']) continue; ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e($c['intervenant']) ?>
                                (<?= e(rtrim(rtrim(number_format((float)$c['part_avance'], 2, ',', ' '), '0'), ',')) ?> %)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Mois</label>
                        <input type="number" class="form-control form-control-sm" name="mois" min="1" value="<?= $mois ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Entente signée</label>
                        <input type="file" class="form-control form-control-sm" name="entente" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <div class="col-12 mt-2"><button class="btn btn-outline-secondary btn-sm">Verser l'avance</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($nonRatifiees && user_role() === 'coordinateur'): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-stamp"></i> Ratifier par résolution</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ratifier">
                    <?php foreach ($nonRatifiees as $p): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="prestations[]" value="<?= (int)$p['id'] ?>"
                               id="p<?= (int)$p['id'] ?>" checked>
                        <label class="form-check-label small" for="p<?= (int)$p['id'] ?>">
                            <?= e($p['intervenant']) ?> — mois <?= (int)$p['mois'] ?> — <?= e(htg((float)$p['brut'])) ?></label>
                    </div>
                    <?php endforeach; ?>
                    <div class="mt-2 mb-2">
                        <label class="form-label small mb-1">Résolution écrite de l'Assemblée Générale</label>
                        <input type="file" class="form-control form-control-sm" name="resolution" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <button class="btn btn-primary btn-sm">Ratifier</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
