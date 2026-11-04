<?php
/**
 * Partenaires
 *
 * GET    /api/partners        - Partenaires visibles (public)
 * GET    /api/partners/all    - Tous les partenaires (admin)
 * POST   /api/partners        - Ajouter (admin)
 * PUT    /api/partners/order  - Enregistrer l'ordre (admin)
 * PUT    /api/partners/:id    - Modifier (admin)
 * DELETE /api/partners/:id    - Supprimer (admin)
 */

function partner_validate(array $body) {
    $name = is_scalar($body['name'] ?? null) ? trim((string) $body['name']) : '';
    if ($name === '' || mb_strlen($name) > 200) {
        return [null, 'Le nom du partenaire est obligatoire'];
    }
    $logo = is_scalar($body['logo_url'] ?? null) ? trim((string) $body['logo_url']) : '';
    if ($logo !== '' && !preg_match('#^uploads/[A-Za-z0-9._-]+$#', $logo)) {
        return [null, 'Logo invalide : envoyez une image depuis ce formulaire'];
    }
    return [['name' => $name, 'logo_url' => $logo ?: null, 'is_active' => empty($body['is_active']) ? 0 : 1], null];
}

$router = Router::getInstance();
$db = Database::getInstance();

$router->get('\/partners', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT name, logo_url FROM partners WHERE is_active = 1 ORDER BY position, name"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible pour le moment', 500);
    }
});

$router->get('\/partners/all', function($params) use ($db) {
    try {
        Response::success($db->fetchAll("SELECT id, name, logo_url, is_active, position FROM partners ORDER BY position, name"));
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->post('\/partners', function($params) use ($db) {
    [$values, $error] = partner_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }
    try {
        $last = $db->fetchOne("SELECT COALESCE(MAX(position), -1) AS last_position FROM partners");
        $db->query(
            "INSERT INTO partners (name, logo_url, is_active, position) VALUES (?, ?, ?, ?)",
            [$values['name'], $values['logo_url'], $values['is_active'], (int) ($last['last_position'] ?? -1) + 1]
        );
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            Response::error('Ce partenaire existe déjà', 409);
        }
        Response::error('Création impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Partenaire ajouté', 201);
}, ['adminMiddleware']);

$router->put('\/partners/order', function($params) use ($db) {
    $ids = Router::getBody()['ids'] ?? null;
    if (!is_array($ids)) {
        Response::error('Ordre invalide', 400);
    }
    try {
        foreach (array_values($ids) as $position => $id) {
            $db->query("UPDATE partners SET position = ? WHERE id = ?", [$position, (int) $id]);
        }
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Ordre enregistré');
}, ['adminMiddleware']);

$router->put('\/partners/:id', function($params) use ($db) {
    [$values, $error] = partner_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }
    try {
        $db->query(
            "UPDATE partners SET name = ?, logo_url = ?, is_active = ? WHERE id = ?",
            array_merge(array_values($values), [$params['id']])
        );
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            Response::error('Ce partenaire existe déjà', 409);
        }
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Partenaire modifié');
}, ['adminMiddleware']);

$router->delete('\/partners/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM partners WHERE id = ?", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Partenaire supprimé');
}, ['adminMiddleware']);
