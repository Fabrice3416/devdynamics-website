<?php
declare(strict_types=1);

/** Noyau - interrupteurs de maintenance des modules (CDC 7.2). Coordinateur seulement. */

require_once __DIR__ . '/../../includes/layout.php';
require_role(['coordinateur']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $module = (string)($_POST['module'] ?? '');
    $etat = (string)($_POST['interrupteur'] ?? '');
    $motif = trim((string)($_POST['motif'] ?? ''));
    if ($module === 'noyau' || !isset(MODULES[$module]) || !in_array($etat, ['actif', 'maintenance'], true)) {
        flash_set('danger', 'Le Noyau ne se ferme pas ; module ou état invalide.');
    } elseif ($etat === 'maintenance' && $motif === '') {
        flash_set('danger', 'Un motif est obligatoire pour fermer un module.');
    } else {
        db()->prepare('UPDATE module_etats SET interrupteur = ?, motif = ? WHERE module = ?')->execute([$etat, $etat === 'maintenance' ? $motif : null, $module]);
        audit('noyau', 'module_interrupteur', 'module', $module, $etat . ($motif ? ' · ' . $motif : ''));
        flash_set('success', 'Module ' . (MODULES[$module][0]) . ' : ' . $etat . '.');
    }
    redirect(base_path('modules/noyau/modules.php'));
}

$etats = db()->query('SELECT * FROM module_etats ORDER BY id')->fetchAll();
$integrite = integrite_triggers();

page_start('Modules', 'noyau');
$ongletActif = 'modules';
require __DIR__ . '/_nav.php';
?>
<div class="row">
    <div class="col-lg-9">
        <p class="text-muted small">L'interrupteur est une mesure technique (module défaillant), distincte de la règle de phase qui ferme des modules parce que le projet est clos. Un module fermé renvoie une page d'indisponibilité ; la consultation, les brouillons et les autres modules continuent. Ordre de rétablissement en cas d'incident : du bas de la carte vers le haut.</p>
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Intégrité de la base</span>
                <span class="badge <?= $integrite['ok'] ? 'badge-module-actif' : 'badge-module-maintenance' ?>"><?= (int)$integrite['presents'] ?> / <?= (int)$integrite['attendus'] ?> garde-fous</span>
            </div>
            <div class="card-body small">
                <?php if ($integrite['ok']): ?>
                    <p class="mb-0">Les huit règles d'immuabilité du cahier des charges sont appliquées par la base
                    elle-même : elles résistent à une erreur de l'application comme à un accès direct par phpMyAdmin.</p>
                <?php else: ?>
                    <p>Ces règles ne sont plus appliquées par la base :</p>
                    <ul>
                        <?php foreach ($integrite['manquants'] as $nom => $regle): ?>
                        <li><?= e($regle) ?> <span class="text-muted">(<code><?= e($nom) ?></code>)</span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mb-0">Importer <code>database/schema_triggers.sql</code> sur la base. Si l'import échoue
                    avec l'erreur 1419, l'hébergeur refuse la création de triggers à un utilisateur sans SUPER :
                    demander au support d'activer <code>log_bin_trust_function_creators</code>. En attendant,
                    l'application applique seules ces règles, sans garantie contre un accès direct à la base.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead><tr><th>Module</th><th>Version</th><th>Dépend de</th><th>État</th><th>Motif</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($etats as $m): $deps = MODULES[$m['module']][1] ?? []; ?>
                <tr>
                    <td><?= e($m['libelle']) ?> <?php if ($m['critique']): ?><span class="badge badge-a-definir">critique</span><?php endif; ?></td>
                    <td class="text-muted small">v<?= e($m['version']) ?></td>
                    <td class="small text-muted"><?= e(implode(', ', array_map(fn($d) => MODULES[$d][0], $deps))) ?></td>
                    <td><span class="badge <?= $m['interrupteur'] === 'actif' ? 'badge-module-actif' : 'badge-module-maintenance' ?>"><?= e($m['interrupteur']) ?></span></td>
                    <td class="small"><?= e($m['motif'] ?? '') ?></td>
                    <td class="text-end">
                        <?php if ($m['module'] !== 'noyau'): ?>
                        <form method="post" class="d-flex gap-1 justify-content-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="module" value="<?= e($m['module']) ?>">
                            <?php if ($m['interrupteur'] === 'actif'): ?>
                                <input type="hidden" name="interrupteur" value="maintenance">
                                <input name="motif" class="form-control form-control-sm" placeholder="Motif de fermeture" style="max-width:220px" required>
                                <button class="btn btn-sm btn-outline-danger">Fermer</button>
                            <?php else: ?>
                                <input type="hidden" name="interrupteur" value="actif">
                                <button class="btn btn-sm btn-outline-success">Rouvrir</button>
                            <?php endif; ?>
                        </form>
                        <?php else: ?><small class="text-muted">toujours actif</small><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php page_end();
