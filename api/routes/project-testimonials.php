<?php
/**
 * Temoignages rattaches aux projets. Rien n'est publie sans validation.
 *
 * GET    /api/project-testimonials/collect/:slug   - Etat de la collecte d'un projet (public)
 * POST   /api/project-testimonials/collect/:slug   - Deposer un temoignage (public)
 * GET    /api/project-testimonials/project/:slug   - Temoignages valides d'un projet (public)
 * GET    /api/project-testimonials/featured        - Selection de l'accueil (public)
 * GET    /api/project-testimonials                 - Tous les temoignages (admin)
 * PUT    /api/project-testimonials/:id             - Valider, refuser, mettre en avant (admin)
 * DELETE /api/project-testimonials/:id             - Supprimer (admin)
 * PUT    /api/projects/:id/testimonials            - Ouvrir, fermer la collecte (admin)
 * GET    /api/project-testimonials/:id/video       - Lire la video envoyee (admin)
 * POST   /api/project-testimonials/:id/video       - Ajouter, remplacer la video (admin)
 * DELETE /api/project-testimonials/:id/video       - Retirer la video (admin)
 *
 * Une video envoyee reste dans un dossier ferme tant que le temoignage n'est pas
 * publie : sur un formulaire ouvert a tous, rien ne doit etre diffuse sans
 * validation. Elle rejoint uploads/videos/ a la publication et en repart si le
 * temoignage est retire.
 */

require_once __DIR__ . '/../utils/RateLimit.php';

const TESTIMONIAL_MAX_PER_HOUR = 10;

const TESTIMONIAL_VIDEO_MAX_BYTES = 100 * 1024 * 1024;
// Plafond des videos en attente : un formulaire public ne doit pas pouvoir remplir l'hebergement
const TESTIMONIAL_VIDEO_PENDING_MAX_BYTES = 2 * 1024 * 1024 * 1024;
const TESTIMONIAL_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/x-m4v' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

function testimonial_video_dir($published, $root = null) {
    return ($root ?? __DIR__ . '/../../uploads') . ($published ? '/videos/' : '/videos-attente/');
}

/**
 * Le type est lu dans le contenu du fichier. Rend [extension, null] ou [null, erreur].
 */
function testimonial_video_check($path, $size, $errorCode) {
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE || $size > TESTIMONIAL_VIDEO_MAX_BYTES) {
        return [null, 'La vidéo dépasse 100 Mo'];
    }
    if ($errorCode !== UPLOAD_ERR_OK || !is_file($path)) {
        return [null, "La vidéo n'a pas pu être reçue. Réessayez."];
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!isset(TESTIMONIAL_VIDEO_TYPES[$type])) {
        return [null, 'Seules les vidéos MP4, WebM ou MOV sont acceptées'];
    }
    return [TESTIMONIAL_VIDEO_TYPES[$type], null];
}

function testimonial_pending_bytes($root = null) {
    $total = 0;
    foreach (glob(testimonial_video_dir(false, $root) . '*.{mp4,webm,mov}', GLOB_BRACE) ?: [] as $file) {
        $total += (int) filesize($file);
    }
    return $total;
}

/**
 * Enregistre la video recue sous un nom aleatoire. Rend [nom, null] ou [null, erreur].
 */
function testimonial_store_video(array $file, $published) {
    if (!is_string($file['tmp_name'] ?? null)) {
        return [null, "La vidéo n'a pas pu être reçue. Réessayez."];
    }
    [$extension, $error] = testimonial_video_check($file['tmp_name'], $file['size'], $file['error']);
    if ($error !== null) {
        return [null, $error];
    }

    $dir = testimonial_video_dir($published);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [null, "La vidéo n'a pas pu être enregistrée"];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        return [null, "La vidéo n'a pas pu être enregistrée"];
    }
    return [$name, null];
}

/**
 * Place le fichier du bon cote selon que le temoignage est publie ou non.
 */
function testimonial_sync_video($name, $published, $root = null) {
    if (!$name) return true;
    $target = testimonial_video_dir($published, $root);
    $source = testimonial_video_dir(!$published, $root) . $name;
    if (is_file($target . $name)) return true;
    if (!is_file($source)) return false;
    if (!is_dir($target) && !mkdir($target, 0755, true)) return false;
    return rename($source, $target . $name);
}

function testimonial_delete_video($name, $root = null) {
    if (!$name) return;
    foreach ([true, false] as $published) {
        $path = testimonial_video_dir($published, $root) . $name;
        if (is_file($path)) unlink($path);
    }
}

/**
 * Le corps d'une requete trop lourde est ecarte par PHP : sans ce controle, le
 * visiteur lirait « Indiquez votre nom » apres avoir tout rempli.
 */
