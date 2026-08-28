<?php
declare(strict_types=1);

/**
 * Comptes - rapprochement bancaire, par compte et par mois calendaire.
 *
 * Le compte SOGEBANK porte les mouvements des deux projets : c'est le cas nominal
 * et non l'exception (CDC 4.9). Le rapprochement ne peut donc plus se produire
 * projet par projet de maniere autonome. Bousol produit un rapprochement par
 * compte, ventile par projet, dont chaque projet extrait la partie qui le
 * concerne pour la joindre a son rapport.
 *
 * Le mois calendaire, et non la periode du projet : les periodes des deux projets
 * ne coincident pas, et sans cette independance la cloture d'un projet attendrait
 * la completude des dossiers de l'autre.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_role(['coordinateur', 'raf']);
require_module('comptes');

$peutEcrire = user_role() === 'raf';
$erreur = null;

$comptesBanque = array_values(array_filter(comptes_plan(), fn($c) => $c['type'] === 'banque' && $c['compte_bancaire_id'] !== null));
$compteId = (int)($_GET['compte'] ?? ($comptesBanque[0]['id'] ?? 0));
$compte = null;
foreach ($comptesBanque as $c) {
    if ((int)$c['id'] === $compteId) {
        $compte = $c;
    }
}
$mois = (string)($_GET['mois'] ?? date('Y-m'));
$dateReleve = fin_de_mois($mois . '-01');

if ($compte !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutEcrire) {
        http_response_code(403);
        exit('403 - Le rapprochement revient au Responsable Administratif et Financier');
    }
    require_phase_execution('Établir le rapprochement');
    $action = (string)($_POST['action'] ?? '');

    $st = db()->prepare('SELECT * FROM rapprochements WHERE projet_id = ? AND compte_id = ? AND date_releve = ?');
    $st->execute([projet_id(), $compteId, $dateReleve]);
    $existant = $st->fetch() ?: null;

    if ($action === 'enregistrer') {
        $solde = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['solde_releve'] ?? '0'));
        $commentaire = trim((string)($_POST['commentaire_ecart'] ?? ''));
        $periode = periode_pour_date($dateReleve);
        if ($periode === null) {
            $erreur = 'Aucune période de projet ne couvre le ' . date_fr($dateReleve)
                    . ' : saisir d\'abord la date de début d\'exécution dans Paramétrage.';
        } elseif ($existant !== null && $existant['statut'] === 'valide') {
            $erreur = 'Ce rapprochement est validé : il ne se modifie plus.';
        } else {
            $etat = rapprochement_consolide((int)$compte['compte_bancaire_id'], $dateReleve, $solde,
                $existant ? lignes_rapprochement((int)$existant['id']) : []);
            if ($existant === null) {
                db()->prepare(
                    'INSERT INTO rapprochements (projet_id, periode_id, compte_id, date_releve, solde_releve,
                                                 solde_reconstitue, ecart, commentaire_ecart, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([projet_id(), (int)$periode['id'], $compteId, $dateReleve, $solde,
                            $etat['solde_ajuste'], $etat['ecart'], $commentaire ?: null, user_id()]);
                $rid = (int)db()->lastInsertId();
            } else {
                $rid = (int)$existant['id'];
                db()->prepare('UPDATE rapprochements SET solde_releve = ?, solde_reconstitue = ?, ecart = ?, commentaire_ecart = ? WHERE id = ?')
                    ->execute([$solde, $etat['solde_ajuste'], $etat['ecart'], $commentaire ?: null, $rid]);
            }
            audit('comptes', 'rapprochement_saisi', 'rapprochement', $rid,
                date_fr($dateReleve) . ' · relevé ' . htg($solde) . ' · écart ' . htg($etat['ecart']));
            flash_set('success', 'Rapprochement enregistré.');
            redirect(base_path('modules/comptes/rapprochement.php?compte=' . $compteId . '&mois=' . urlencode($mois)));
        }
    } elseif ($action === 'ligne' && $existant !== null) {
        $montant = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0'));
        $nature = (string)($_POST['nature'] ?? 'autre');
        $sens = (string)($_POST['sens'] ?? 'plus');
        if ($montant <= 0 || !array_key_exists($nature, NATURES_LIGNE_RAPPROCHEMENT) || !in_array($sens, ['plus', 'moins'], true)) {
            $erreur = 'Sens, nature et montant sont obligatoires.';
        } else {
            db()->prepare(
                'INSERT INTO lignes_rapprochement (projet_id, rapprochement_id, sens, nature, objet, montant, motif_non_concordance)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([projet_id(), (int)$existant['id'], $sens, $nature,
                        trim((string)($_POST['objet'] ?? '')), $montant,
                        trim((string)($_POST['motif'] ?? '')) ?: null]);
            redirect(base_path('modules/comptes/rapprochement.php?compte=' . $compteId . '&mois=' . urlencode($mois)));
        }
    } elseif ($action === 'valider' && $existant !== null) {
        $res = rapprochement_valider((int)$existant['id']);
        if (!$res['success']) {
            $erreur = $res['error'];
        } else {
            flash_set('success', 'Rapprochement validé.');
            redirect(base_path('modules/comptes/rapprochement.php?compte=' . $compteId . '&mois=' . urlencode($mois)));
        }
    }
}

$rappro = null;
$lignes = [];
if ($compte !== null) {
    $st = db()->prepare('SELECT * FROM rapprochements WHERE projet_id = ? AND compte_id = ? AND date_releve = ?');
    $st->execute([projet_id(), $compteId, $dateReleve]);
    $rappro = $st->fetch() ?: null;
    $lignes = $rappro ? lignes_rapprochement((int)$rappro['id']) : [];
}
$etat = $compte === null ? null : rapprochement_consolide((int)$compte['compte_bancaire_id'], $dateReleve,
    (float)($rappro['solde_releve'] ?? 0), $lignes);
$rattaches = $compte === null ? [] : projets_du_compte_bancaire((int)$compte['compte_bancaire_id']);

$ongletActif = 'rapprochement';
page_start('Rapprochement bancaire', 'comptes');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Rapprochement bancaire</h1>
    <form method="get" class="d-flex gap-2">
        <select class="form-select form-select-sm" name="compte" onchange="this.form.submit()">
            <?php foreach ($comptesBanque as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $compteId ? 'selected' : '' ?>><?= e($c['libelle']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="month" class="form-control form-control-sm" name="mois" value="<?= e($mois) ?>" onchange="this.form.submit()">
    </form>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($compte === null): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i>
    Aucun compte bancaire rattaché à ce projet.</div>
<?php else: ?>

<?php if (count($rattaches) > 1): ?>
<div class="alert alert-info py-2"><i class="bi bi-diagram-2"></i>
    <strong>Compte partagé</strong> entre <?= e(implode(', ', array_column($rattaches, 'code'))) ?>.
    Le rapprochement se produit par compte et se ventile par projet : chacun n'est validable
    qu'une fois que tous ont produit leur extrait au <?= e(date_fr($dateReleve)) ?>.
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-calculator"></i>
                État consolidé au <?= e(date_fr($dateReleve)) ?></div>
            <table class="table table-sm mb-0">
                <?php foreach ($etat['par_projet'] as $p): ?>
                <tr><td class="text-muted"><?= e($p['code']) ?> <?= e($p['intitule']) ?></td>
                    <td class="text-end"><?= e(htg($p['solde'])) ?></td></tr>
                <?php endforeach; ?>
                <tr class="fw-semibold"><td>Solde reconstitué, tous projets</td>
                    <td class="text-end"><?= e(htg($etat['reconstitue'])) ?></td></tr>
                <tr><td class="text-muted">Ajustements de rapprochement</td>
                    <td class="text-end"><?= e(htg($etat['ajustements'])) ?></td></tr>
                <tr class="fw-semibold"><td>Solde ajusté</td>
                    <td class="text-end"><?= e(htg($etat['solde_ajuste'])) ?></td></tr>
                <tr><td class="text-muted">Solde du relevé</td>
                    <td class="text-end"><?= e(htg($etat['solde_releve'])) ?></td></tr>
                <tr class="fw-semibold"><td>Écart</td>
                    <td class="text-end <?= abs($etat['ecart']) >= 0.01 ? 'text-danger' : '' ?>"><?= e(htg($etat['ecart'])) ?></td></tr>
            </table>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check"></i> Lignes de rapprochement</div>
            <table class="table table-sm mb-0">
                <?php foreach ($lignes as $l): ?>
                <tr>
                    <td class="small"><?= e(NATURES_LIGNE_RAPPROCHEMENT[$l['nature']] ?? $l['nature']) ?>
                        <br><span class="text-muted"><?= e($l['objet']) ?>
                        <?php if ($l['motif_non_concordance']): ?> · <?= e($l['motif_non_concordance']) ?><?php endif; ?></span></td>
                    <td class="text-end"><?= $l['sens'] === 'plus' ? '+' : '−' ?> <?= e(htg((float)$l['montant'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$lignes): ?><tr><td class="text-muted p-3">Aucune ligne. Chaque ligne porte son sens, son objet et son motif de non-concordance.</td></tr><?php endif; ?>
            </table>
            <?php if ($peutEcrire && $rappro !== null && $rappro['statut'] !== 'valide'): ?>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ligne">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Sens</label>
                        <select class="form-select form-select-sm" name="sens">
                            <option value="plus">Ajouter au reconstitué</option>
                            <option value="moins">Retrancher</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Nature</label>
                        <select class="form-select form-select-sm" name="nature">
                            <?php foreach (NATURES_LIGNE_RAPPROCHEMENT as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Montant</label>
                        <input class="form-control form-control-sm text-end" name="montant" inputmode="decimal" required>
                    </div>
                    <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100">Ajouter</button></div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Objet</label>
                        <input class="form-control form-control-sm" name="objet" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Motif de non-concordance</label>
                        <input class="form-control form-control-sm" name="motif" maxlength="255">
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text"></i>
                Extrait de <?= e(projet_code()) ?>
                <?php if ($rappro): ?><span class="badge text-bg-light border"><?= e($rappro['statut']) ?></span><?php endif; ?></div>
            <div class="card-body">
                <?php if (!$peutEcrire): ?>
                <p class="small text-muted">Le rapprochement est établi par le Responsable Administratif et Financier.
                    Vous en avez la lecture.</p>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enregistrer">
                    <fieldset <?= $peutEcrire && ($rappro === null || $rappro['statut'] !== 'valide') ? '' : 'disabled' ?>>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Solde du relevé au <?= e(date_fr($dateReleve)) ?></label>
                        <input class="form-control form-control-sm text-end" name="solde_releve" inputmode="decimal"
                               value="<?= $rappro ? e(number_format((float)$rappro['solde_releve'], 2, '.', '')) : '' ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Commentaire sur l'écart</label>
                        <textarea class="form-control form-control-sm" name="commentaire_ecart" rows="3"><?= e($rappro['commentaire_ecart'] ?? '') ?></textarea>
                        <div class="form-text">Tout écart non résolu exige un commentaire avant validation.</div>
                    </div>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button>
                    </fieldset>
                </form>
                <?php if ($peutEcrire && $rappro !== null && $rappro['statut'] !== 'valide'): ?>
                <form method="post" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="valider">
                    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-lock"></i> Valider le rapprochement</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php page_end();
