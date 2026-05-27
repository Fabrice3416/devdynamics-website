<?php
declare(strict_types=1);

/**
 * Header HTML commun a toutes les pages authentifiees du portail.
 * A inclure APRES auth.php (qui demarre la session).
 *
 * Variables attendues :
 *   $pageTitle (string)  : titre de la page
 *   $activeMenu (string) : cle du menu actif (dashboard, admin, compta, reporting, profil)
 */

$pageTitle  = $pageTitle  ?? 'Portail DEVDYNAMICS / ACP';
$activeMenu = $activeMenu ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> &middot; Portail DEVDYNAMICS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/portail/assets/css/portail.css?v=1">
</head>
<body class="portail-app">
<?php require __DIR__ . '/nav.php'; ?>

<main class="container-fluid py-4 portail-main">
    <?php
    $flashes = flash_get();
    foreach ($flashes as $f):
        $type = in_array($f['type'], ['success','danger','warning','info'], true) ? $f['type'] : 'info';
    ?>
        <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
