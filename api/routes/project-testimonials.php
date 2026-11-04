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
 */

require_once __DIR__ . '/../utils/RateLimit.php';

const TESTIMONIAL_MAX_PER_HOUR = 10;

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
    $body = Router::getBody();

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
        $db->query(
            "INSERT INTO project_testimonials (project_id, name, role_title, content, video_id, status)
             VALUES (?, ?, ?, ?, ?, 'pending')",
            [$project['id'], $values['name'], $values['role_title'], $values['content'], $values['video_id']]
        );
    } catch (Exception $e) {
        error_log('temoignage : ' . $e->getMessage());
        Response::error("Le témoignage n'a pas pu être enregistré. Réessayez dans un instant.", 500);
    }
    Response::success(null, 'Témoignage reçu', 201);
});

$router->get('\/project-testimonials/project/:slug', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT t.name, t.role_title, t.content, t.video_id
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
            "SELECT t.name, t.role_title, t.content, t.video_id, p.name AS project_name, p.slug AS project_slug
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
            "SELECT t.id, t.name, t.role_title, t.content, t.video_id, t.status, t.featured, t.created_at, p.name AS project_name
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
        $db->query("UPDATE project_testimonials SET status = ?, featured = ? WHERE id = ?", [$status, $featured, $params['id']]);
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Témoignage modifié');
}, ['adminMiddleware']);

$router->delete('\/project-testimonials/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM project_testimonials WHERE id = ?", [$params['id']]);
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
