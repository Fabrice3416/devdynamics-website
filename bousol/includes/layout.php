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
    $projets = projets_accessibles();
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
<nav class="navbar navbar-expand-xl navbar-dark bousol-nav">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= e(base_path('dashboard.php')) ?>">
            <i class="bi bi-compass"></i> Bousòl
        </a>

        <?php if (projet_id() !== null): ?>
        <!-- Le projet et sa phase vont ensemble : la phase qualifie le projet, elle ne flotte pas au milieu des modules. -->
        <div class="dropdown bousol-contexte">
            <button class="btn bousol-projet dropdown-toggle" data-bs-toggle="dropdown" title="Projet courant">
                <span class="bousol-projet-txt">
                <span class="code"><?= e(projet_code()) ?></span>
                <span class="nom"><?= e(projet_intitule()) ?></span>
                <span class="phase"><?= e(match ($phase) {
                    'projet_actif' => 'Projet actif', 'regularisation' => 'Régularisation',
                    'post_cloture' => 'Suivi post-clôture', default => 'Non initialisé' }) ?><?= $moisCourant ? ' · M' . str_pad((string)$moisCourant, 2, '0', STR_PAD_LEFT) : '' ?></span>
                </span>
            </button>
            <ul class="dropdown-menu">
                <li><h6 class="dropdown-header">Changer de projet</h6></li>
                <?php foreach ($projets as $pr): ?>
                <li><a class="dropdown-item <?= (int)$pr['id'] === projet_id() ? 'active' : '' ?>"
                       href="<?= e(base_path('projets.php?id=' . (int)$pr['id'])) ?>">
                    <?= e($pr['code']) ?> — <?= e($pr['intitule']) ?>
                    <?php if (!empty($pr['role'])): ?><small class="opacity-75">· <?= e(ROLES_LIBELLES[$pr['role']] ?? $pr['role']) ?></small><?php endif; ?>
                </a></li>
                <?php endforeach; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= e(base_path('projets.php')) ?>">Tous les projets</a></li>
            </ul>
        </div>
        <?php endif; ?>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <?php
                $menus = projet_id() === null ? [] : [
                    'dashboard'    => ['Tableau de bord', 'dashboard.php', 'bi-speedometer2'],
                    'depenses'     => ['Dépenses',        'modules/depenses/', 'bi-receipt'],
                    'comptes'      => ['Comptes',         'modules/comptes/', 'bi-bank'],
                    'remuneration' => ['Rémunération',    'modules/remuneration/', 'bi-person-badge'],
                    'activites'    => ['Activités',       'modules/activites/', 'bi-diagram-3'],
                    'restitution'  => ['Restitution',     'modules/restitution/', 'bi-file-earmark-text'],
                    'financement'  => ['Financement',     'modules/financement/', 'bi-cash-stack'],
                    // Les deux referentiels ferment la barre : on les consulte, on n'y saisit
                    // pas d'ecriture. La cle vaut le code du module, sinon l'onglet ne
                    // s'allume jamais - page_start() passe 'tiers' et 'budget'.
                    'budget'       => ['Budget',          'modules/budget/', 'bi-list-nested'],
                    'tiers'        => ['Référentiels',    'modules/tiers/', 'bi-collection'],
                ];
                foreach ($menus as $key => [$lib, $href, $ico]): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $menuActif === $key ? 'active' : '' ?>" href="<?= e(base_path($href)) ?>">
                            <i class="bi <?= $ico ?>"></i> <?= e($lib) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Tout ce qui n'est pas un module de travail se range ici : la barre reste lisible. -->
            <ul class="navbar-nav align-items-xl-center">
                <li class="nav-item dropdown">
                    <a class="nav-link bousol-compte dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-xl-inline"><?= e(user_nom()) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="px-3 py-2">
                            <div class="fw-semibold"><?= e(user_nom()) ?></div>
                            <small class="text-muted"><?= e(user_role() ? (ROLES_LIBELLES[user_role()] ?? user_role()) : (user_est_admin_outil() ? ADMIN_OUTIL_LIBELLE : 'sans rôle')) ?></small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(base_path('profil.php')) ?>"><i class="bi bi-person"></i> Profil et spécimen</a></li>
                        <?php if (projet_id() !== null): ?>
                        <li><a class="dropdown-item" href="<?= e(base_path('modules/signature/')) ?>"><i class="bi bi-pen"></i> File de signature</a></li>
                        <?php endif; ?>
                        <?php if (user_role() === 'coordinateur'): ?>
                        <li><a class="dropdown-item <?= $menuActif === 'noyau' ? 'active' : '' ?>" href="<?= e(base_path('modules/noyau/')) ?>"><i class="bi bi-gear"></i> Paramétrage du projet</a></li>
                        <?php endif; ?>
                        <?php if (user_est_admin_outil()): ?>
                        <li><a class="dropdown-item <?= $menuActif === 'administration' ? 'active' : '' ?>" href="<?= e(base_path('modules/noyau/projets.php')) ?>"><i class="bi bi-diagram-2"></i> Administration de l'outil</a></li>
                        <?php endif; ?>
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
        &copy; <?= date('Y') ?> DÉVELOPPEMENT ET DYNAMISME
        <?php if (projet_id() !== null): ?>
            &middot; <?= e(projet_intitule()) ?>
            <?php if ($n = param('numero_contrat')): ?> &middot; Convention <?= e($n) ?><?php endif; ?>
        <?php endif; ?>
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
