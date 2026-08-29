<?php
declare(strict_types=1);

/**
 * Restitution - fiche d'un rapport : les onze lignes de l'annexe G, la validation
 * qui fige la periode, la transmission et la rectification.
 *
 * Les lignes sont stockees et non recalculees : une correction ulterieure dans un
 * dossier ancien ne modifie jamais un rapport deja envoye (CDC 6.7).
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/restitution.php';
require_projet();
require_module('restitution');

$id = (int)($_GET['id'] ?? 0);
$r = rapport_restitution($id);
if ($r === null) {
    http_response_code(404);
    exit('404 - Rapport inconnu');
}
$erreur = null;
$ecarts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $res = ['success' => false, 'error' => 'Action inconnue.'];

    if ($action === 'recalculer') {
        rapport_calculer_lignes($id);
        $res = ['success' => true];
    } elseif ($action === 'valider') {
        $res = rapport_valider($id);
    } elseif ($action === 'transmettre') {
        $res = rapport_transmettre($id, (string)($_POST['date'] ?? date('Y-m-d')), $_FILES['accuse'] ?? null);
    } elseif ($action === 'rectifier') {
        $res = rapport_rectifier($id, (string)($_POST['motif'] ?? ''));
        if (!empty($res['success'])) {
            flash_set('success', 'Version rectificative ouverte.'
                . ($res['ecarts'] ? ' Écarts : ' . implode(' ; ', $res['ecarts']) : ''));
            redirect(base_path('modules/restitution/rapport.php?id=' . (int)$res['id']));
        }
    } elseif ($action === 'liasse') {
        $res = liasse_periode($id);
        if (!empty($res['success'])) {
            flash_set('success', 'Liasse produite, ' . (int)$res['nombre'] . ' pièce(s).');
            redirect(base_path('modules/restitution/rapport.php?id=' . $id));
        }
    } elseif ($action === 'produire') {
        $type = (string)($_POST['document'] ?? '');
        $res = document_generer($type, donnees_rapport($id, $type), 'rapport', $id, 'restitution',
            (string)($_POST['exemplaire'] ?? ''));
        if (!empty($res['success'])) {
            redirect(base_path('pdf/serve.php?id=' . (int)$res['fichier_id']));
        }
    }

    if (empty($res['success'])) {
        $erreur = $res['error'];
    } else {
        flash_set('success', 'Enregistré.');
        redirect(base_path('modules/restitution/rapport.php?id=' . $id));
    }
    $r = rapport_restitution($id);
}

/** Les donnees d'un gabarit de rapport, assemblees au moment de produire. */
function donnees_rapport(int $rapportId, string $type): array
{
    $r = rapport_restitution($rapportId);
    $contenu = json_decode((string)($r['contenu_json'] ?? '{}'), true) ?: [];
    $base = [
        'rapport'     => $r,
        'commentaire' => $contenu['commentaire'] ?? '',
    ];
    if ($type === 'rapport_financier') {
        return $base + ['lignes' => lignes_financieres($rapportId), 'solde' => solde_cloture()];
    }
    if ($type === 'ventilation') {
        return $base + ['ventilation' => ventilation((string)$r['periode_debut'], (string)$r['periode_fin'])];
    }
    return $base + [
        'elements'   => cadre_elements(),
        'indicateurs' => indicateurs(),
        'activites'  => activites(),
        'difficultes' => difficultes(),
        'solde'      => solde_cloture(),
        'reussite'   => taux_reussite(),
        'adoption'   => adoption(),
    ];
}

$lignes = lignes_financieres($id);
$mesLiasses = liasses($id);
$peutAgir = user_role() === 'coordinateur';
$documents = ['rapport_mensuel' => 'Rapport mensuel', 'rapport_narratif' => 'Rapport narratif (annexe 4)',
              'rapport_financier' => 'Rapport financier (annexe G)', 'ventilation' => 'Ventilation détaillée'];
$exemplaires = mentions_exemplaires();

