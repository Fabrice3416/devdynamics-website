<?php
declare(strict_types=1);

/**
 * Composant stub - affiche un message "Module en cours d'implementation"
 * Utilise par toutes les pages pas encore developpees pour que le portail
 * soit navigable des le deploiement Phase 1.
 *
 * Variables attendues :
 *   $pageTitle (string)
 *   $activeMenu (string)
 *   $moduleName (string) - nom affiche
 *   $phaseRoadmap (string) - reference roadmap (ex: "Phase 5 - Semaines 4-5")
 *   $description (string) - description du module
 *   $allowedRoles (array) - roles autorises (par defaut tous les connectes)
 */

require_once __DIR__ . '/auth.php';

$allowedRoles = $allowedRoles ?? ['administrateur', 'coordinateur', 'comptable'];
check_role($allowedRoles);

$moduleName   = $moduleName   ?? 'Module';
$phaseRoadmap = $phaseRoadmap ?? '';
$description  = $description  ?? '';
$pageTitle    = $pageTitle    ?? $moduleName;
$activeMenu   = $activeMenu   ?? '';

require __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 text-center">
                <i class="bi bi-tools fs-1 text-muted"></i>
                <h2 class="h4 mt-3"><?= e($moduleName) ?></h2>

                <?php if ($phaseRoadmap): ?>
                    <p class="text-muted small mb-3">
                        <span class="badge bg-secondary"><?= e($phaseRoadmap) ?></span>
                    </p>
                <?php endif; ?>

                <?php if ($description): ?>
                    <p class="text-muted"><?= e($description) ?></p>
                <?php endif; ?>

                <div class="alert alert-info text-start mt-4">
                    <strong>Module en cours d implementation.</strong><br>
                    Cette page est prevue dans la feuille de route mais n est pas encore developpee.
                    Le squelette du portail (authentification, BDD, securite, signatures) est operationnel.
                    Voir <code>PLAN_PORTAIL.md</code> a la racine du depot pour le calendrier complet.
                </div>

                <a href="/portail/dashboard.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Retour au tableau de bord
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php';
