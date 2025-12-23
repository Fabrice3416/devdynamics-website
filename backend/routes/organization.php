<?php
/**
 * Organization Routes
 * GET /api/organization - Get organization info
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get organization information
$router->get('\/organization\/info', function($params) use ($db) {
    try {
        $organization = $db->fetchOne(
            "SELECT * FROM organization_info LIMIT 1"
        );

        if (!$organization) {
            Response::notFound('Organization information not found');
        }

        Response::success($organization);
    } catch (Exception $e) {
        Response::error('Failed to fetch organization info: ' . $e->getMessage(), 500);
    }
});

// Update organization information (Admin only)
$router->put('\/organization\/info', function($params) use ($db) {
    // Vérifier l'authentification admin
    adminMiddleware();

    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            Response::error('Invalid JSON data', 400);
        }

        // Vérifier si un enregistrement existe
        $existing = $db->fetchOne("SELECT id FROM organization_info LIMIT 1");

        if ($existing) {
            // Mise à jour
            $fields = [];
            $values = [];

            if (isset($data['name'])) {
                $fields[] = 'name = ?';
                $values[] = $data['name'];
            }
            if (isset($data['mission'])) {
                $fields[] = 'mission = ?';
                $values[] = $data['mission'];
            }
            if (isset($data['address'])) {
                $fields[] = 'address = ?';
                $values[] = $data['address'];
            }
            if (isset($data['phone'])) {
                $fields[] = 'phone = ?';
                $values[] = $data['phone'];
            }
            if (isset($data['email'])) {
                $fields[] = 'email = ?';
                $values[] = $data['email'];
            }
            if (isset($data['whatsapp'])) {
                $fields[] = 'whatsapp = ?';
                $values[] = $data['whatsapp'];
            }
            if (isset($data['facebook_url'])) {
                $fields[] = 'facebook_url = ?';
                $values[] = $data['facebook_url'];
            }
            if (isset($data['twitter_url'])) {
                $fields[] = 'twitter_url = ?';
                $values[] = $data['twitter_url'];
            }
            if (isset($data['linkedin_url'])) {
                $fields[] = 'linkedin_url = ?';
                $values[] = $data['linkedin_url'];
            }
            if (isset($data['instagram_url'])) {
                $fields[] = 'instagram_url = ?';
                $values[] = $data['instagram_url'];
            }

            if (empty($fields)) {
                Response::error('No fields to update', 400);
            }

            $fields[] = 'updated_at = NOW()';
            $values[] = $existing['id'];

            $sql = "UPDATE organization_info SET " . implode(', ', $fields) . " WHERE id = ?";
            $db->query($sql, $values);
        } else {
            // Insertion
            $sql = "INSERT INTO organization_info (name, mission, address, phone, email, whatsapp,
                    facebook_url, twitter_url, linkedin_url, instagram_url, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $db->query($sql, [
                $data['name'] ?? 'DevDynamics',
                $data['mission'] ?? '',
                $data['address'] ?? '',
                $data['phone'] ?? '',
                $data['email'] ?? '',
                $data['whatsapp'] ?? '',
                $data['facebook_url'] ?? '',
                $data['twitter_url'] ?? '',
                $data['linkedin_url'] ?? '',
                $data['instagram_url'] ?? ''
            ]);
        }

        // Récupérer les données mises à jour
        $updated = $db->fetchOne("SELECT * FROM organization_info LIMIT 1");

        Response::success($updated, 'Organization info updated successfully');
    } catch (Exception $e) {
        Response::error('Failed to update organization info: ' . $e->getMessage(), 500);
    }
});

// Get founders/team members
$router->get('\/organization\/founders', function($params) use ($db) {
    try {
        $founders = $db->fetchAll(
            "SELECT * FROM founders ORDER BY order_position ASC"
        );

        Response::success($founders);
    } catch (Exception $e) {
        Response::error('Failed to fetch founders: ' . $e->getMessage(), 500);
    }
});
