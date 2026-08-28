<?php
declare(strict_types=1);

/**
 * Comptes - journal des ecritures.
 *
 * L'ecriture porte le module d'origine et l'identifiant de l'objet en valeurs, ce
 * qui maintient Comptes ignorant des modules qui l'appellent (CDC 8.3) : le journal
 * les affiche tels quels, sans jointure vers eux.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/comptes.php';
require_projet();
require_module('comptes');

$liste = ecritures();
$ongletActif = 'journal';
page_start('Journal des écritures', 'comptes');
require __DIR__ . '/_nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Journal des écritures</h1>
    <span class="text-muted small"><?= count($liste) ?> écritures · partie double allégée</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead><tr class="small text-muted">
            <th style="width:7rem">Date</th><th>Libellé</th><th>Compte</th>
            <th class="text-end" style="width:9rem">Débit</th><th class="text-end" style="width:9rem">Crédit</th>
        </tr></thead>
        <tbody>
        <?php foreach ($liste as $e): $mvts = mouvements_ecriture((int)$e['id']); ?>
        <tr class="fw-semibold">
            <td><?= e(date_fr($e['date'])) ?></td>
            <td colspan="4"><?= e($e['libelle']) ?>
                <span class="badge text-bg-light border"><?= e($e['type']) ?></span>
                <small class="text-muted">· <?= e($e['origine_module'] . ':' . $e['origine_ref']) ?></small></td>
        </tr>
        <?php foreach ($mvts as $m): ?>
        <tr>
            <td></td>
            <td class="small text-muted" style="padding-left:1.5rem"><?= $m['tiers_nom'] ? e($m['tiers_nom']) : '' ?>
                <?= $m['depense_reportee'] ? '<span class="badge text-bg-light border">dépense reportée</span>' : '' ?></td>
            <td class="small"><?= e($m['code']) ?> <span class="text-muted"><?= e($m['libelle']) ?></span></td>
            <td class="text-end"><?= $m['sens'] === 'D' ? e(htg((float)$m['montant'])) : '' ?></td>
            <td class="text-end"><?= $m['sens'] === 'C' ? e(htg((float)$m['montant'])) : '' ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
        <?php if (!$liste): ?><tr><td colspan="5" class="text-muted p-3">Aucune écriture. Le journal se remplit à l'exécution des règlements.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php page_end();
