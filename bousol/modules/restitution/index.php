<?php
declare(strict_types=1);

/**
 * Restitution - cloture de periode et rapports.
 *
 * « La cloture n'est pas datee, elle est conditionnee. Elle intervient quand les
 * dossiers de la periode sont complets, et son declenchement reste manuel »
 * (CDC 6.6). L'ecran montre donc les trois etapes bloquantes avant d'offrir le
 * bouton, et non l'inverse.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/restitution.php';
require_projet();
require_module('restitution');

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'ouvrir') {
        $res = rapport_ouvrir((string)($_POST['type'] ?? ''), (int)($_POST['periode_id'] ?? 0),
            (string)($_POST['commentaire'] ?? ''));
        if (!empty($res['success'])) {
            redirect(base_path('modules/restitution/rapport.php?id=' . (int)$res['id']));
        }
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/restitution/index.php'));
    }
}

$listePeriodes = periodes();
$ouvertes = periodes_ouvertes();
$liste = rapports_restitution();
$solde = solde_cloture();
$periodeChoisie = (int)($_GET['periode'] ?? ($ouvertes[0]['id'] ?? 0));
$controles = $periodeChoisie > 0 ? cloture_controles($periodeChoisie) : null;
$cheques = $periodeChoisie > 0 ? cheques_en_circulation($periodeChoisie) : [];
$peutCloturer = user_role() === 'coordinateur';

$ongletActif = 'cloture';
page_start('Clôture et rapports', 'restitution');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Clôture de période et rapports</h1>
    <span class="text-muted small"><?= count($listePeriodes) ?> période(s) · <?= count($ouvertes) ?> non figée(s)</span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if (!$listePeriodes): ?>
<div class="alert alert-warning py-2"><i class="bi bi-calendar-x"></i>
    Aucune période n'existe : le calendrier dérive de la date de début d'exécution, qui n'est pas saisie.
    <?php if (user_role() === 'coordinateur'): ?><a href="<?= e(base_path('modules/noyau/')) ?>">Paramétrer</a>.<?php endif; ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Coûts directs constatés</div>
        <div class="valeur fs-5"><?= e(htg($solde['directs'])) ?></div>
        <small class="text-muted">assiette des coûts indirects</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Enveloppe indirecte</div>
        <div class="valeur fs-5"><?= e(htg($solde['indirects'])) ?></div>
        <small class="text-muted"><?= number_format($solde['taux_indirect'] * 100, 2, ',', ' ') ?> %, plafonnée au contrat</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Préfinancements reçus</div>
        <div class="valeur fs-5"><?= e(htg($solde['prefinancements'])) ?></div>
        <small class="text-muted">constatés sur avis de crédit</small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Solde <?= $solde['sens'] === 'a_recevoir' ? 'à recevoir' : 'à rembourser' ?></div>
        <div class="valeur fs-5"><?= e(htg($solde['solde'])) ?></div>
        <small class="text-muted">calculé, jamais estimé</small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-check2-square"></i> Conditions de clôture</span>
                <form method="get"><select class="form-select form-select-sm" name="periode" onchange="this.form.submit()">
                    <?php foreach ($listePeriodes as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $periodeChoisie ? 'selected' : '' ?>>
                        Période <?= (int)$p['numero'] ?> — <?= e($p['statut']) ?></option>
                    <?php endforeach; ?>
                </select></form>
            </div>
            <?php if ($controles !== null): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($controles['etapes'] as $et): ?>
                <li class="list-group-item">
                    <i class="bi <?= $et['ok'] ? 'bi-check2-circle text-success' : 'bi-x-circle text-danger' ?>"></i>
                    <strong><?= e($et['nom']) ?></strong>
                    <br><small class="text-muted"><?= e($et['motif']) ?></small>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body">
                <p class="small text-muted mb-2">La clôture n'est pas datée, elle est conditionnée : elle intervient
                    quand les dossiers sont complets. Un dossier n'a pas besoin d'être clos, mais il doit être réglé
                    et porter toutes ses pièces préalables au paiement.</p>
                <?php if ($cheques): ?>
                <p class="small mb-0"><strong><?= count($cheques) ?> chèque(s) en circulation</strong> —
                    remis et réglés, non encore présentés à la banque. La clôture arrête leur liste ;
                    leur encaissement apparaîtra au rapprochement sans rien rouvrir.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($peutCloturer && $periodeChoisie > 0): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-plus"></i> Ouvrir un rapport</div>
            <div class="card-body">
                <p class="small text-muted">Une seule séquence sert les trois échéances : seule la liste de sorties
                    varie. Un rapport se prépare pendant que les dossiers se complètent ; les trois conditions ne
                    sont exigées qu'à la validation.</p>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ouvrir">
                    <input type="hidden" name="periode_id" value="<?= $periodeChoisie ?>">
                    <div class="col-6">
                        <label class="form-label small mb-1">Échéance</label>
                        <select class="form-select form-select-sm" name="type">
                            <?php foreach (TYPES_RAPPORT as $k => $lib): if ($k === 'rectificatif') continue; ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Commentaire d'analyse
                            <span class="text-muted">— la seule saisie propre au rapport mensuel</span></label>
                        <textarea class="form-control form-control-sm" name="commentaire" rows="3"></textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-primary btn-sm">Ouvrir le rapport</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-journals"></i> Rapports</div>
            <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr class="small text-muted">
                    <th>Échéance</th><th>Période</th><th>Cadre</th><th>Statut</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($liste as $r): ?>
                <tr>
                    <td class="small"><?= e(TYPES_RAPPORT[$r['type']]) ?>
                        <?php if ((int)$r['version'] > 1): ?><br><span class="text-muted">version <?= (int)$r['version'] ?></span><?php endif; ?></td>
                    <td class="small text-muted"><?= e(date_fr($r['periode_debut'])) ?><br><?= e(date_fr($r['periode_fin'])) ?></td>
                    <td class="small text-muted"><?= $r['version_cadre_ref'] ? 'v' . (int)$r['version_cadre_ref'] : '—' ?></td>
                    <td><span class="badge text-bg-light border"><?= e(STATUTS_RAPPORT_RESTITUTION[$r['statut']]) ?></span>
                        <?php if ($r['date_transmission']): ?><br><small class="text-muted"><?= e(date_fr($r['date_transmission'])) ?></small><?php endif; ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-link p-0"
                        href="<?= e(base_path('modules/restitution/rapport.php?id=' . (int)$r['id'])) ?>">ouvrir</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$liste): ?><tr><td colspan="5" class="text-muted p-3">Aucun rapport.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<?php page_end();
