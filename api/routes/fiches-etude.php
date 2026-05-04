<?php
/**
 * Fiches d'étude Routes — Enseignant(e) ACP
 *
 * POST   /api/fiches-etude           Submit a new fiche (public)
 * GET    /api/fiches-etude           List all fiches (admin only)
 * GET    /api/fiches-etude/:id       Get a single fiche (admin only)
 * GET    /api/fiches-etude/:id/pdf   Stream the fiche PDF (admin only)
 */

require_once __DIR__ . '/../utils/SimplePDF.php';
require_once __DIR__ . '/../utils/FicheEtudePDF.php';
require_once __DIR__ . '/../utils/Mailer.php';

$router = Router::getInstance();
$db = Database::getInstance();

// =====================================================
// Submit fiche d'étude (public)
// =====================================================
$router->post('\/fiches-etude', function($params) use ($db) {
    $body = Router::getBody();

    $required = [
        'etablissement_nom', 'etablissement_adresse',
        'enseignant_prenom', 'enseignant_nom', 'telephone', 'email',
        'niveau_classe', 'nombre_eleves', 'age_moyen', 'creneau',
        'eleves_touches', 'zones_a_eviter',
        'niveau_lecture', 'capacite_attention', 'aisance_oral',
        'notions_geo', 'plan_simple', 'besoins_particuliers', 'langue_instruction',
        'date_souhaitee_1', 'videoprojecteur', 'imprimante',
        'langue_animation', 'disponible_encadrer', 'conserver_feuilles',
        'motivation', 'attentes', 'collaboration_future',
        'auth_animation'
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

    // auth_animation must be checked
    if (empty($body['auth_animation'])) {
        $errors['auth_animation'] = 'Vous devez autoriser l\'animation de la séance';
    }

    // At least one risque OR a free-text "autre"
    $risques = $body['risques'] ?? [];
    if (!is_array($risques)) $risques = [];
    if (empty($risques) && empty($body['risque_autre'])) {
        $errors['risques'] = 'Cochez au moins un risque (ou précisez « Autre »)';
    }

    if (!empty($errors)) {
        Response::validationError($errors, 'Formulaire incomplet ou invalide');
    }

    // ---- Atomic daily counter ----
    $dateKey = date('Ymd');
    try {
        $db->query(
            "INSERT INTO acp_fiches_counters (date_key, counter)
             VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE counter = LAST_INSERT_ID(counter + 1)",
            [$dateKey]
        );
        $row = $db->fetchOne("SELECT LAST_INSERT_ID() AS c");
        $counter = (int) ($row['c'] ?? 0);
        if ($counter <= 0) {
            throw new Exception('Counter increment failed');
        }
        $ficheId = 'FE-' . $dateKey . '-' . $counter;
    } catch (Exception $e) {
        Response::error('Erreur de génération du numéro : ' . $e->getMessage(), 500);
    }

    // Normalize fields
    $fields = [
        'fiche_id'              => $ficheId,
        'etablissement_nom'     => trim($body['etablissement_nom']),
        'etablissement_adresse' => trim($body['etablissement_adresse']),
        'enseignant_prenom'     => trim($body['enseignant_prenom']),
        'enseignant_nom'        => trim($body['enseignant_nom']),
        'telephone'             => trim($body['telephone']),
        'whatsapp'              => trim($body['whatsapp'] ?? ''),
        'email'                 => strtolower(trim($body['email'])),
        'niveau_classe'         => trim($body['niveau_classe']),
        'nombre_eleves'         => (int) $body['nombre_eleves'],
        'age_moyen'             => trim($body['age_moyen']),
        'creneau'               => $body['creneau'],
        'risques'               => $risques,
        'risque_autre'          => trim($body['risque_autre'] ?? ''),
        'eleves_touches'        => $body['eleves_touches'],
        'eleves_touches_lequel' => trim($body['eleves_touches_lequel'] ?? ''),
        'risque_visible'        => trim($body['risque_visible'] ?? ''),
        'zones_a_eviter'        => $body['zones_a_eviter'],
        'zones_precisions'      => trim($body['zones_precisions'] ?? ''),
        'niveau_lecture'        => $body['niveau_lecture'],
        'capacite_attention'    => $body['capacite_attention'],
        'aisance_oral'          => $body['aisance_oral'],
        'notions_geo'           => $body['notions_geo'],
        'plan_simple'           => $body['plan_simple'],
        'besoins_particuliers'  => $body['besoins_particuliers'],
        'besoins_precisions'    => trim($body['besoins_precisions'] ?? ''),
        'langue_instruction'    => $body['langue_instruction'],
        'date_souhaitee_1'      => $body['date_souhaitee_1'],
        'date_souhaitee_2'      => $body['date_souhaitee_2'] ?? null,
        'videoprojecteur'       => $body['videoprojecteur'],
        'videoprojecteur_precision' => trim($body['videoprojecteur_precision'] ?? ''),
        'imprimante'            => $body['imprimante'],
        'imprimante_precision'  => trim($body['imprimante_precision'] ?? ''),
        'nb_feuilles'           => isset($body['nb_feuilles']) && $body['nb_feuilles'] !== '' ? (int) $body['nb_feuilles'] : null,
        'langue_animation'      => $body['langue_animation'],
        'disponible_encadrer'   => $body['disponible_encadrer'],
        'disponible_precision'  => trim($body['disponible_precision'] ?? ''),
        'conserver_feuilles'    => $body['conserver_feuilles'],
        'conserver_precision'   => trim($body['conserver_precision'] ?? ''),
        'motivation'            => trim($body['motivation']),
        'attentes'              => trim($body['attentes']),
        'collaboration_future'  => $body['collaboration_future'],
        'suggestions'           => trim($body['suggestions'] ?? ''),
        'auth_animation'        => !empty($body['auth_animation']) ? 1 : 0,
        'auth_photos'           => !empty($body['auth_photos']) ? 1 : 0,
        'auth_conservation'     => !empty($body['auth_conservation']) ? 1 : 0,
    ];

    // ---- Generate PDF ----
    $pdfBinary = FicheEtudePDF::build($ficheId, $fields);

    $storageDir = __DIR__ . '/../storage/fiches-etude';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    $pdfFilename = $ficheId . '.pdf';
    $pdfPath = $storageDir . '/' . $pdfFilename;
    file_put_contents($pdfPath, $pdfBinary);

    // ---- Persist to DB ----
    try {
        $db->query(
            "INSERT INTO acp_fiches_etude
                (fiche_id, etablissement_nom, etablissement_adresse,
                 enseignant_prenom, enseignant_nom, telephone, whatsapp, email,
                 niveau_classe, nombre_eleves, age_moyen, creneau,
                 risques, risque_autre, eleves_touches, eleves_touches_lequel,
                 risque_visible, zones_a_eviter, zones_precisions,
                 niveau_lecture, capacite_attention, aisance_oral,
                 notions_geo, plan_simple, besoins_particuliers, besoins_precisions,
                 langue_instruction, date_souhaitee_1, date_souhaitee_2,
                 videoprojecteur, videoprojecteur_precision,
                 imprimante, imprimante_precision, nb_feuilles,
                 langue_animation, disponible_encadrer, disponible_precision,
                 conserver_feuilles, conserver_precision,
                 motivation, attentes, collaboration_future, suggestions,
                 auth_animation, auth_photos, auth_conservation,
                 pdf_path, email_sent, status, created_at)
             VALUES
                (?, ?, ?,
                 ?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, 0, 'pending', NOW())",
            [
                $fields['fiche_id'], $fields['etablissement_nom'], $fields['etablissement_adresse'],
                $fields['enseignant_prenom'], $fields['enseignant_nom'], $fields['telephone'], $fields['whatsapp'], $fields['email'],
                $fields['niveau_classe'], $fields['nombre_eleves'], $fields['age_moyen'], $fields['creneau'],
                json_encode($fields['risques'], JSON_UNESCAPED_UNICODE), $fields['risque_autre'], $fields['eleves_touches'], $fields['eleves_touches_lequel'],
                $fields['risque_visible'], $fields['zones_a_eviter'], $fields['zones_precisions'],
                $fields['niveau_lecture'], $fields['capacite_attention'], $fields['aisance_oral'],
                $fields['notions_geo'], $fields['plan_simple'], $fields['besoins_particuliers'], $fields['besoins_precisions'],
                $fields['langue_instruction'], $fields['date_souhaitee_1'], $fields['date_souhaitee_2'],
                $fields['videoprojecteur'], $fields['videoprojecteur_precision'],
                $fields['imprimante'], $fields['imprimante_precision'], $fields['nb_feuilles'],
                $fields['langue_animation'], $fields['disponible_encadrer'], $fields['disponible_precision'],
                $fields['conserver_feuilles'], $fields['conserver_precision'],
                $fields['motivation'], $fields['attentes'], $fields['collaboration_future'], $fields['suggestions'],
                $fields['auth_animation'], $fields['auth_photos'], $fields['auth_conservation'],
                'storage/fiches-etude/' . $pdfFilename,
            ]
        );
    } catch (Exception $e) {
        Response::error('Erreur enregistrement : ' . $e->getMessage(), 500);
    }

    // ---- Send email with PDF attached ----
    $recipient = getenv('ACP_NOTIFY_EMAIL') ?: 'contact@dev-dynamics.org';
    $teacherFullName = $fields['enseignant_prenom'] . ' ' . $fields['enseignant_nom'];

    $subject = "[ACP] Nouvelle fiche enseignant {$ficheId} — {$fields['etablissement_nom']}";
    $bodyHtml = '<html><body style="font-family:Arial,sans-serif;color:#1a1a1a;">'
        . '<h2 style="color:#008080;">Nouvelle fiche d\'étude reçue</h2>'
        . '<p><strong>N° de fiche :</strong> ' . htmlspecialchars($ficheId) . '</p>'
        . '<p><strong>Établissement :</strong> ' . htmlspecialchars($fields['etablissement_nom']) . '</p>'
        . '<p><strong>Enseignant(e) :</strong> ' . htmlspecialchars($teacherFullName) . '</p>'
        . '<p><strong>Email :</strong> ' . htmlspecialchars($fields['email']) . '</p>'
        . '<p><strong>Téléphone :</strong> ' . htmlspecialchars($fields['telephone']) . '</p>'
        . '<p><strong>Niveau :</strong> ' . htmlspecialchars($fields['niveau_classe']) . ' (' . (int) $fields['nombre_eleves'] . ' élèves)</p>'
        . '<p><strong>Date souhaitée :</strong> ' . htmlspecialchars($fields['date_souhaitee_1']) . '</p>'
        . '<p><strong>Date de soumission :</strong> ' . date('d/m/Y H:i') . '</p>'
        . '<hr><p>La fiche complète est jointe en PDF.</p>'
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
                "UPDATE acp_fiches_etude SET email_sent = 1 WHERE fiche_id = ?",
                [$ficheId]
            );
        } catch (Exception $e) {
            // non-fatal
        }
    }

    Response::success([
        'fiche_id' => $ficheId,
        'email_sent' => (bool) $sent,
        'pdf_filename' => $pdfFilename,
    ], 'Fiche enregistrée avec succès', 201);
});

