<?php
declare(strict_types=1);

/**
 * Streaming securise des fichiers stockes dans portail/storage/.
 * Verifie la session ou un token avant de servir le fichier.
 *
 * Usage :
 *   /portail/pdf/serve.php?path=signatures/users/user_1.png&type=sig
 *   /portail/pdf/serve.php?id=42&type=dossier  (TODO: par ID via table)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$path = (string)($_GET['path'] ?? '');
$type = (string)($_GET['type'] ?? '');

// Empeche path traversal
if (strpos($path, '..') !== false || strpos($path, "\0") !== false || $path === '') {
    http_response_code(404);
    exit('404');
}

$abs = storage_absolute_path('storage/' . ltrim($path, '/'));
if (!is_file($abs)) {
    http_response_code(404);
    exit('404 - fichier introuvable');
}

// Controle d'acces par type (au moins basique - a affiner phase 2+)
switch ($type) {
    case 'sig':
        // Tous les utilisateurs connectes peuvent voir une signature
        break;
    case 'pdf':
    case 'dossier':
        check_role(['administrateur', 'coordinateur', 'comptable']);
        break;
    default:
        http_response_code(400);
        exit('400 - type inconnu');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($abs) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, no-cache');
// Affichage inline pour les images, attachment pour les PDF
if (strpos($mime, 'image/') !== 0) {
    header('Content-Disposition: inline; filename="' . basename($abs) . '"');
}

readfile($abs);
exit;