function testimonial_post_too_large() {
    return empty($_POST) && empty($_FILES)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
}

/**
 * Identifiant d'une video YouTube, ou null si le lien n'en est pas une. Seul
 * l'identifiant est garde : le lecteur est construit par le site.
 */
function testimonial_video_id($url) {
    $pattern = '#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\#]*&)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,20})(?:[?&\#/].*)?$#';
    return preg_match($pattern, trim((string) $url), $match) ? $match[1] : null;
}

function testimonial_validate(array $body) {
    $text = fn($key) => is_scalar($body[$key] ?? null) ? trim((string) $body[$key]) : '';

    $name = $text('name');
    $role = $text('role_title');
    $content = $text('content');
    $video = $text('video_url');

    if ($name === '' || mb_strlen($name) > 120) return [null, 'Indiquez votre nom'];
    if (mb_strlen($role) > 160) return [null, 'La fonction est trop longue'];
    if ($content === '' || mb_strlen($content) > 3000) return [null, 'Écrivez votre témoignage (3 000 caractères au plus)'];
    if (empty($body['consent'])) return [null, 'Votre accord pour la publication est nécessaire'];

    $videoId = null;
    if ($video !== '') {
        $videoId = testimonial_video_id($video);
        if ($videoId === null) return [null, 'Le lien de la vidéo doit être un lien YouTube'];
    }

    return [['name' => $name, 'role_title' => $role ?: null, 'content' => $content, 'video_id' => $videoId], null];
}

$router = Router::getInstance();
$db = Database::getInstance();

$router->get('\/project-testimonials/collect/:slug', function($params) use ($db) {
    try {
        $project = $db->fetchOne("SELECT name, testimonials_open FROM projects WHERE slug = ? LIMIT 1", [$params['slug']]);
    } catch (Exception $e) {
        Response::error('Indisponible pour le moment', 500);
    }
    if (!$project) {
        Response::notFound('Projet introuvable');
    }
    Response::success(['project_name' => $project['name'], 'open' => (int) $project['testimonials_open']]);
});

$router->post('\/project-testimonials/collect/:slug', function($params) use ($db) {
    if (testimonial_post_too_large()) {
        Response::error('La vidéo dépasse 100 Mo', 413);
    }
    // Avec une video le formulaire arrive en multipart, sinon en JSON
    $body = $_POST ?: Router::getBody();

    // Piege a robots : un champ invisible que seul un script remplit
    if (!empty($body['website'])) {
        Response::success(null, 'Témoignage reçu', 201);
    }

    [$values, $error] = testimonial_validate($body);
    if ($error !== null) {
        Response::error($error, 400);
    }

    try {
        $project = $db->fetchOne("SELECT id, testimonials_open FROM projects WHERE slug = ? LIMIT 1", [$params['slug']]);
        if (!$project) {
            Response::notFound('Projet introuvable');
        }
        if (!$project['testimonials_open']) {
            Response::error('La collecte de témoignages est fermée pour ce projet', 403);
        }
        if (rate_limited($db, 'testimonial', TESTIMONIAL_MAX_PER_HOUR, 3600)) {
            Response::error('Trop de témoignages envoyés. Réessayez plus tard.', 429);
        }

        $videoFile = null;
        $upload = $_FILES['video'] ?? null;
        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (testimonial_pending_bytes() + (int) $upload['size'] > TESTIMONIAL_VIDEO_PENDING_MAX_BYTES) {
                Response::error("L'envoi de vidéos est momentanément indisponible. Envoyez votre témoignage sans vidéo, ou avec un lien YouTube.", 503);
            }
            [$videoFile, $error] = testimonial_store_video($upload, false);
            if ($error !== null) {
                Response::error($error, 400);
            }
        }

        $db->query(
            "INSERT INTO project_testimonials (project_id, name, role_title, content, video_id, video_file, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')",
            [$project['id'], $values['name'], $values['role_title'], $values['content'], $values['video_id'], $videoFile]
        );
    } catch (Exception $e) {
        // Sans sa ligne en base, le fichier ne serait plus jamais retrouve
        testimonial_delete_video($videoFile ?? null);
        error_log('temoignage : ' . $e->getMessage());
        Response::error("Le témoignage n'a pas pu être enregistré. Réessayez dans un instant.", 500);
    }
    Response::success(null, 'Témoignage reçu', 201);
});

$router->get('\/project-testimonials/project/:slug', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT t.name, t.role_title, t.content, t.video_id, t.video_file
             FROM project_testimonials t JOIN projects p ON p.id = t.project_id
             WHERE p.slug = ? AND p.status = 'published' AND t.status = 'approved'
             ORDER BY t.created_at DESC, t.id DESC",
            [$params['slug']]
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible pour le moment', 500);
    }
});