// =====================================================
// List all fiches (admin)
// =====================================================
$router->get('\/fiches-etude', function($params) use ($db) {
    try {
        $rows = $db->fetchAll(
            "SELECT id, fiche_id, etablissement_nom, enseignant_prenom, enseignant_nom,
                    email, telephone, niveau_classe, nombre_eleves, date_souhaitee_1,
                    status, email_sent, created_at
             FROM acp_fiches_etude
             ORDER BY created_at DESC"
        );
        Response::success($rows);
    } catch (Exception $e) {
        Response::error('Erreur récupération : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// =====================================================
// Get one fiche (admin)
// =====================================================
$router->get('\/fiches-etude/:id', function($params) use ($db) {
    try {
        $row = $db->fetchOne(
            "SELECT * FROM acp_fiches_etude WHERE fiche_id = ? LIMIT 1",
            [$params['id']]
        );
        if (!$row) Response::notFound('Fiche introuvable');
        Response::success($row);
    } catch (Exception $e) {
        Response::error('Erreur : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// =====================================================
// Stream the PDF (admin) — accepts header OR ?token=
// =====================================================
$router->get('\/fiches-etude/:id/pdf', function($params) use ($db) {
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
            "SELECT pdf_path, fiche_id FROM acp_fiches_etude WHERE fiche_id = ? LIMIT 1",
            [$params['id']]
        );
        if (!$row) Response::notFound('Fiche introuvable');

        $path = __DIR__ . '/../' . $row['pdf_path'];
        if (!file_exists($path)) Response::notFound('PDF introuvable');

        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $row['fiche_id'] . '.pdf"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } catch (Exception $e) {
        Response::error('Erreur : ' . $e->getMessage(), 500);
    }
});
