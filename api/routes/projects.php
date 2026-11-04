<?php
/**
 * Projets — les realisations du site, auxquelles se rattachent formulaires et temoignages
 *
 * GET    /api/projects        - Projets publies (public)
 * GET    /api/projects/all    - Tous les projets (admin)
 * GET    /api/projects/:slug  - Fiche d'un projet publie (public)
 * POST   /api/projects        - Creer un projet (admin)
 * PUT    /api/projects/:id    - Modifier un projet (admin)
 * DELETE /api/projects/:id    - Supprimer un projet (admin)
 */

const PROJECT_TYPES = ['formation', 'produit', 'mission', 'autre'];
const PROJECT_STATUSES = ['draft', 'published'];

// Colonnes visibles du public : ni brouillon ni date technique
const PROJECT_PUBLIC_COLUMNS = 'id, slug, name, type, start_date, end_date, period_label, summary, description, participants, speakers, external_url, image_url';

function project_slug($name) {
    $text = strtr((string) $name, [
        'à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i',
        'ô'=>'o','ò'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y','œ'=>'oe','æ'=>'ae',
        'À'=>'a','Â'=>'a','Ç'=>'c','É'=>'e','È'=>'e','Ê'=>'e','Î'=>'i','Ï'=>'i','Ô'=>'o','Ò'=>'o','Ù'=>'u','Û'=>'u',
    ]);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    return substr($slug, 0, 100);
}

function project_date($value) {
    $value = trim((string) ($value ?? ''));
    if ($value === '') return [null, true];
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? [$value, true] : [null, false];
}

/**
 * Controle la saisie. Rend [valeurs, null] ou [null, message d'erreur].
 */
function project_validate(array $body) {
    $text = function ($key, $max) use ($body) {
        $value = $body[$key] ?? '';
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    };
    $orNull = fn($value) => $value === '' ? null : $value;

    $name = $text('name', 200);
    if ($name === '' || project_slug($name) === '') {
        return [null, 'Le nom du projet est obligatoire'];
    }
    $summary = $text('summary', 400);
    if ($summary === '') {
        return [null, 'Le résumé est obligatoire'];
    }
    if (!in_array($body['type'] ?? 'autre', PROJECT_TYPES, true)) {
        return [null, 'Type de projet invalide'];
    }
    if (!in_array($body['status'] ?? 'draft', PROJECT_STATUSES, true)) {
        return [null, 'Statut invalide'];
    }

    [$start, $startOk] = project_date($body['start_date'] ?? null);
    [$end, $endOk] = project_date($body['end_date'] ?? null);
    if (!$startOk || !$endOk) {
        return [null, 'Date invalide'];
    }
    if ($start && $end && $end < $start) {
        return [null, 'La fin ne peut pas précéder le début'];
    }

    $participants = null;
    if (($body['participants'] ?? '') !== '' && ($body['participants'] ?? null) !== null) {
        $participants = filter_var($body['participants'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($participants === false) {
            return [null, 'Le nombre de participants doit être un entier positif'];
        }
    }

    // Ces deux valeurs finissent dans href et src : javascript: ne doit pas passer
    $externalUrl = $text('external_url', 500);
    if ($externalUrl !== '' && !preg_match('#^https?://[^\s"\'<>]+$#i', $externalUrl)) {
        return [null, 'Le lien externe doit commencer par http:// ou https://'];
    }
    $imageUrl = $text('image_url', 500);
    if ($imageUrl !== '' && !preg_match('#^uploads/[A-Za-z0-9._-]+$#', $imageUrl)) {
        return [null, 'Image invalide'];
    }

    return [[
        'name' => $name,
        'type' => $body['type'] ?? 'autre',
        'status' => $body['status'] ?? 'draft',
        'start_date' => $start,
        'end_date' => $end,
        'period_label' => $orNull($text('period_label', 120)),
        'summary' => $summary,
        'description' => $orNull($text('description', 20000)),
        'participants' => $participants,
        'speakers' => $orNull($text('speakers', 255)),
        'external_url' => $orNull($externalUrl),
        'image_url' => $orNull($imageUrl),
    ], null];
}

$router = Router::getInstance();
$db = Database::getInstance();

$router->get('\/projects', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT " . PROJECT_PUBLIC_COLUMNS . " FROM projects
             WHERE status = 'published'
             ORDER BY start_date IS NULL DESC, start_date DESC, name"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible pour le moment', 500);
    }
});

$router->get('\/projects/all', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT * FROM projects ORDER BY start_date IS NULL DESC, start_date DESC, name"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->get('\/projects/:slug', function($params) use ($db) {
    try {
        $project = $db->fetchOne(
            "SELECT " . PROJECT_PUBLIC_COLUMNS . " FROM projects WHERE slug = ? AND status = 'published' LIMIT 1",
            [$params['slug']]
        );
    } catch (Exception $e) {
        Response::error('Lecture impossible pour le moment', 500);
    }
    if (!$project) {
        Response::notFound('Projet introuvable');
    }
    Response::success($project);
});

$router->post('\/projects', function($params) use ($db) {
    [$values, $error] = project_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }

    // L'adresse courte est fixee a la creation : un lien deja partage ne doit pas casser
    $base = project_slug($values['name']);
    $columns = array_keys($values);
    $sql = "INSERT INTO projects (slug, " . implode(', ', $columns) . ")
            VALUES (?" . str_repeat(', ?', count($columns)) . ")";

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $slug = $attempt === 1 ? $base : "{$base}-{$attempt}";
        try {
            $db->query($sql, array_merge([$slug], array_values($values)));
            Response::success(['slug' => $slug], 'Projet créé', 201);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                Response::error('Création impossible : ' . $e->getMessage(), 500);
            }
        }
    }
    Response::error('Un projet porte déjà ce nom', 409);
}, ['adminMiddleware']);

$router->put('\/projects/:id', function($params) use ($db) {
    [$values, $error] = project_validate(Router::getBody());
    if ($error !== null) {
        Response::error($error, 400);
    }

    $assignments = implode(', ', array_map(fn($column) => "{$column} = ?", array_keys($values)));
    try {
        $exists = $db->fetchOne("SELECT id FROM projects WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$exists) {
            Response::notFound('Projet introuvable');
        }
        $db->query(
            "UPDATE projects SET {$assignments} WHERE id = ?",
            array_merge(array_values($values), [$params['id']])
        );
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Projet modifié');
}, ['adminMiddleware']);

$router->delete('\/projects/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM projects WHERE id = ?", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Projet supprimé');
}, ['adminMiddleware']);
