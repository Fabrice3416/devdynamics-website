<?php
declare(strict_types=1);

/**
 * Comptes - petite caisse en fonds fixe (CDC 4.6).
 *
 * La caisse n'est pas une table a part, c'est un compte de type caisse : son
 * journal est la suite de ses mouvements, et son arrete periodique est le seul
 * objet propre. Deux plafonds parametrables s'appliquent, le montant du fonds et
 * le montant maximal d'une depense unitaire en especes.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_role(['coordinateur', 'raf']);
require_module('comptes');

$compte = compte_caisse();
$erreur = null;

if ($compte !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_phase_execution('Arrêter la caisse');
    $res = arrete_caisse_creer(
        (int)$compte['id'],
        (string)($_POST['date'] ?? date('Y-m-d')),
        (float)str_replace([' ', ','], ['', '.'], (string)($_POST['solde_constate'] ?? '0')),
        (int)($_POST['detenteur_id'] ?? 0) ?: null,
        trim((string)($_POST['commentaire'] ?? ''))
    );
    if (!$res['success']) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Arrêté de caisse enregistré. Il conditionne le prochain renflouement.');
        redirect(base_path('modules/comptes/caisse.php'));
    }
}

$mois = (string)($_GET['mois'] ?? date('Y-m'));
$debut = $mois . '-01';
$fin = fin_de_mois($debut);
$journal = $compte === null ? null : caisse_journal((int)$compte['id'], $debut, $fin);
$plafondFonds = param('plafond_petite_caisse');
$plafondDepense = param('plafond_depense_especes');
$personnes = db()->query("SELECT id, nom FROM tiers WHERE type = 'personne' AND actif = 1 ORDER BY nom")->fetchAll();

$ongletActif = 'caisse';
page_start('Petite caisse', 'comptes');
require __DIR__ . '/_nav.php';
?>
<h1 class="h4 mb-3">Petite caisse</h1>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($compte === null): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i>
    Ce projet n'a pas de compte de caisse actif. Sur Koulè Ki Pale, le module est disponible mais désactivé par défaut.</div>
<?php else: ?>

<?php if ($plafondFonds === null || $plafondDepense === null): ?>
<div class="alert alert-warning py-2"><i class="bi bi-sliders"></i>
    Les deux plafonds de l'annexe F ne sont pas saisis : le montant du fonds et le montant maximal
    d'une dépense unitaire en espèces. Tant qu'ils manquent, aucun plafond ne s'applique.
    <?php if (user_role() === 'coordinateur'): ?><a href="<?= e(base_path('modules/noyau/')) ?>">Paramétrer</a>.<?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Solde en caisse</div>
        <div class="valeur fs-5"><?= e(htg(solde_compte((int)$compte['id']))) ?></div>
        <small class="text-muted"><?= $plafondFonds === null ? 'fonds non plafonné' : 'fonds fixe ' . e(htg((float)$plafondFonds)) ?></small>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Plafond d'une dépense</div>
        <div class="valeur fs-5"><?= $plafondDepense === null ? '—' : e(htg((float)$plafondDepense)) ?></div>
        <small class="text-muted">en espèces, par opération</small>
    </div></div></div>
    <div class="col-12 col-lg-6"><div class="card card-indicateur border-0 shadow-sm"><div class="card-body">
        <div class="libelle">Dernier arrêté</div>
        <?php $dernier = dernier_arrete_caisse((int)$compte['id']); ?>
        <div class="valeur fs-5"><?= $dernier ? e(date_fr($dernier['date'])) : 'aucun' ?></div>
        <small class="text-muted"><?= $dernier
            ? 'écart ' . e(htg((float)$dernier['ecart']))
            : 'le renflouement suppose la justification des dépenses antérieures' ?></small>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-cash-coin"></i> Journal de caisse</span>
                <form method="get"><input type="month" class="form-control form-control-sm" name="mois"
                    value="<?= e($mois) ?>" onchange="this.form.submit()"></form>
            </div>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr class="small text-muted">
                    <th>Date</th><th>Libellé</th><th class="text-end">Entrée</th>
                    <th class="text-end">Sortie</th><th class="text-end">Balance</th>
                </tr></thead>
                <tbody>
                <tr class="fw-semibold"><td colspan="4">Solde initial au <?= e(date_fr($debut)) ?></td>
                    <td class="text-end"><?= e(htg($journal['solde_initial'])) ?></td></tr>
                <?php foreach ($journal['lignes'] as $l): ?>
                <tr>
                    <td class="small text-muted"><?= e(date_fr($l['date'])) ?></td>
                    <td class="small"><?= e($l['libelle']) ?>
                        <?php if ($l['depense_reportee']): ?><span class="badge text-bg-light border">reportée</span><?php endif; ?>
                        <?php if ($l['observation']): ?><br><small class="text-muted"><?= e($l['observation']) ?></small><?php endif; ?></td>
                    <td class="text-end"><?= $l['entree'] > 0 ? e(htg($l['entree'])) : '' ?></td>
                    <td class="text-end"><?= $l['sortie'] > 0 ? e(htg($l['sortie'])) : '' ?></td>
                    <td class="text-end"><?= e(htg($l['balance'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$journal['lignes']): ?><tr><td colspan="5" class="text-muted p-3">Aucun mouvement sur ce mois.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check"></i> Arrêter la caisse</div>
            <div class="card-body">
                <p class="small text-muted">Compter les espèces et les confronter au solde théorique.
                    Un écart doit être expliqué. L'arrêté conditionne le renflouement.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Date</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Espèces comptées</label>
                        <input class="form-control form-control-sm text-end" name="solde_constate" inputmode="decimal" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Détenteur du fonds</label>
                        <select class="form-select form-select-sm" name="detenteur_id">
                            <option value="">—</option>
                            <?php foreach ($personnes as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">L'approvisionnement se fait par chèque à son nom, jamais au porteur.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Commentaire</label>
                        <textarea class="form-control form-control-sm" name="commentaire" rows="2"></textarea>
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer l'arrêté</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history"></i> Arrêtés</div>
            <ul class="list-group list-group-flush">
                <?php foreach (arretes_caisse((int)$compte['id']) as $a): ?>
                <li class="list-group-item small"><?= e(date_fr($a['date'])) ?>
                    <span class="text-muted">· théorique <?= e(htg((float)$a['solde_theorique'])) ?>
                        · compté <?= e(htg((float)$a['solde_constate'])) ?>
                        <?php if (abs((float)$a['ecart']) >= 0.01): ?>· écart <?= e(htg((float)$a['ecart'])) ?><?php endif; ?></span>
                    <?php if ($a['detenteur_nom']): ?><br><span class="text-muted"><?= e($a['detenteur_nom']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
                <?php if (!arretes_caisse((int)$compte['id'])): ?><li class="list-group-item small text-muted">Aucun arrêté.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>
<?php page_end();
