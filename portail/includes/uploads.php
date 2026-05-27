<?php
declare(strict_types=1);

/**
 * Gestion securisee des uploads de fichiers.
 *
 * Securite en 5 etapes :
 *   1. Whitelist d'extensions
 *   2. Validation MIME reelle via finfo
 *   3. Verification taille max
 *   4. Renommage securise (hash) - jamais le nom original
 *   5. Compression GD automatique pour JPG/PNG > 2 Mo
 */

require_once __DIR__ . '/functions.php';

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024; // 5 Mo
const UPLOAD_COMPRESS_THRESHOLD = 2 * 1024 * 1024; // 2 Mo

/**
 * Whitelist de types autorises - selon le contexte d'usage.
 */
const ALLOWED_DOCUMENT = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];

const ALLOWED_PDF_ONLY = [
    'pdf' => ['application/pdf'],
];

const ALLOWED_IMAGE_ONLY = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];

/**
 * Traite un upload PHP-natif (depuis $_FILES) et le deplace
 * dans le dossier de destination avec un nom securise.
 *
 * @param array $file       Element de $_FILES['nom_champ']
 * @param string $destDir   Chemin absolu du dossier destination
 * @param array $allowed    Whitelist (ALLOWED_DOCUMENT par defaut)
 * @return array{success:bool, path?:string, error?:string, size?:int, type?:string}
 */
function handle_upload(array $file, string $destDir, array $allowed = ALLOWED_DOCUMENT): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Parametre fichier invalide'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['success' => false, 'error' => 'Aucun fichier transmis'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['success' => false, 'error' => 'Fichier trop volumineux (limite serveur)'];
        default:
            return ['success' => false, 'error' => 'Erreur upload: ' . (int)$file['error']];
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max 5 Mo)'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Upload invalide'];
    }

    // 1) Whitelist extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['success' => false, 'error' => 'Extension non autorisee: ' . $ext];
    }

    // 2) Validation MIME REELLE via finfo (jamais $_FILES['type'] seul)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        return ['success' => false, 'error' => 'Contenu MIME ne correspond pas a l extension'];
    }

    // 3) Creation dossier destination
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        return ['success' => false, 'error' => 'Impossible de creer le dossier destination'];
    }

    // 4) Renommage securise (hash)
    $userId = $_SESSION['user_id'] ?? 0;
    $newName = hash('sha256', uniqid('', true) . $userId . $file['name']) . '.' . $ext;
    // On garde un nom plus court mais toujours unique
    $newName = substr($newName, 0, 24) . '.' . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'error' => 'Echec deplacement fichier'];
    }

    // 5) Compression GD si image > 2 Mo
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && filesize($destPath) > UPLOAD_COMPRESS_THRESHOLD) {
        compress_image($destPath, $ext);
    }

    @chmod($destPath, 0644);

    return [
        'success' => true,
        'path'    => $destPath,
        'size'    => filesize($destPath),
        'type'    => $ext,
    ];
}

/**
 * Compresse une image via GD. Qualite cible : 75.
 */
function compress_image(string $path, string $ext): void
{
    try {
        if (!extension_loaded('gd')) {
            return;
        }
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $img = @imagecreatefromjpeg($path);
            if ($img !== false) {
                @imagejpeg($img, $path, 75);
                imagedestroy($img);
            }
        } elseif ($ext === 'png') {
            $img = @imagecreatefrompng($path);
            if ($img !== false) {
                @imagepng($img, $path, 6); // 0-9
                imagedestroy($img);
            }
        }
    } catch (Throwable $e) {
        error_log('compress_image failed: ' . $e->getMessage());
    }
}

/**
 * Convertit un chemin absolu (storage/...) en chemin relatif a la racine du portail
 * pour stockage en base.
 */
function storage_relative_path(string $absolutePath): string
{
    $base = dirname(__DIR__) . '/';
    if (strpos($absolutePath, $base) === 0) {
        return substr($absolutePath, strlen($base));
    }
    return $absolutePath;
}

/**
 * Retourne le chemin absolu d'un chemin relatif stocke en base.
 */
function storage_absolute_path(string $relativePath): string
{
    return dirname(__DIR__) . '/' . ltrim($relativePath, '/');
}
