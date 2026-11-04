<?php
/**
 * Messages de contact
 *
 * POST   /api/contact             - Envoyer un message (public)
 * GET    /api/contact             - Liste des messages (admin)
 * PUT    /api/contact/:id/status  - Changer le statut (admin)
 * DELETE /api/contact/:id         - Supprimer (admin)
 */

require_once __DIR__ . '/../utils/RateLimit.php';
require_once __DIR__ . '/../utils/Mailer.php';

const CONTACT_MAX_PER_HOUR = 10;

/**
 * Controle la saisie. Rend [valeurs, null] ou [null, message d'erreur].
 */
function contact_validate(array $body) {
    $text = function ($key) use ($body) {
        $value = $body[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    };

    $name = $text('name');
    $email = $text('email');
    $phone = $text('phone');
    $subject = $text('subject');
    $message = $text('message');

    if ($name === '' || mb_strlen($name) > 255) {
        return [null, 'Indiquez votre nom'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        return [null, 'Indiquez une adresse e-mail valide'];
    }
    if (mb_strlen($phone) > 20) {
        return [null, 'Le numéro de téléphone est trop long'];
    }
    if (mb_strlen($subject) > 255) {
        return [null, 'Le sujet est trop long'];
    }
    if ($message === '' || mb_strlen($message) > 10000) {
        return [null, 'Écrivez votre message (10 000 caractères au plus)'];
    }

    return [[
        'name' => $name,
        'email' => $email,
        'phone' => $phone === '' ? null : $phone,
        'subject' => $subject,
        'message' => $message,
    ], null];
}

$router = Router::getInstance();
$db = Database::getInstance();

$router->post('\/contact', function($params) use ($db) {
    $body = Router::getBody();

    // Piege a robots : un champ invisible que seul un script remplit
    if (!empty($body['website'])) {
        Response::success(null, 'Message envoyé', 201);
    }

    [$values, $error] = contact_validate($body);
    if ($error !== null) {
        Response::error($error, 400);
    }

    if (rate_limited($db, 'contact', CONTACT_MAX_PER_HOUR, 3600)) {
        Response::error('Trop de messages envoyés. Réessayez plus tard ou écrivez à contact@dev-dynamics.org.', 429);
    }

    try {
        $db->query(
            "INSERT INTO contact_messages (name, email, phone, subject, message, status)
             VALUES (?, ?, ?, ?, ?, 'new')",
            array_values($values)
        );
    } catch (Exception $e) {
        error_log('contact : ' . $e->getMessage());
        Response::error("Le message n'a pas pu être enregistré. Écrivez à contact@dev-dynamics.org.", 500);
    }

    // Apres l'enregistrement : une panne de messagerie ne perd pas le message
    Mailer::notifyContact($values);

    Response::success(null, 'Message envoyé', 201);
});

$router->get('\/contact', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT id, name, email, phone, subject, message, status, created_at
             FROM contact_messages ORDER BY created_at DESC, id DESC"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->put('\/contact/:id/status', function($params) use ($db) {
    $status = Router::getBody()['status'] ?? '';
    if (!in_array($status, ['new', 'read', 'replied'], true)) {
        Response::error('Statut invalide', 400);
    }
    try {
        $db->query("UPDATE contact_messages SET status = ? WHERE id = ?", [$status, $params['id']]);
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Statut modifié');
}, ['adminMiddleware']);

$router->delete('\/contact/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM contact_messages WHERE id = ?", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Message supprimé');
}, ['adminMiddleware']);