$router->get('\/project-testimonials/featured', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT t.name, t.role_title, t.content, t.video_id, t.video_file, p.name AS project_name, p.slug AS project_slug
             FROM project_testimonials t JOIN projects p ON p.id = t.project_id
             WHERE p.status = 'published' AND t.status = 'approved' AND t.featured = 1
             ORDER BY t.created_at DESC, t.id DESC LIMIT 3"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible pour le moment', 500);
    }
});

$router->get('\/project-testimonials', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT t.id, t.name, t.role_title, t.content, t.video_id, t.video_file, t.status, t.featured, t.created_at, p.name AS project_name
             FROM project_testimonials t JOIN projects p ON p.id = t.project_id
             ORDER BY t.status = 'pending' DESC, t.created_at DESC, t.id DESC"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->put('\/project-testimonials/:id', function($params) use ($db) {
    $body = Router::getBody();
    $status = $body['status'] ?? '';
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        Response::error('Statut invalide', 400);
    }
    // Un temoignage qui n'est pas valide ne peut pas rester en avant sur l'accueil
    $featured = ($status === 'approved' && !empty($body['featured'])) ? 1 : 0;
    try {
        $row = $db->fetchOne("SELECT video_file FROM project_testimonials WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$row) {
            Response::notFound('Témoignage introuvable');
        }
        if (!testimonial_sync_video($row['video_file'] ?? null, $status === 'approved')) {
            Response::error('Le fichier de la vidéo est introuvable. Retirez la vidéo puis réessayez.', 409);
        }
        $db->query("UPDATE project_testimonials SET status = ?, featured = ? WHERE id = ?", [$status, $featured, $params['id']]);
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Témoignage modifié');
}, ['adminMiddleware']);

$router->delete('\/project-testimonials/:id', function($params) use ($db) {
    try {
        $row = $db->fetchOne("SELECT video_file FROM project_testimonials WHERE id = ? LIMIT 1", [$params['id']]);
        $db->query("DELETE FROM project_testimonials WHERE id = ?", [$params['id']]);
        testimonial_delete_video($row['video_file'] ?? null);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Témoignage supprimé');
}, ['adminMiddleware']);

$router->put('\/projects/:id/testimonials', function($params) use ($db) {
    $open = empty(Router::getBody()['open']) ? 0 : 1;
    try {
        $db->query("UPDATE projects SET testimonials_open = ? WHERE id = ?", [$open, $params['id']]);
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, $open ? 'Collecte ouverte' : 'Collecte fermée');
}, ['adminMiddleware']);

$router->get('\/project-testimonials/:id/video', function($params) use ($db) {
    try {
        $row = $db->fetchOne("SELECT video_file, status FROM project_testimonials WHERE id = ? LIMIT 1", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
    $name = $row['video_file'] ?? null;
    $path = $name ? testimonial_video_dir($row['status'] === 'approved') . $name : null;
    if (!$path || !is_file($path)) {
        Response::notFound('Vidéo introuvable');
    }

    // Par extension : plusieurs types recus partagent la meme, comme video/x-m4v et video/mp4
    $types = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
    header('Content-Type: ' . ($types[pathinfo($name, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}, ['adminMiddleware']);

$router->post('\/project-testimonials/:id/video', function($params) use ($db) {
    if (testimonial_post_too_large()) {
        Response::error('La vidéo dépasse 100 Mo', 413);
    }
    $upload = $_FILES['video'] ?? null;
    if (!$upload) {
        Response::error('Aucune vidéo reçue', 400);
    }
    try {
        $row = $db->fetchOne("SELECT video_file, status FROM project_testimonials WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$row) {
            Response::notFound('Témoignage introuvable');
        }
        [$name, $error] = testimonial_store_video($upload, $row['status'] === 'approved');
        if ($error !== null) {
            Response::error($error, 400);
        }
        $db->query("UPDATE project_testimonials SET video_file = ? WHERE id = ?", [$name, $params['id']]);
        testimonial_delete_video($row['video_file'] ?? null);
    } catch (Exception $e) {
        testimonial_delete_video($name ?? null);
        Response::error('Enregistrement impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Vidéo enregistrée', 201);
}, ['adminMiddleware']);

$router->delete('\/project-testimonials/:id/video', function($params) use ($db) {
    try {
        $row = $db->fetchOne("SELECT video_file FROM project_testimonials WHERE id = ? LIMIT 1", [$params['id']]);
        $db->query("UPDATE project_testimonials SET video_file = NULL WHERE id = ?", [$params['id']]);
        testimonial_delete_video($row['video_file'] ?? null);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Vidéo retirée');
}, ['adminMiddleware']);
