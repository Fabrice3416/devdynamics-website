<?php
/**
 * Envoi d'images depuis l'administration
 *
 * POST /api/uploads - Enregistre une image, rend son chemin (admin)
 */

const UPLOAD_MAX_BYTES = 2 * 1024 * 1024;

// Le type est lu dans le contenu du fichier, jamais dans ce que le navigateur annonce.
// SVG est exclu : il peut contenir du code qui s'executerait sur le site.
const UPLOAD_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

/**
 * Rend [extension, null] ou [null, message d'erreur].
 */
function upload_check($path, $size, $errorCode) {
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE || $size > UPLOAD_MAX_BYTES) {
        return [null, "L'image dépasse 2 Mo"];
    }
    if ($errorCode !== UPLOAD_ERR_OK || !is_file($path)) {
        return [null, "L'image n'a pas pu être reçue"];
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!isset(UPLOAD_TYPES[$type]) || @getimagesize($path) === false) {
        return [null, 'Seules les images PNG, JPEG ou WebP sont acceptées'];
    }
    return [UPLOAD_TYPES[$type], null];
}

$router = Router::getInstance();

$router->post('\/uploads', function($params) {
    $file = $_FILES['file'] ?? null;
    if (!$file || !is_string($file['tmp_name'] ?? null)) {
        Response::error('Aucun fichier reçu', 400);
    }

    [$extension, $error] = upload_check($file['tmp_name'], $file['size'], $file['error']);
    if ($error !== null) {
        Response::error($error, 400);
    }

    // Nom aleatoire : celui du fichier d'origine ne sert jamais
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../../uploads/' . $name)) {
        Response::error("L'image n'a pas pu être enregistrée", 500);
    }

    Response::success(['path' => 'uploads/' . $name], 'Image enregistrée', 201);
}, ['adminMiddleware']);