$ongletActif = 'cloture';
page_start(TYPES_RAPPORT[$r['type']], 'restitution');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= e(TYPES_RAPPORT[$r['type']]) ?>
        <small class="text-muted fw-normal">· <?= e(date_fr($r['periode_debut'])) ?> au <?= e(date_fr($r['periode_fin'])) ?>
        · <?= e(STATUTS_RAPPORT_RESTITUTION[$r['statut']]) ?>
        <?php if ((int)$r['version'] > 1): ?>· version <?= (int)$r['version'] ?><?php endif; ?></small></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_path('modules/restitution/')) ?>">
        <i class="bi bi-arrow-left"></i> Rapports</a>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<?php if ($r['statut'] !== 'brouillon'): ?>
<div class="alert alert-info py-2"><i class="bi bi-lock"></i>
    Ce rapport est figé : ses lignes sont stockées et ne se recalculent plus. Une correction ultérieure dans un
    dossier ancien ne le modifiera jamais — elle passera par une version rectificative.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span><i class="bi bi-table"></i> Rapport financier au modèle de l'annexe G</span>
        <span class="small fw-normal text-muted">le budget affiché est le contractuel figé, jamais le budget de gestion</span>
    </div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr class="small text-muted">
            <th style="min-width:18rem">Ligne</th>
            <th class="text-end">Unité</th><th class="text-end">Qté</th><th class="text-end">Val. unit.</th>
            <th class="text-end">Budget (a)</th>
            <th class="text-end">Qté période</th><th class="text-end">Val. moy.</th><th class="text-end">Période (b)</th>
            <th class="text-end">Antérieur (c)</th><th class="text-end">Cumul (d)</th><th class="text-end">d − a</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lignes as $lf): $imputable = $lf['nature'] === 'imputable'; ?>
        <tr class="<?= $imputable ? '' : 'fw-semibold' ?>">
            <td style="padding-left:<?= (int)$lf['niveau'] * 0.8 ?>rem">
                <span class="text-muted small me-2"><?= e($lf['code']) ?></span><?= e($lf['libelle']) ?></td>
            <td class="text-end small text-muted"><?= $lf['budget_unite'] ? e(UNITES[$lf['budget_unite']] ?? $lf['budget_unite']) : '' ?></td>
            <td class="text-end small text-muted"><?= $lf['budget_quantite'] === null ? '' : e(rtrim(rtrim(number_format((float)$lf['budget_quantite'], 2, ',', ' '), '0'), ',')) ?></td>
            <td class="text-end small text-muted"><?= $lf['budget_valeur'] === null ? '' : e(htg((float)$lf['budget_valeur'], false)) ?></td>
            <td class="text-end"><?= $lf['budget_total'] === null ? '' : e(htg((float)$lf['budget_total'], false)) ?></td>
            <td class="text-end small"><?= $lf['periode_quantite'] === null ? '' : e(rtrim(rtrim(number_format((float)$lf['periode_quantite'], 2, ',', ' '), '0'), ',')) ?></td>
            <td class="text-end small"><?= $lf['periode_valeur'] === null ? '' : e(htg((float)$lf['periode_valeur'], false)) ?></td>
            <td class="text-end"><?= (float)$lf['periode_total'] != 0.0 ? e(htg((float)$lf['periode_total'], false)) : '' ?></td>
            <td class="text-end text-muted"><?= (float)$lf['cumul_anterieur'] != 0.0 ? e(htg((float)$lf['cumul_anterieur'], false)) : '' ?></td>
            <td class="text-end"><?= (float)$lf['cumul_total'] != 0.0 ? e(htg((float)$lf['cumul_total'], false)) : '' ?></td>
            <td class="text-end text-muted"><?= $lf['difference'] === null ? '' : e(htg((float)$lf['difference'], false)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lignes): ?><tr><td colspan="11" class="text-muted p-3">Aucune ligne calculée.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-0">La valeur unitaire des dépenses est une moyenne — le coût total divisé par
            la quantité — ce qui reste exact lorsqu'une même ligne reçoit des dépenses à des valeurs unitaires
            différentes. Sur une ligne non consommée, la quantité étant nulle, la colonne reste vide.
            La provision n'étant jamais imputée directement, sa ligne reste à zéro en dépenses.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-right-circle"></i> Cycle du rapport</div>
            <div class="card-body">
                <?php if ($r['statut'] === 'brouillon' && $peutAgir): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="recalculer">
                    <button class="btn btn-outline-secondary btn-sm">Recalculer les lignes</button>
                </form>
                <form method="post" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="valider">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-lock"></i> Valider et figer la période</button>
                </form>
                <p class="form-text mt-2 mb-0">La validation vérifie les trois conditions de clôture,
                    fige la période et la version du cadre logique jointe.</p>

                <?php elseif ($r['statut'] === 'valide'): ?>
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="transmettre">
                    <div class="col-5">
                        <label class="form-label small mb-1">Date de transmission</label>
                        <input type="date" class="form-control form-control-sm" name="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small mb-1">Accusé de réception</label>
                        <input type="file" class="form-control form-control-sm" name="accuse" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-2"><button class="btn btn-primary btn-sm w-100">Transmettre</button></div>
                    <div class="col-12"><div class="form-text">Le figement précède la transmission, de sorte que la
                        copie envoyée et la version conservée soient rigoureusement identiques.</div></div>
                </form>
                <?php endif; ?>

                <?php if ($r['statut'] !== 'brouillon' && $peutAgir): ?>
                <hr>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rectifier">
                    <div class="col-8">
                        <label class="form-label small mb-1">Réouverture exceptionnelle — motif</label>
                        <input class="form-control form-control-sm" name="motif" required maxlength="255">
                    </div>
                    <div class="col-4"><button class="btn btn-outline-secondary btn-sm w-100">Rectifier</button></div>
                    <div class="col-12"><div class="form-text">Produit une version rectificative numérotée et laisse
                        intacte la version transmise. C'est elle qui alimentera le cumul du rapport suivant.</div></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-pdf"></i> Documents à produire</div>
            <div class="card-body">
                <?php foreach ($documents as $code => $lib): $doc = document_de($code, 'rapport', $id); ?>
                <form method="post" class="d-flex justify-content-between align-items-center mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="produire">
                    <input type="hidden" name="document" value="<?= e($code) ?>">
                    <span class="small"><?= e($lib) ?>
                        <?php if ($doc && $doc['fichier_id']): ?>
                        <a class="ms-2" href="<?= e(base_path('pdf/serve.php?id=' . (int)$doc['fichier_id'])) ?>">produit</a>
                        <?php endif; ?></span>
                    <span>
                        <?php if ($exemplaires): ?>
                        <select class="form-select form-select-sm d-inline-block" style="width:14rem" name="exemplaire">
                            <option value="">sans mention</option>
                            <?php foreach ($exemplaires as $ex): ?>
                            <option value="<?= e($ex) ?>"><?= e($ex) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-link p-0 ms-1"><?= $doc ? 'régénérer' : 'produire' ?></button>
                    </span>
                </form>
                <?php endforeach; ?>
                <?php if ($exemplaires): ?>
                <p class="form-text mb-0">Trois exemplaires portent leur mention :
                    <?= e(implode(' · ', $exemplaires)) ?>.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-archive"></i> Liasse de période</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($mesLiasses as $l): ?>
                <li class="list-group-item small">
                    <a href="<?= e(base_path('pdf/serve.php?id=' . (int)$l['fichier_id'])) ?>"><?= e($l['nom_genere']) ?></a>
                    <span class="text-muted">· <?= (int)$l['nombre_pieces'] ?> pièce(s)</span></li>
                <?php endforeach; ?>
                <?php if (!$mesLiasses): ?><li class="list-group-item small text-muted">Aucune liasse produite.</li><?php endif; ?>
            </ul>
            <?php if ($peutAgir): ?>
            <div class="card-body">
                <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="liasse">
                    <button class="btn btn-outline-secondary btn-sm">Produire la liasse</button>
                    <div class="form-text">L'index range les pièces par numéro, de sorte que le classement
                        électronique et le classement physique soient identiques.</div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php page_end();
