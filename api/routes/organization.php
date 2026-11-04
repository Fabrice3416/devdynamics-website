<?php
/**
 * Organisation et fondateurs
 *
 * GET    /api/organization    - Coordonnees, mission et fondateurs (public)
 * PUT    /api/organization    - Modifier (admin)
 * POST   /api/founders        - Ajouter un fondateur (admin)
 * PUT    /api/founders/:id    - Modifier un fondateur (admin)
 * DELETE /api/founders/:id    - Supprimer un fondateur (admin)
 */

function organization_validate(array $body) {
    $text = fn($key) => is_scalar($body[$key] ?? null) ? trim((string) $body[$key]) : '';

    $name = $text('name');
    $email = $text('email');
    $phone = $text('phone');
    if ($name === '' || mb_strlen($name) > 255) return [null, 'Le nom est obligatoire'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide'];
    if (mb_strlen($phone) > 20) return [null, 'Le numéro de téléphone est trop long'];

    return [[
        'name' => $name,
        'email' => $email,
        'phone' => $phone ?: null,
        'address' => $text('address') ?: null,
        'mission' => $text('mission') ?: null,
        'description' => $text('description') ?: null,
    ], null];
}

function founder_validate(array $body) {
    $text = fn($key) => is_scalar($body[$key] ?? null) ? trim((string) $body[$key]) : '';

    $name = $text('name');
    $role = $text('role');
    if ($name === '' || mb_strlen($name) > 255) return [null, 'Le nom est obligatoire'];
    if ($role === '' || mb_strlen($role) > 255) return [null, 'La fonction est obligatoire'];

    $image = $text('image_url');
    if ($image !== '' && !preg_match('#^uploads/[A-Za-z0-9._-]+$#', $image)) return [null, 'Image invalide'];

    $position = filter_var($body['order_position'] ?? 0, FILTER_VALIDATE_INT);
    return [[
        'name' => $name,
        'role' => $role,
        'image_url' => $image ?: null,
        'order_position' => $position === false ? 0 : $position,
    ], null];
}

$router = Router::getInstance();
$db = Database::getInstance();

$router->get('\/organization', function($params) use ($db) {
    try {
        $organization = $db->fetchOne(
            "SELECT name, description, mission, email, phone, address FROM organization_info ORDER BY id LIMIT 1"
        );
        if (!$organization) {
            Response::notFound('Organisation introuvable');
        }
        $organization['founders'] = $db->fetchAll(
            "SELECT id, name, role, image_url, order_position FROM founders ORDER BY order_position, id"
        );
    } catch (Exception $e) {
        Response::error('Lecture impossible pour le moment', 500);
    }
    Response::success($organization);
});

$router->put('\/organization', function($params) use ($db) {
    [$values, $error] = organization_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }
    $assignments = implode(', ', array_map(fn($column) => "{$column} = ?", array_keys($values)));
    try {
        // Une seule ligne fait foi : la premiere, celle que lit le site
        $row = $db->fetchOne("SELECT id FROM organization_info ORDER BY id LIMIT 1");
        if ($row) {
            $db->query("UPDATE organization_info SET {$assignments} WHERE id = ?", array_merge(array_values($values), [$row['id']]));
        } else {
            $columns = array_keys($values);
            $db->query(
                "INSERT INTO organization_info (" . implode(', ', $columns) . ") VALUES (?" . str_repeat(', ?', count($columns) - 1) . ")",
                array_values($values)
            );
        }
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Organisation modifiée');
}, ['adminMiddleware']);

$router->post('\/founders', function($params) use ($db) {
    [$values, $error] = founder_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }
    try {
        $db->query("INSERT INTO founders (name, role, image_url, order_position) VALUES (?, ?, ?, ?)", array_values($values));
    } catch (Exception $e) {
        Response::error('Création impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Fondateur ajouté', 201);
}, ['adminMiddleware']);

$router->put('\/founders/:id', function($params) use ($db) {
    [$values, $error] = founder_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }
    try {
        $db->query(
            "UPDATE founders SET name = ?, role = ?, image_url = ?, order_position = ? WHERE id = ?",
            array_merge(array_values($values), [$params['id']])
        );
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Fondateur modifié');
}, ['adminMiddleware']);

$router->delete('\/founders/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM founders WHERE id = ?", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Fondateur supprimé');
}, ['adminMiddleware']);
