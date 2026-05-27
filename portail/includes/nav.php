<?php
declare(strict_types=1);

/**
 * Barre de navigation principale - role-aware.
 */

$role = user_role();
?>
<nav class="navbar navbar-expand-lg navbar-dark portail-navbar">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="/portail/dashboard.php">
            <i class="bi bi-shield-lock-fill me-1"></i>
            DEVDYNAMICS / ACP
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>" href="/portail/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Tableau de bord
                    </a>
                </li>

                <?php if (in_array($role, ['administrateur', 'coordinateur'], true)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= $activeMenu === 'admin' ? 'active' : '' ?>" href="#" id="navAdmin" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-folder-fill"></i> Administration
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navAdmin">
                        <li><a class="dropdown-item" href="/portail/admin/contrats.php">Contrats</a></li>
                        <li><a class="dropdown-item" href="/portail/admin/tcd.php">TCD Devis</a></li>
                        <li><a class="dropdown-item" href="/portail/admin/bon_commande.php">Bons de Commande</a></li>
                        <li><a class="dropdown-item" href="/portail/admin/bon_reception.php">Bons de Reception</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/portail/admin/candidatures.php">Candidatures</a></li>
                        <li><a class="dropdown-item" href="/portail/admin/livrables.php">Livrables</a></li>
                        <li><a class="dropdown-item" href="/portail/admin/partenaires.php">Partenaires (FECP)</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (in_array($role, ['administrateur', 'coordinateur', 'comptable'], true)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= $activeMenu === 'compta' ? 'active' : '' ?>" href="#" id="navCompta" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-cash-coin"></i> Comptabilite
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navCompta">
                        <li><a class="dropdown-item" href="/portail/compta/f01.php">F01 - Imputations</a></li>
                        <li><a class="dropdown-item" href="/portail/compta/f02.php">F02 - Decaissements</a></li>
                        <li><a class="dropdown-item" href="/portail/compta/asf.php">ASF - Attestations</a></li>
                        <li><a class="dropdown-item" href="/portail/compta/frp.php">FRP - Reglements</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/portail/compta/journal.php">Journal des depenses</a></li>
                        <li><a class="dropdown-item" href="/portail/compta/budget.php">Suivi budgetaire</a></li>
                        <li><a class="dropdown-item" href="/portail/compta/plan_decaissement.php">Plan Decaissement (PDP)</a></li>
                        <li><a class="dropdown-item" href="/portail/compta/rapprochement.php">Rapprochement bancaire</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/portail/compta/petite_caisse.php">Petite Caisse</a></li>
                        <?php if ($role === 'administrateur'): ?>
                        <li><a class="dropdown-item" href="/portail/compta/retroactif.php">Saisie retroactive</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (in_array($role, ['administrateur', 'coordinateur'], true)): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeMenu === 'reporting' ? 'active' : '' ?>" href="/portail/reporting/rapports.php">
                        <i class="bi bi-file-earmark-bar-graph"></i> Reporting
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navUser" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                        <?= e(user_nom() ?? '') ?>
                        <span class="badge bg-light text-dark ms-1"><?= e(ucfirst((string)$role)) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navUser">
                        <li><a class="dropdown-item" href="/portail/profil.php"><i class="bi bi-person"></i> Mon profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="/portail/logout.php" class="px-3 m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-link p-0 text-decoration-none">
                                    <i class="bi bi-box-arrow-right"></i> Deconnexion
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
