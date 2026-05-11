<?php
/**
 * Candidatures Routes — Académie des Cartographes Populaires (ACP)
 *
 * POST   /api/candidatures           Submit a new application (public)
 * GET    /api/candidatures           List all applications (admin only)
 * GET    /api/candidatures/:id       Get a single application by candidature_id (admin only)
 * GET    /api/candidatures/:id/pdf   Stream the application PDF (admin only)
 */

require_once __DIR__ . '/../utils/SimplePDF.php';
require_once __DIR__ . '/../utils/CandidaturePDF.php';
require_once __DIR__ . '/../utils/Mailer.php';

$router = Router::getInstance();
$db = Database::getInstance();

// =====================================================
// Submit candidature (public)
// =====================================================
$router->post('\/candidatures', function($params) use ($db) {
    $body = Router::getBody();

    // Required fields
    $required = [
        'prenom', 'nom', 'date_naissance', 'sexe', 'lieu_naissance', 'piece_identite',
        'adresse', 'commune', 'departement', 'telephone', 'email',
        'niveau_etudes', 'situation', 'a_ordinateur',
        'motivation', 'usage_envisage', 'disponibilite',
        'confirm_id', 'confirm_eligibility'
    ];

    $errors = [];
    foreach ($required as $field) {
        if (!isset($body[$field]) || (is_string($body[$field]) && trim($body[$field]) === '')) {
            $errors[$field] = 'Champ obligatoire';
        }
    }

    if (!empty($body['email']) && !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Adresse email invalide';
    }

    if (!empty($body['date_naissance'])) {
        $ts = strtotime($body['date_naissance']);
        if (!$ts) {
            $errors['date_naissance'] = 'Date invalide';
        } else {
            $age = (int) date('Y', time()) - (int) date('Y', $ts);
            if (date('md', time()) < date('md', $ts)) $age--;
            if ($age < 18) {
                $errors['date_naissance'] = 'Vous devez avoir au moins 18 ans';
            }
        }
    }

    foreach (['confirm_id', 'confirm_eligibility'] as $checkbox) {
        if (empty($body[$checkbox])) {
            $errors[$checkbox] = 'Vous devez cocher cet engagement';
        }
    }

    if (!empty($errors)) {
        Response::validationError($errors, 'Formulaire incomplet ou invalide');
    }

    // ---- Atomic daily counter (per current PHP timezone) ----
    $dateKey = date('Ymd');
    try {
        $db->query(
            "INSERT INTO acp_counters (date_key, counter)
             VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE counter = LAST_INSERT_ID(counter + 1)",
            [$dateKey]
        );
        $row = $db->fetchOne("SELECT LAST_INSERT_ID() AS c");
        $counter = (int) ($row['c'] ?? 0);
        if ($counter <= 0) {
            throw new Exception('Counter increment failed');
        }
        $candidatureId = $dateKey . '-' . $counter;
    } catch (Exception $e) {
        Response::error('Erreur de génération du numéro : ' . $e->getMessage(), 500);
    }

    // Normalize fields
    $fields = [
        'candidature_id' => $candidatureId,
        'prenom'         => trim($body['prenom']),
        'nom'            => trim($body['nom']),
        'date_naissance' => $body['date_naissance'],
        'sexe'           => $body['sexe'],
        'lieu_naissance' => trim($body['lieu_naissance']),
        'piece_identite' => trim($body['piece_identite']),
        'adresse'        => trim($body['adresse']),
        'commune'        => trim($body['commune']),
        'departement'    => trim($body['departement']),
        'telephone'      => trim($body['telephone']),
        'whatsapp'       => trim($body['whatsapp'] ?? ''),
        'email'          => strtolower(trim($body['email'])),
        'source'         => trim($body['source'] ?? ''),
        'niveau_etudes'  => $body['niveau_etudes'],
        'etablissement'  => trim($body['etablissement'] ?? ''),
        'filiere'        => trim($body['filiere'] ?? ''),
        'situation'      => $body['situation'],
        'a_ordinateur'   => $body['a_ordinateur'],
        'experience'     => trim($body['experience'] ?? ''),
        'motivation'     => trim($body['motivation']),
        'usage_envisage' => trim($body['usage_envisage']),
        'disponibilite'  => $body['disponibilite'],
        'contraintes'    => trim($body['contraintes'] ?? ''),
    ];

    // ---- Generate PDF ----
    $pdfBinary = CandidaturePDF::build($candidatureId, $fields);

    $storageDir = __DIR__ . '/../storage/candidatures';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    $pdfFilename = $candidatureId . '.pdf';
    $pdfPath = $storageDir . '/' . $pdfFilename;
    file_put_contents($pdfPath, $pdfBinary);

    // ---- Persist to DB ----
    try {
        $db->query(
            "INSERT INTO acp_candidatures
                (candidature_id, prenom, nom, date_naissance, sexe, lieu_naissance, piece_identite,
                 adresse, commune, departement, telephone, whatsapp, email, source,
                 niveau_etudes, etablissement, filiere, situation, a_ordinateur, experience,
                 motivation, usage_envisage, disponibilite, contraintes,
                 pdf_path, email_sent, status, created_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, 0, 'pending', NOW())",
            [
                $fields['candidature_id'], $fields['prenom'], $fields['nom'],
                $fields['date_naissance'], $fields['sexe'], $fields['lieu_naissance'], $fields['piece_identite'],
                $fields['adresse'], $fields['commune'], $fields['departement'],
                $fields['telephone'], $fields['whatsapp'], $fields['email'], $fields['source'],
                $fields['niveau_etudes'], $fields['etablissement'], $fields['filiere'],
                $fields['situation'], $fields['a_ordinateur'], $fields['experience'],
                $fields['motivation'], $fields['usage_envisage'], $fields['disponibilite'], $fields['contraintes'],
                'storage/candidatures/' . $pdfFilename,
            ]
        );
    } catch (Exception $e) {
        Response::error('Erreur enregistrement : ' . $e->getMessage(), 500);
    }

    // ---- Send email to coordination address with PDF attached ----
    $recipient = getenv('ACP_NOTIFY_EMAIL') ?: 'contact@dev-dynamics.org';
    $candidateFullName = $fields['prenom'] . ' ' . $fields['nom'];

    $subject = "[ACP] Nouvelle candidature {$candidatureId} — {$candidateFullName}";
    $bodyHtml = '<html><body style="font-family:Arial,sans-serif;color:#1a1a1a;">'
        . '<h2 style="color:#008080;">Nouvelle candidature reçue</h2>'
        . '<p><strong>N° de candidature :</strong> ' . htmlspecialchars($candidatureId) . '</p>'
        . '<p><strong>Candidat(e) :</strong> ' . htmlspecialchars($candidateFullName) . '</p>'
        . '<p><strong>Email :</strong> ' . htmlspecialchars($fields['email']) . '</p>'
        . '<p><strong>Téléphone :</strong> ' . htmlspecialchars($fields['telephone']) . '</p>'
        . '<p><strong>Date de soumission :</strong> ' . date('d/m/Y H:i') . '</p>'
        . '<hr><p>Le formulaire complet est joint en PDF.</p>'
        . '<p style="color:#666;font-size:12px;">DevDynamics — Académie des Cartographes Populaires</p>'
        . '</body></html>';

    $sent = Mailer::sendWithPdf(
        $recipient,
        $subject,
        $bodyHtml,
        $pdfPath,
        $pdfFilename,
        null,
        $fields['email']
    );

    if ($sent) {
        try {
            $db->query(
                "UPDATE acp_candidatures SET email_sent = 1 WHERE candidature_id = ?",
                [$candidatureId]
            );
        } catch (Exception $e) {
            // non-fatal
        }
    }

    Response::success([
        'candidature_id' => $candidatureId,
        'email_sent' => (bool) $sent,
        'pdf_filename' => $pdfFilename,
    ], 'Candidature enregistrée avec succès', 201);
});

