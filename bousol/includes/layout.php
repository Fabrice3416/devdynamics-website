<?php
declare(strict_types=1);

/**
 * Gabarit HTML des pages authentifiees.
 * Usage : page_start('Titre', 'menu'); ... page_end();
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calendrier.php';
require_once __DIR__ . '/referentiels.php';

function page_start(string $titre, string $menuActif = ''): void
{
    $phase = phase_code();
    $moisCourant = mois_projet();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titre) ?> &middot; Bousòl</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_path('assets/css/bousol.css')) ?>?v=1">
</head>
<body class="bousol-app">
<nav class="navbar navbar-expand-lg navbar-dark bousol-nav">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= e(base_path('dashboard.php')) ?>">
            <i class="bi bi-compass"></i> Bousòl
            <small class="fw-normal opacity-75 ms-2">KèsKlè</small>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <?php
                $menus = [
                    'dashboard'    => ['Tableau de bord', 'dashboard.php', 'bi-speedometer2'],
                    'depenses'     => ['Dépenses',        'modules/depenses/', 'bi-receipt'],
                    'comptes'      => ['Comptes',         'modules/comptes/', 'bi-bank'],
                    'remuneration' => ['Rémunération',    'modules/remuneration/', 'bi-person-badge'],
                    'activites'    => ['Activités',       'modules/activites/', 'bi-diagram-3'],
                    'restitution'  => ['Restitution',     'modules/restitution/', 'bi-file-earmark-text'],
                    'financement'  => ['Financement',     'modules/financement/', 'bi-cash-stack'],
                    'referentiels' => ['Référentiels',    'modules/tiers/', 'bi-collection'],
                ];
                foreach ($menus as $key => [$lib, $href, $ico]): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $menuActif === $key ? 'active' : '' ?>" href="<?= e(base_path($href)) ?>">
                            <i class="bi <?= $ico ?>"></i> <?= e($lib) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item me-lg-3">
                    <span class="badge bousol-phase" title="Phase courante">
                        <?= e(match ($phase) { 'projet_actif' => 'Projet actif', 'regularisation' => 'Régularisation', 'post_cloture' => 'Suivi post-clôture', default => 'Non initialisé' }) ?>
                        <?= $moisCourant ? ' · M' . str_pad((string)$moisCourant, 2, '0', STR_PAD_LEFT) : '' ?>
                    </span>
                </li>
                <?php if (user_role() === 'coordinateur'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $menuActif === 'noyau' ? 'active' : '' ?>" href="<?= e(base_path('modules/noyau/')) ?>"><i class="bi bi-gear"></i> Paramétrage</a>
                </li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= e(user_nom()) ?>
                        <small class="opacity-75">(<?= e(ROLES_LIBELLES[user_role()] ?? user_role()) ?>)</small>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(base_path('profil.php')) ?>"><i class="bi bi-person"></i> Profil et spécimen</a></li>
                        <li><a class="dropdown-item" href="<?= e(base_path('modules/signature/')) ?>"><i class="bi bi-pen"></i> File de signature</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(base_path('logout.php')) ?>"><i class="bi bi-box-arrow-right"></i> Déconnexion</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid py-4 bousol-main">
    <?php foreach (flash_get() as $f):
        $t = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info'; ?>
        <div class="alert alert-<?= $t ?> alert-dismissible fade show" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    <?php endforeach;
}

function page_end(): void
{
    ?>
</main>
<footer class="bousol-footer text-center py-3 text-muted">
    <small>
        &copy; <?= date('Y') ?> DÉVELOPPEMENT ET DYNAMISME &middot; Projet KèsKlè &middot; PAIESC / Union européenne
        <?php if ($n = param('numero_contrat')): ?> &middot; Contrat <?= e($n) ?><?php endif; ?>
        &middot; Session expire dans <span id="session-countdown" data-ttl="<?= (int)(config()['app']['session_ttl'] ?? 3600) ?>">--:--</span>
    </small>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(base_path('assets/js/bousol.js')) ?>?v=1"></script>
</body>
</html>
    <?php
}

/** Page "module en construction" partagee par les modules non encore livres. */
function page_module_stub(string $module, string $phaseLivraison, array $contenu): void
{
    $lib = MODULES[$module][0] ?? $module;
    page_start($lib, $module);
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1"><i class="bi bi-cone-striped text-warning"></i> Module <?= e($lib) ?></h1>
                    <p class="text-muted">Livraison prévue : <?= e($phaseLivraison) ?>.</p>
                    <ul class="mb-0">
                        <?php foreach ($contenu as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php
    page_end();
}
