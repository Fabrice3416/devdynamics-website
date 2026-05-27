<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/CaisseModel.php';
check_role(['administrateur', 'coordinateur']);

$cfg = config();
$fonds = (float)$cfg['app']['caisse_fonds'];
$solde = CaisseModel::solde();
$totalDepenses = $fonds - $solde;

$pageTitle = 'Rapprochement Petite Caisse';
$activeMenu = 'compta';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-3"><i class="bi bi-clipboard-data"></i> Rapprochement Petite Caisse</h1>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h6 class="text-uppercase small text-muted mb-3">Regle d'integrite</h6>
        <p class="mb-0">
            <strong>Especes en caisse</strong> + <strong>total reçus non renfloues</strong>
            doit etre egal a <strong><?= format_htg($fonds) ?></strong> en permanence.
        </p>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>Etat de la caisse</strong></div>
    <div class="card-body">
        <table class="table mb-0">
            <tr>
                <th>Fonds Imprest (constant)</th>
                <td class="text-end font-monospace"><?= format_htg($fonds) ?></td>
            </tr>
            <tr>
                <th>- Total reçus non renfloues</th>
                <td class="text-end font-monospace text-danger">- <?= format_htg($totalDepenses) ?></td>
            </tr>
            <tr class="table-info">
                <th>= Especes en caisse attendues</th>
                <td class="text-end font-monospace fw-bold"><?= format_htg($solde) ?></td>
            </tr>
        </table>
        <hr>
        <p class="text-muted small">
            Pour le rapprochement physique : compter les especes effectivement presentes dans la caisse,
            comparer avec le montant ci-dessus. Si ecart > 0, signaler au Coordinateur immediatement.
        </p>
        <p class="text-muted small mb-0">
            Le rapprochement detaille est imprime mensuellement avec le journal Petite Caisse.
        </p>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php';