// =====================================================
// List all candidatures (admin)
// =====================================================
$router->get('\/candidatures', function($params) use ($db) {
    try {
        $rows = $db->fetchAll(
            "SELECT id, candidature_id, prenom, nom, sexe, email, telephone, niveau_etudes,
                    situation, disponibilite, status, email_sent, created_at
             FROM acp_candidatures
             ORDER BY created_at DESC"
        );
        Response::success($rows);
    } catch (Exception $e) {
        Response::error('Erreur récupération : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// =====================================================
// Get one candidature (admin)
// =====================================================
$router->get('\/candidatures/:id', function($params) use ($db) {
    try {
        $row = $db->fetchOne(
            "SELECT * FROM acp_candidatures WHERE candidature_id = ? LIMIT 1",
            [$params['id']]
        );
        if (!$row) Response::notFound('Candidature introuvable');
        Response::success($row);
    } catch (Exception $e) {
        Response::error('Erreur : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// =====================================================
// Stream the PDF for a candidature (admin)
// Auth accepts either an Authorization header OR a ?token= query parameter,
// because <a target="_blank"> cannot inject custom headers.
// =====================================================
$router->get('\/candidatures/:id/pdf', function($params) use ($db) {
    $token = JWT::getTokenFromHeader();
    if (!$token) {
        $token = isset($_GET['token']) ? $_GET['token'] : null;
    }
    if (!$token) Response::unauthorized('No token provided');

    try {
        $decoded = JWT::decode($token);
        if (($decoded['role'] ?? '') !== 'admin') {
            Response::forbidden('Admin access required');
        }
    } catch (Exception $e) {
        Response::unauthorized('Invalid or expired token');
    }

    try {
        $row = $db->fetchOne(
            "SELECT pdf_path, candidature_id FROM acp_candidatures WHERE candidature_id = ? LIMIT 1",
            [$params['id']]
        );
        if (!$row) Response::notFound('Candidature introuvable');

        $path = __DIR__ . '/../' . $row['pdf_path'];
        if (!file_exists($path)) Response::notFound('PDF introuvable');

        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $row['candidature_id'] . '.pdf"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } catch (Exception $e) {
        Response::error('Erreur : ' . $e->getMessage(), 500);
    }
});
