<?php
declare(strict_types=1);

/**
 * Noyau - bascule vers la phase de suivi post-cloture (CDC 1.7 et 9).
 *
 * La bascule n'est jamais automatique : elle se decide, apres une periode de
 * regularisation et une checklist bloquante. C'est le dernier geste de la phase
 * d'execution, et il ne se defait que par une reouverture exceptionnelle, bornee
 * dans le temps, qui ne rouvre jamais la creation de depense.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/bascule.php';
require_role(['coordinateur']);

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'regularisation') {
        $res = regularisation_ouvrir();
        if (!empty($res['success'])) {
            flash_set('success', 'Période de régularisation ouverte pour ' . (int)$res['duree'] . ' jours. '
                . 'Aucune imputation nouvelle ; les dossiers ouverts se mènent à leur terme.');
            redirect(base_path('modules/noyau/bascule.php'));
        }
    } elseif ($action === 'basculer') {
        $res = basculer((string)($_POST['motif'] ?? ''));
        if (!empty($res['success'])) {
            flash_set('success', 'Bascule effectuée. Enveloppe indirecte figée à ' . htg((float)$res['enveloppe'])
                . ' sur ' . htg((float)$res['directs']) . ' de coûts directs constatés.');
            redirect(base_path('modules/noyau/bascule.php'));
        }
    } elseif ($action === 'reouvrir') {
        $res = reouverture_ouvrir((string)($_POST['motif'] ?? ''), (string)($_POST['date_limite'] ?? ''));
    } elseif ($action === 'clore_reouverture') {
        $res = reouverture_clore((int)($_POST['reouverture_id'] ?? 0));
    } elseif ($action === 'archive') {
        $res = archive_definitive();
        if (!empty($res['success'])) {
            flash_set('success', 'Archive définitive produite : ' . (int)$res['nombre'] . ' entrée(s) indexée(s).');
            redirect(base_path('modules/noyau/bascule.php'));
        }
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/noyau/bascule.php'));
    }
}

$phase = phase_code();
$checklist = bascule_checklist();
$listeReouvertures = reouvertures();
$expirees = reouvertures_expirees();
$solde = solde_cloture();
$activee = param('suivi_post_cloture', '0') === '1';
$figee = param('enveloppe_indirecte_figee');
$residuel = duree_residuelle_phase2();

$ongletActif = 'bascule';
page_start('Bascule', 'noyau');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Bascule vers la phase de suivi</h1>
    <span class="text-muted small">Phase courante :
        <?= e(match ($phase) {
            'projet_actif' => 'projet actif', 'regularisation' => 'régularisation',
            'post_cloture' => 'suivi post-clôture', default => 'non initialisée' }) ?></span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if (!$activee): ?>
<div class="alert alert-info py-2"><i class="bi bi-info-circle"></i>
    La double temporalité n'est pas activée sur ce projet : il se clôt à la transmission de son rapport final,
    sans phase de suivi. C'est le cas de Koulè Ki Pale.</div>
<?php endif; ?>

<?php if ($expirees): ?>
<div class="alert alert-warning py-2"><i class="bi bi-clock-history"></i>
    <strong><?= count($expirees) ?> réouverture(s) dont la borne est dépassée.</strong>
    Une réouverture est bornée dans le temps : celle-ci doit être close.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Enveloppe indirecte</div>
        <div class="valeur fs-5"><?= $figee === null ? e(htg($solde['indirects'])) : e(htg((float)$figee)) ?></div>
        <small class="text-muted"><?= $figee === null
            ? 'théorique, recalculée en continu · se fige à la bascule'
            : 'figée à la bascule · c\'est elle qui finance la phase 2' ?></small>
    </div></div></div>
    <div class="col-6 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Coûts directs constatés</div>
        <div class="valeur fs-5"><?= e(htg($solde['directs'])) ?></div>
        <small class="text-muted">assiette des sept pour cent, article 3.3</small>
    </div></div></div>
    <div class="col-12 col-lg-4"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Durée résiduelle de la phase 2</div>
        <div class="valeur fs-5"><?= $residuel === null ? 'sans objet' : (int)$residuel . ' mois' ?></div>
        <small class="text-muted"><?= $residuel === null
            ? 'suivi post-clôture désactivé'
            : 'jusqu\'à la seconde borne du ' . e(date_fr((string)seconde_borne())) ?></small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-square"></i>
                Checklist de bascule — chaque point est bloquant</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($checklist['points'] as $p): ?>
                <li class="list-group-item">
                    <i class="bi <?= $p['ok'] ? 'bi-check2-circle text-success' : 'bi-x-circle text-danger' ?>"></i>
                    <strong><?= e($p['nom']) ?></strong>
                    <br><small class="text-muted"><?= e($p['motif']) ?></small>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history"></i> Réouvertures exceptionnelles</div>
            <table class="table table-sm mb-0">
                <?php foreach ($listeReouvertures as $r): ?>
                <tr>
                    <td class="small"><?= e($r['motif']) ?>
                        <br><span class="text-muted">du <?= e(date_fr($r['date_debut'])) ?>
                        au <?= e(date_fr($r['date_limite'])) ?> · <?= e($r['auteur']) ?></span></td>
                    <td class="text-end">
                        <span class="badge text-bg-light border"><?= e($r['statut']) ?></span>
                        <?php if ($r['statut'] === 'ouverte'): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="clore_reouverture">
                            <input type="hidden" name="reouverture_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-link p-0">clore</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$listeReouvertures): ?><tr><td class="text-muted p-3">Aucune réouverture.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($phase === 'projet_actif'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-hourglass-split"></i> Ouvrir la régularisation</div>
            <div class="card-body">
                <p class="small text-muted">Pendant la régularisation, <strong>aucune imputation nouvelle</strong>
                    ne se crée, mais les dossiers déjà ouverts se mènent à leur terme. Durée paramétrée :
                    <?= e(param('duree_regularisation_jours', '30')) ?> jours.</p>
                <p class="small text-muted">Tout engagement doit être ouvert avant cette étape, même sans sa facture :
                    une prestation rendue pendant la période éligible mais sans dossier ne trouvera plus où s'imputer.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="regularisation">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-right"></i> Ouvrir la régularisation</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($phase === 'regularisation' && $activee): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right"></i> Basculer</div>
            <div class="card-body">
                <p class="small text-muted">La bascule ferme la quasi-totalité des modules. Trois registres restent
                    ouverts : le journal de support, le registre des correctifs et l'enquête d'adoption.
                    L'enveloppe indirecte se fige à cet instant.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="basculer">
                    <div class="mb-2">
                        <label class="form-label small mb-1">Motif</label>
                        <input class="form-control form-control-sm" name="motif" required maxlength="255">
                    </div>
                    <button class="btn btn-primary btn-sm" <?= $checklist['ok'] ? '' : 'disabled' ?>>
                        <i class="bi bi-lock"></i> Basculer en suivi post-clôture</button>
                    <?php if (!$checklist['ok']): ?>
                    <div class="form-text">La checklist n'est pas complète.</div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($phase === 'post_cloture'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-unlock"></i> Réouverture exceptionnelle</div>
            <div class="card-body">
                <p class="small text-muted">Motivée, tracée, bornée dans le temps. Elle rouvre l'état de
                    régularisation et <strong>jamais la création de dépense</strong>.</p>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reouvrir">
                    <div class="col-12">
                        <label class="form-label small mb-1">Motif</label>
                        <input class="form-control form-control-sm" name="motif" required maxlength="255">
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1">Bornée au</label>
                        <input type="date" class="form-control form-control-sm" name="date_limite" required>
                    </div>
                    <div class="col-5 d-flex align-items-end">
                        <button class="btn btn-outline-secondary btn-sm w-100">Rouvrir</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-archive"></i> Archive définitive</div>
            <div class="card-body">
                <p class="small text-muted">À la seconde borne, le paquet autoportant est remis à la direction et
                    conservé jusqu'en 2032 au titre de l'article 5.6. La base peut alors être arrêtée.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="archive">
                    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-seam"></i>
                        Produire l'index d'archive</button>
                    <div class="form-text">L'extraction des fichiers eux-mêmes passe par l'export de sauvegarde.</div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php page_end();
