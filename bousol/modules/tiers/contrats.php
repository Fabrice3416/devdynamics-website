<?php
declare(strict_types=1);

/**
 * Tiers - contrats de service et conventions de partenariat.
 *
 * Le contrat relie une personne a une ligne budgetaire, ce qui permet au controle de
 * quantite de fonctionner, et porte son taux d'acompte par defaut (CDC 8.2). Le
 * rattachement a une ligne reste facultatif : une convention de partenariat non
 * remuneree n'en a aucune (CDC 3.4).
 *
 * Bousol calcule pour chaque contrat l'echeancier theorique des versements et le
 * confronte a la quantite budgetee (CDC 3.1). Une meme personne peut porter
 * plusieurs contrats : le budget de KesKle prevoit que le developpeur back-end et le
 * formateur soient la meme personne, laquelle est aussi mandataire du compte.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/budget.php';
require_projet();
require_module('tiers');

// Un contrat engage le projet : le Coordinateur seul le noue, le RAF le lit.
$peutEcrire = user_role() === 'coordinateur';
$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$peutEcrire) {
        http_response_code(403);
        exit('403 - Acces refuse');
    }
    $tiersId = (int)($_POST['tiers_id'] ?? 0);
    $type    = (string)($_POST['type'] ?? 'service');
    $ligneId = (int)($_POST['ligne_id'] ?? 0) ?: null;
    $fonction = trim((string)($_POST['fonction'] ?? ''));
    $numero   = trim((string)($_POST['numero'] ?? ''));
    $debut    = (string)($_POST['date_debut'] ?? '');
    $fin      = (string)($_POST['date_fin'] ?? '');
    $unite    = (string)($_POST['unite'] ?? 'forfait');
    $quantite = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['quantite'] ?? '0'));
    $unitaire = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant_unitaire'] ?? '0'));
    $acompte  = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['taux_acompte'] ?? param('taux_acompte_defaut_pct', '2')));
    $avance   = !empty($_POST['avance_autorisee']) ? 1 : 0;
    $partAvance = trim((string)($_POST['part_avance'] ?? ''));

    if ($tiersId <= 0 || $fonction === '' || $debut === '' || $fin === '') {
        $erreur = 'Tiers, fonction et dates sont obligatoires.';
    } elseif (!array_key_exists($type, TYPES_CONTRAT)) {
        $erreur = 'Type de contrat hors liste.';
    } elseif (!array_key_exists($unite, UNITES)) {
        $erreur = 'Unité hors liste.';
    } elseif ($fin < $debut) {
        $erreur = 'La date de fin précède la date de début.';
    } elseif ($quantite <= 0 || $unitaire < 0) {
        $erreur = 'Quantité et montant unitaire doivent être renseignés.';
    } elseif ($type !== 'convention_partenariat' && $ligneId === null) {
        $erreur = 'Un contrat rémunéré doit être rattaché à sa ligne budgétaire : c\'est ce rattachement qui fait fonctionner le contrôle de quantité.';
    } elseif ($avance && param('avances_honoraires', '0') !== '1') {
        $erreur = 'Les avances sur honoraires ne sont pas autorisées sur ce projet (annexe F).';
    } elseif ($avance && $unite === 'mois') {
        $erreur = 'Une avance est interdite sur un contrat mensuel récurrent : elle ne vaut que pour les rémunérations non récurrentes.';
    }

    // Le cloisonnement d'abord : un contrat ne s'adosse jamais a la ligne d'un autre projet.
    $ligne = null;
    if ($erreur === null && $ligneId !== null) {
        $sl = db()->prepare('SELECT * FROM lignes_budgetaires WHERE id = ? AND projet_id = ?');
        $sl->execute([$ligneId, projet_id()]);
        $ligne = $sl->fetch();
        if ($ligne === false) {
            $erreur = 'Ligne budgétaire inconnue dans ce projet.';
        } elseif ($ligne['nature'] !== 'imputable') {
            $erreur = 'Ligne non imputable : un contrat ne s\'adosse qu\'à une ligne du niveau le plus fin.';
        }
    }

    // L'echeancier theorique se confronte a la quantite budgetee, contrats deja
    // noues compris : deux contrats de huit mois sur une ligne qui en finance huit
    // ne tiennent pas, meme si chacun tient pris isolement.
    if ($erreur === null && $ligne !== null && in_array($unite, UNITES_DENOMBRABLES, true)
        && (string)$ligne['unite'] === $unite && $ligne['quantite_gestion'] !== null) {
        $sq = db()->prepare(
            "SELECT COALESCE(SUM(quantite),0) FROM contrats
              WHERE ligne_id = ? AND projet_id = ? AND statut <> 'clos' AND unite = ?"
        );
        $sq->execute([$ligneId, projet_id(), $unite]);
        $deja = (float)$sq->fetchColumn();
        $reste = round((float)$ligne['quantite_gestion'] - $deja, 2);
        if ($quantite > $reste) {
            $erreur = sprintf('La ligne %s finance %s %s, dont %s déjà engagés par contrat : il en reste %s pour ce contrat.',
                $ligne['code'], rtrim(rtrim(number_format((float)$ligne['quantite_gestion'], 2, ',', ' '), '0'), ','),
                UNITES[$unite], rtrim(rtrim(number_format($deja, 2, ',', ' '), '0'), ','),
                rtrim(rtrim(number_format($reste, 2, ',', ' '), '0'), ','));
        }
    }

    $fichierId = null;
    if ($erreur === null && !empty($_FILES['contrat']['name'])) {
        $up = enregistrer_upload($_FILES['contrat'], 'coffre',
            projet_code() . '-CONTRAT-' . $tiersId . '-' . date('Ymd') . '.pdf', ALLOWED_DOCUMENT, true);
        if (!$up['success']) {
            $erreur = 'Contrat numérisé : ' . $up['error'];
        } else {
            $fichierId = (int)$up['id'];
        }
    }

    if ($erreur === null) {
        $total = round($quantite * $unitaire, 2);
        try {
            db()->prepare(
                'INSERT INTO contrats (projet_id, tiers_id, ligne_id, type, avance_autorisee, part_avance, numero,
                                       fonction, date_debut, date_fin, unite, quantite, montant_unitaire,
                                       montant_total, taux_acompte_defaut, fichier_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([projet_id(), $tiersId, $ligneId, $type, $avance,
                        $partAvance !== '' ? (float)$partAvance : null, $numero ?: null,
                        $fonction, $debut, $fin, $unite, $quantite, $unitaire, $total, $acompte,
                        $fichierId, user_id()]);
            $cid = (int)db()->lastInsertId();
            audit('tiers', 'contrat_cree', 'contrat', $cid,
                TYPES_CONTRAT[$type] . ' · ' . $fonction . ' · ' . $quantite . ' ' . UNITES[$unite]
                . ' × ' . htg($unitaire) . ' = ' . htg($total)
                . ($ligne ? ' · ligne ' . $ligne['code'] : ''));
            flash_set('success', 'Contrat enregistré.');
            redirect(base_path('modules/tiers/contrats.php'));
        } catch (Throwable $ex) {
            error_log('creer contrat: ' . $ex->getMessage());
            $erreur = 'Enregistrement impossible.';
        }
    }
}

$sc = db()->prepare(
    'SELECT c.*, t.nom AS tiers_nom, l.code AS ligne_code, l.libelle AS ligne_libelle, l.quantite_gestion
       FROM contrats c
       JOIN tiers t ON t.id = c.tiers_id
       LEFT JOIN lignes_budgetaires l ON l.id = c.ligne_id
      WHERE c.projet_id = ? ORDER BY t.nom, c.date_debut'
);
$sc->execute([projet_id()]);
$contrats = $sc->fetchAll();

$personnes = db()->query("SELECT id, nom, fonction FROM tiers WHERE actif = 1 AND type IN ('personne','organisation','fournisseur') ORDER BY nom")->fetchAll();
$lignes = array_filter(budget_lignes(), fn($l) => $l['nature'] === 'imputable');

$ongletActif = 'contrats';
page_start('Contrats', 'tiers');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Contrats et conventions</h1>
    <span class="text-muted small"><?= count($contrats) ?> sur <?= e(projet_intitule()) ?></span>
</div>

<?php if ($erreur): ?><div class="alert alert-danger py-2"><i class="bi bi-x-octagon"></i> <?= e($erreur) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text"></i> Contrats en cours</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th>Intervenant</th><th>Fonction</th><th>Ligne</th>
            <th class="text-end">Rémunération</th><th>Période</th><th class="text-end">Échéancier</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($contrats as $c):
            // Echeancier theorique : autant de versements que d'unites denombrables.
            $echeances = in_array($c['unite'], UNITES_DENOMBRABLES, true) ? (int)ceil((float)$c['quantite']) : 1;
            $depasse = $c['quantite_gestion'] !== null && in_array($c['unite'], UNITES_DENOMBRABLES, true)
                       && (float)$c['quantite'] > (float)$c['quantite_gestion'];
        ?>
            <tr>
                <td><a href="<?= e(base_path('modules/tiers/fiche.php?id=' . (int)$c['tiers_id'])) ?>"><?= e($c['tiers_nom']) ?></a>
                    <?php if ($c['type'] !== 'service'): ?><br><span class="badge text-bg-light border"><?= e(TYPES_CONTRAT[$c['type']]) ?></span><?php endif; ?></td>
                <td class="small"><?= e($c['fonction']) ?><?php if ($c['numero']): ?><br><span class="text-muted">n° <?= e($c['numero']) ?></span><?php endif; ?></td>
                <td class="small text-muted"><?= $c['ligne_code'] ? e($c['ligne_code'] . ' ' . $c['ligne_libelle']) : '—' ?></td>
                <td class="text-end"><?= e(htg((float)$c['montant_total'])) ?>
                    <br><small class="text-muted"><?= e(rtrim(rtrim(number_format((float)$c['quantite'], 2, ',', ' '), '0'), ',')) ?>
                        <?= e(UNITES[$c['unite']] ?? $c['unite']) ?> × <?= e(htg((float)$c['montant_unitaire'])) ?></small></td>
                <td class="small text-muted"><?= e(date_fr($c['date_debut'])) ?><br><?= e(date_fr($c['date_fin'])) ?></td>
                <td class="text-end small"><?= $echeances ?> versement<?= $echeances > 1 ? 's' : '' ?>
                    <?php if ($depasse): ?><br><span class="text-danger">au-delà de la quantité budgétée</span><?php endif; ?></td>
                <td class="text-end small text-muted">
                    <?= $c['fichier_id'] ? '<i class="bi bi-paperclip" title="Contrat numérisé au coffre"></i>' : '' ?>
                    <?= $c['avance_autorisee'] ? '<span class="badge text-bg-light border">avance</span>' : '' ?>
                    acompte <?= e(rtrim(rtrim(number_format((float)$c['taux_acompte_defaut'], 2, ',', ' '), '0'), ',')) ?> %
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$contrats): ?><tr><td colspan="7" class="text-muted p-3">Aucun contrat sur ce projet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($peutEcrire): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle"></i> Nouveau contrat</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label small mb-1">Intervenant</label>
                <select class="form-select form-select-sm" name="tiers_id" required>
                    <option value="">—</option>
                    <?php foreach ($personnes as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)($_GET['tiers'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= e($p['nom']) ?><?= $p['fonction'] ? ' — ' . e($p['fonction']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Type</label>
                <select class="form-select form-select-sm" name="type">
                    <?php foreach (TYPES_CONTRAT as $k => $lib): ?>
                    <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1">Ligne budgétaire <span class="text-muted">(facultative pour une convention)</span></label>
                <select class="form-select form-select-sm" name="ligne_id">
                    <option value="">—</option>
                    <?php foreach ($lignes as $code => $l): ?>
                    <option value="<?= (int)$l['id'] ?>"><?= e($code . ' — ' . $l['libelle']) ?>
                        <?= $l['unite'] ? '(' . e(UNITES[$l['unite']]) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Fonction contractuelle</label>
                <input class="form-control form-control-sm" name="fonction" required maxlength="120">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Numéro</label>
                <input class="form-control form-control-sm" name="numero" maxlength="30">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Début</label>
                <input type="date" class="form-control form-control-sm" name="date_debut" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Fin</label>
                <input type="date" class="form-control form-control-sm" name="date_fin" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Unité</label>
                <select class="form-select form-select-sm" name="unite">
                    <?php foreach (UNITES as $u => $lib): ?><option value="<?= e($u) ?>"><?= e($lib) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Quantité</label>
                <input class="form-control form-control-sm text-end" name="quantite" inputmode="decimal" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Montant unitaire brut</label>
                <input class="form-control form-control-sm text-end" name="montant_unitaire" inputmode="decimal" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Acompte %</label>
                <input class="form-control form-control-sm text-end" name="taux_acompte" inputmode="decimal"
                       value="<?= e(param('taux_acompte_defaut_pct', '2')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Contrat numérisé</label>
                <input type="file" class="form-control form-control-sm" name="contrat" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <?php if (param('avances_honoraires', '0') === '1'): ?>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" name="avance_autorisee" id="av" value="1">
                    <label class="form-check-label small" for="av">Avance autorisée</label>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Part avancée %</label>
                <input class="form-control form-control-sm text-end" name="part_avance" inputmode="decimal">
            </div>
            <?php endif; ?>
            <div class="col-12 mt-3">
                <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php page_end();
