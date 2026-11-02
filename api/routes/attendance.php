<?php
/**
 * Feuille de presence — restitution du 2 novembre 2026
 *
 * POST   /api/attendance         - S'inscrire sur la feuille (public)
 * GET    /api/attendance         - Liste des presences (admin)
 * DELETE /api/attendance/:id     - Supprimer une ligne (admin)
 * GET    /api/attendance/export  - Feuille de presence en PDF (admin)
 */

const ATTENDANCE_TITLE = 'Feuille de présence à la restitution du 2 novembre 2026';
const ATTENDANCE_GENDERS = ['F' => 'Féminin', 'M' => 'Masculin'];

/**
 * La table se cree a la premiere utilisation : la page sert le jour meme de la
 * restitution, elle ne doit pas dependre d'une migration lancee a la main.
 */
function attendance_ensure_table($db) {
    $db->query("CREATE TABLE IF NOT EXISTS attendance_sheet (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        last_name   VARCHAR(80) NOT NULL,
        first_name  VARCHAR(80) NOT NULL,
        identifier  VARCHAR(20) NOT NULL COMMENT 'NIF ou NINU, sans espace ni tiret',
        gender      ENUM('F','M') NOT NULL,
        age         TINYINT UNSIGNED NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_attendance_identifier (identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Controle la saisie. Rend [valeurs, null] ou [null, message d'erreur].
 */
function attendance_validate(array $body) {
    $clean = function($key) use ($body) {
        $value = $body[$key] ?? '';
        return is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value)) : '';
    };

    $lastName = $clean('last_name');
    $firstName = $clean('first_name');
    if ($lastName === '' || mb_strlen($lastName) > 80) {
        return [null, 'Le nom est obligatoire (80 caractères au plus)'];
    }
    if ($firstName === '' || mb_strlen($firstName) > 80) {
        return [null, 'Le prénom est obligatoire (80 caractères au plus)'];
    }

    // Le meme numero s'ecrit avec ou sans tirets : on le range sous une seule forme
    $identifier = strtoupper(preg_replace('/[\s.\-]/', '', $clean('identifier')));
    if (!preg_match('/^[A-Z0-9]{8,20}$/', $identifier)) {
        return [null, "L'identifiant (NIF ou NINU) doit contenir de 8 à 20 lettres ou chiffres"];
    }

    $gender = $clean('gender');
    if (!isset(ATTENDANCE_GENDERS[$gender])) {
        return [null, 'Le sexe est obligatoire'];
    }

    $age = filter_var($clean('age'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 120]]);
    if ($age === false) {
        return [null, "L'âge doit être un nombre entre 5 et 120"];
    }

    return [[
        'last_name' => $lastName,
        'first_name' => $firstName,
        'identifier' => $identifier,
        'gender' => $gender,
        'age' => $age,
    ], null];
}

function attendance_rows($db) {
    return $db->fetchAll(
        "SELECT id, last_name, first_name, identifier, gender, age, created_at
         FROM attendance_sheet ORDER BY last_name, first_name"
    );
}

/**
 * Decoupe un texte pour une colonne. Helvetica n'a pas de largeur fixe : 0,6 em
 * par caractere reste large, meme pour un nom ecrit en majuscules.
 */
function attendance_pdf_lines($text, $width, $fontSize) {
    $max = max(1, (int) floor($width / ($fontSize * 0.6)));
    preg_match_all('/\S.{0,' . ($max - 1) . '}(?=\s|$)|\S.{0,' . ($max - 1) . '}/u', (string) $text, $matches);
    return $matches[0] ?: [''];
}

/**
 * En-tete DevDynamics, titre et ligne de colonnes : repetes sur chaque page.
 */
function attendance_pdf_page_top($pdf, array $columns, $pageNumber) {
    $pdf->image(__DIR__ . '/../../assets/images/logo_6_pdf.jpg', 140);
    $pdf->moveDown(16);
    $pdf->setFont(9);
    $pdf->text('Association éducative et technologique à but non lucratif en Haïti');
    $pdf->text('Port-au-Prince, Haïti | contact@dev-dynamics.org | +509 47 41 8737');
    $pdf->moveDown(6);
    $pdf->hr();
    $pdf->moveDown(10);
    $pdf->setFont(14, true);
    $pdf->text(ATTENDANCE_TITLE);
    $pdf->moveDown(8);

    $pdf->setFont(8);
    $pdf->textAt('Page ' . $pageNumber, $pdf->marginLeft, $pdf->marginBottom - 20);

    $pdf->setFont(9, true);
    $height = 20;
    $x = $pdf->marginLeft;
    $y = $pdf->cursorY - $height;
    foreach ($columns as [$label, $width]) {
        $pdf->rect($x, $y, $width, $height, 0.9);
        $pdf->textAt($label, $x + 4, $y + 6);
        $x += $width;
    }
    $pdf->cursorY = $y;
    $pdf->setFont(9);
}

/**
 * Construit le PDF et le rend sous forme de chaine.
 */
function attendance_pdf(array $rows) {
    require_once __DIR__ . '/../utils/SimplePDF.php';

    $columns = [['N°', 30], ['Nom', 135], ['Prénom', 135], ['Identifiant', 105], ['Sexe', 55], ['Âge', 35]];
    $fontSize = 9;

    $pdf = new SimplePDF();
    $page = 1;
    attendance_pdf_page_top($pdf, $columns, $page);

    foreach (array_values($rows) as $index => $row) {
        $cells = [
            [$index + 1],
            attendance_pdf_lines($row['last_name'], $columns[1][1] - 8, $fontSize),
            attendance_pdf_lines($row['first_name'], $columns[2][1] - 8, $fontSize),
            attendance_pdf_lines($row['identifier'], $columns[3][1] - 8, $fontSize),
            [ATTENDANCE_GENDERS[$row['gender']] ?? $row['gender']],
            [$row['age']],
        ];
        $lineCount = max(array_map('count', $cells));
        $height = 8 + $lineCount * 12;

        if ($pdf->cursorY - $height < $pdf->marginBottom) {
            $pdf->addPage();
            attendance_pdf_page_top($pdf, $columns, ++$page);
        }

        $x = $pdf->marginLeft;
        $y = $pdf->cursorY - $height;
        foreach ($columns as $i => [, $width]) {
            $pdf->rect($x, $y, $width, $height);
            foreach ($cells[$i] as $n => $line) {
                $pdf->textAt($line, $x + 4, $pdf->cursorY - 14 - $n * 12);
            }
            $x += $width;
        }
        $pdf->cursorY = $y;
    }

    if ($pdf->cursorY - 30 < $pdf->marginBottom) {
        $pdf->addPage();
        attendance_pdf_page_top($pdf, $columns, ++$page);
    }
    $pdf->moveDown(18);
    $pdf->setFont(10, true);
    $pdf->text('Total : ' . count($rows) . ' présent' . (count($rows) > 1 ? 's' : ''));

    return $pdf->output();
}

$router = Router::getInstance();
$db = Database::getInstance();

$router->post('\/attendance', function($params) use ($db) {
    $body = Router::getBody();

    // Piege a robots : un champ invisible que seul un script remplit
    if (!empty($body['website'])) {
        Response::success(null, 'Présence enregistrée', 201);
    }

    [$values, $error] = attendance_validate($body);
    if ($error !== null) {
        Response::error($error, 400);
    }

    try {
        attendance_ensure_table($db);
        $db->query(
            "INSERT INTO attendance_sheet (last_name, first_name, identifier, gender, age)
             VALUES (?, ?, ?, ?, ?)",
            array_values($values)
        );
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            Response::error('Cet identifiant est déjà inscrit sur la feuille de présence', 409);
        }
        Response::error("La présence n'a pas pu être enregistrée. Réessaie dans un instant.", 500);
    }

    Response::success(null, 'Présence enregistrée', 201);
});

$router->get('\/attendance', function($params) use ($db) {
    try {
        attendance_ensure_table($db);
        Response::success(attendance_rows($db));
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->delete('\/attendance/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM attendance_sheet WHERE id = ?", [$params['id']]);
        Response::success(null, 'Ligne supprimée');
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->get('\/attendance/export', function($params) use ($db) {
    try {
        attendance_ensure_table($db);
        $content = attendance_pdf(attendance_rows($db));
    } catch (Exception $e) {
        Response::error('Export impossible : ' . $e->getMessage(), 500);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="feuille-de-presence-2026-11-02.pdf"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}, ['adminMiddleware']);
