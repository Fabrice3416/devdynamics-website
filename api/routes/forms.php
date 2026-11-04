<?php
/**
 * Formulaires rattaches aux projets
 *
 * GET    /api/forms/public/:slug            - Formulaire a remplir (public)
 * POST   /api/forms/public/:slug/responses  - Envoyer une reponse (public)
 * GET    /api/forms                         - Liste (admin)
 * POST   /api/forms                         - Creer (admin)
 * GET    /api/forms/:id                     - Detail et champs (admin)
 * PUT    /api/forms/:id                     - Modifier (admin)
 * PUT    /api/forms/:id/status              - Ouvrir, suspendre (admin)
 * DELETE /api/forms/:id                     - Supprimer (admin)
 * GET    /api/forms/:id/responses           - Reponses et graphiques (admin)
 * GET    /api/forms/:id/export              - Export csv | excel | pdf (admin)
 * DELETE /api/forms/responses/:id           - Supprimer une reponse (admin)
 *
 * L'empreinte d'appareil (form_responses.device_hash) ne sort jamais de ce
 * fichier : aucune requete de lecture ne la selectionne.
 */

require_once __DIR__ . '/../utils/RateLimit.php';

// Large : une salle entiere repond souvent depuis la meme connexion
const FORM_MAX_PER_HOUR = 300;

const FORM_FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'single', 'multiple', 'select', 'scale', 'yesno'];
const FORM_OPTION_TYPES = ['single', 'multiple', 'select'];
const FORM_CHART_TYPES = ['single', 'multiple', 'select', 'scale', 'yesno', 'number'];
const FORM_TEXT_LIMITS = ['text' => 500, 'textarea' => 10000];
const FORM_YESNO = ['oui' => 'Oui', 'non' => 'Non'];

function form_now() {
    return (new DateTime('now', new DateTimeZone('America/Port-au-Prince')))->format('Y-m-d H:i:s');
}

/**
 * Etat vu par le public : un formulaire ouvert dont la date est passee est ferme.
 */
function form_state(array $form, $now) {
    if ($form['status'] !== 'open') return $form['status'];
    if (!empty($form['closes_at']) && $form['closes_at'] <= $now) return 'closed';
    return 'open';
}

function form_device_hash($formId, $token) {
    return hash('sha256', $formId . '|' . $token);
}

function form_field_options(array $field) {
    if (empty($field['options'])) return [];
    return is_array($field['options']) ? $field['options'] : (json_decode($field['options'], true) ?: []);
}

/**
 * Controle les informations generales. Rend [valeurs, null] ou [null, erreur].
 */
function form_validate_meta(array $body) {
    $text = function ($key, $max) use ($body) {
        $value = $body[$key] ?? '';
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    };

    $title = $text('title', 200);
    if ($title === '' || project_slug($title) === '') {
        return [null, 'Le titre est obligatoire'];
    }
    $projectId = filter_var($body['project_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($projectId === false) {
        return [null, 'Choisissez un projet'];
    }

    // datetime-local envoie 2026-11-30T17:00, la base attend une espace
    $closesAt = str_replace('T', ' ', $text('closes_at', 19));
    if ($closesAt !== '') {
        if (strlen($closesAt) === 16) $closesAt .= ':00';
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $closesAt);
        if (!$date || $date->format('Y-m-d H:i:s') !== $closesAt) {
            return [null, 'Date de fermeture invalide'];
        }
    }

    return [[
        'project_id' => $projectId,
        'title' => $title,
        'intro' => $text('intro', 5000) ?: null,
        'thanks_message' => $text('thanks_message', 500) ?: null,
        'closes_at' => $closesAt ?: null,
        'one_per_device' => empty($body['one_per_device']) ? 0 : 1,
    ], null];
}

/**
 * Controle la liste des champs envoyee par le constructeur. Quand des reponses
 * existent, un champ ne peut plus etre retire ni changer de type, et une option
 * ne peut plus disparaitre : ces reponses perdraient leur sens.
 * Rend [champs, ids_a_supprimer, null] ou [null, null, erreur].
 */
function form_normalize_fields($input, array $existing, $hasResponses) {
    if (!is_array($input) || !$input) {
        return [null, null, 'Ajoutez au moins une question'];
    }

    $byId = [];
    foreach ($existing as $field) $byId[(int) $field['id']] = $field;

    $fields = [];
    $kept = [];
    foreach (array_values($input) as $index => $raw) {
        if (!is_array($raw)) return [null, null, 'Question invalide'];
        $number = $index + 1;

        $label = is_scalar($raw['label'] ?? null) ? mb_substr(trim((string) $raw['label']), 0, 500) : '';
        if ($label === '') return [null, null, "La question {$number} n'a pas de libellé"];

        $type = $raw['type'] ?? '';
        if (!in_array($type, FORM_FIELD_TYPES, true)) return [null, null, "La question {$number} a un type invalide"];

        $id = isset($raw['id']) && $raw['id'] !== '' ? (int) $raw['id'] : null;
        if ($id !== null && !isset($byId[$id])) return [null, null, "La question {$number} n'appartient pas à ce formulaire"];
        if ($id !== null) $kept[$id] = true;

        $options = [];
        if (in_array($type, FORM_OPTION_TYPES, true)) {
            foreach ((array) ($raw['options'] ?? []) as $option) {
                $option = is_scalar($option) ? mb_substr(trim((string) $option), 0, 200) : '';
                if ($option !== '' && !in_array($option, $options, true)) $options[] = $option;
            }
            if (count($options) < 2) return [null, null, "La question {$number} doit proposer au moins deux choix"];
        }

        if ($id !== null && $hasResponses) {
            if ($byId[$id]['type'] !== $type) {
                return [null, null, "La question {$number} a déjà des réponses : son type ne peut plus changer"];
            }
            if (array_diff(form_field_options($byId[$id]), $options)) {
                return [null, null, "La question {$number} a déjà des réponses : ses choix ne peuvent plus être retirés"];
            }
        }

        $chart = $raw['chart'] ?? 'none';
        if (!in_array($chart, ['none', 'bar', 'pie'], true) || !in_array($type, FORM_CHART_TYPES, true)) {
            $chart = 'none';
        }

        $fields[] = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'help_text' => is_scalar($raw['help_text'] ?? null) ? (mb_substr(trim((string) $raw['help_text']), 0, 500) ?: null) : null,
            'required' => empty($raw['required']) ? 0 : 1,
            'options' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'position' => $index,
            'hidden' => empty($raw['hidden']) ? 0 : 1,
            'chart' => $chart,
        ];
    }

    $removed = array_values(array_diff(array_keys($byId), array_keys($kept)));
    if ($removed && $hasResponses) {
        return [null, null, 'Ce formulaire a déjà des réponses : masquez la question au lieu de la retirer'];
    }
    return [$fields, $removed, null];
}

/**
 * Controle une reponse. Rend [[field_id => valeur a enregistrer], null] ou [null, erreur].
 */
function form_validate_answers(array $fields, $answers) {
    $answers = is_array($answers) ? $answers : [];
    $values = [];

    foreach ($fields as $field) {
        if (!empty($field['hidden'])) continue;

        $id = (int) $field['id'];
        $raw = $answers[$id] ?? null;
        $empty = $raw === null || $raw === '' || $raw === [] || (is_string($raw) && trim($raw) === '');
        if ($empty) {
            if (!empty($field['required'])) return [null, "Répondez à la question : {$field['label']}"];
            continue;
        }

        $invalid = [null, "Réponse invalide à la question : {$field['label']}"];
        $options = form_field_options($field);

        switch ($field['type']) {
            case 'text':
            case 'textarea':
                if (!is_scalar($raw)) return $invalid;
                $value = trim((string) $raw);
                if (mb_strlen($value) > FORM_TEXT_LIMITS[$field['type']]) return [null, "Réponse trop longue à la question : {$field['label']}"];
                $values[$id] = $value;
                break;
            case 'number':
                if (!is_scalar($raw) || !is_numeric(str_replace(',', '.', (string) $raw))) return $invalid;
                $values[$id] = str_replace(',', '.', trim((string) $raw));
                break;
            case 'date':
                $date = is_string($raw) ? DateTime::createFromFormat('Y-m-d', $raw) : false;
                if (!$date || $date->format('Y-m-d') !== $raw) return $invalid;
                $values[$id] = $raw;
                break;
            case 'single':
            case 'select':
                if (!is_string($raw) || !in_array($raw, $options, true)) return $invalid;
                $values[$id] = $raw;
                break;
            case 'multiple':
                if (!is_array($raw) || array_diff($raw, $options)) return $invalid;
                $values[$id] = json_encode(array_values(array_unique($raw)), JSON_UNESCAPED_UNICODE);
                break;
            case 'scale':
                $scale = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : false;
                if ($scale === false) return $invalid;
                $values[$id] = (string) $scale;
                break;
            case 'yesno':
                if (!is_string($raw) || !isset(FORM_YESNO[$raw])) return $invalid;
                $values[$id] = $raw;
                break;
            default:
                return $invalid;
        }
    }

    if (!$values) return [null, 'Répondez à au moins une question'];
    return [$values, null];
}

/**
 * Valeur lisible d'une reponse, pour l'admin et les exports.
 */
function form_display_value(array $field, $value) {
    if ($value === null) return '';
    if ($field['type'] === 'multiple') return implode(', ', json_decode($value, true) ?: []);
    if ($field['type'] === 'yesno') return FORM_YESNO[$value] ?? $value;
    return (string) $value;
}

/**
 * Agregat d'une question : series pour les graphiques, resume pour les nombres.
 */
function form_field_stats(array $field, array $values) {
    $stats = ['field_id' => (int) $field['id'], 'label' => $field['label'], 'type' => $field['type'], 'chart' => $field['chart'], 'n' => count($values)];

    $count = function (array $labels) use ($values, $field) {
        $counts = array_fill_keys($labels, 0);
        foreach ($values as $value) {
            $picked = $field['type'] === 'multiple' ? (json_decode($value, true) ?: []) : [$value];
            foreach ($picked as $item) {
                if (array_key_exists($item, $counts)) $counts[$item]++;
            }
        }
        return $counts;
    };
    $series = fn(array $counts, array $names = []) => array_map(
        fn($key) => ['label' => (string) ($names[$key] ?? $key), 'count' => $counts[$key]],
        array_keys($counts)
    );

    switch ($field['type']) {
        case 'single':
        case 'select':
        case 'multiple':
            $stats['series'] = $series($count(form_field_options($field)));
            break;
        case 'scale':
            $stats['series'] = $series($count(['1', '2', '3', '4', '5']));
            $stats['average'] = $values ? round(array_sum($values) / count($values), 2) : null;
            break;
        case 'yesno':
            $stats['series'] = $series($count(array_keys(FORM_YESNO)), FORM_YESNO);
            break;
        case 'number':
            $numbers = array_map('floatval', $values);
            if ($numbers) {
                $stats['average'] = round(array_sum($numbers) / count($numbers), 2);
                $stats['min'] = min($numbers);
                $stats['max'] = max($numbers);
            }
            // Peu de valeurs differentes : une barre par valeur. Sinon six tranches.
            $distinct = array_unique($numbers);
            sort($distinct);
            if (count($distinct) <= 8) {
                $stats['series'] = array_map(fn($d) => ['label' => (string) $d, 'count' => count(array_keys($numbers, $d))], $distinct);
            } else {
                $step = (max($numbers) - min($numbers)) / 6;
                $stats['series'] = [];
                for ($i = 0; $i < 6; $i++) {
                    $from = min($numbers) + $i * $step;
                    $to = $from + $step;
                    $stats['series'][] = [
                        'label' => round($from, 2) . ' à ' . round($to, 2),
                        'count' => count(array_filter($numbers, fn($n) => $n >= $from && ($i === 5 ? $n <= $to : $n < $to))),
                    ];
                }
            }
            break;
        default:
            $stats['values'] = array_values($values);
    }
    return $stats;
}

/**
 * Lignes de reponses pretes a afficher ou exporter, dans l'ordre des champs.
 */
function form_table(array $fields, array $responses, array $answers) {
    $byResponse = [];
    foreach ($answers as $answer) {
        $byResponse[$answer['response_id']][$answer['field_id']] = $answer['value'];
    }
    return array_map(function ($response) use ($fields, $byResponse) {
        $cells = [];
        foreach ($fields as $field) {
            $cells[] = form_display_value($field, $byResponse[$response['id']][$field['id']] ?? null);
        }
        return ['id' => (int) $response['id'], 'created_at' => $response['created_at'], 'cells' => $cells];
    }, $responses);
}

/**
 * Un tableur execute une cellule qui commence par = + - @ : une reponse ne doit
 * pas pouvoir s'y transformer en formule.
 */
function form_safe_cell($value) {
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value) ? "'" . $value : $value;
}

function form_export_header(array $fields) {
    return array_merge(['Date'], array_map(fn($field) => $field['label'], $fields));
}

function form_export_csv(array $fields, array $rows) {
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('form_safe_cell', form_export_header($fields)), ';', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('form_safe_cell', array_merge([$row['created_at']], $row['cells'])), ';', '"', '');
    }
    rewind($out);
    return stream_get_contents($out);
}

function form_export_excel(array $form, array $fields, array $rows) {
    $cell = fn($value) => '<Cell><Data ss:Type="String">' . htmlspecialchars(form_safe_cell($value), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
    $xmlRow = fn(array $values) => '<Row>' . implode('', array_map($cell, $values)) . "</Row>\n";

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n"
        . '<Worksheet ss:Name="Réponses"><Table>' . "\n"
        . $xmlRow([$form['title']])
        . $xmlRow(form_export_header($fields));
    foreach ($rows as $row) {
        $xml .= $xmlRow(array_merge([$row['created_at']], $row['cells']));
    }
    return $xml . "</Table></Worksheet>\n</Workbook>\n";
}

function form_export_pdf(array $form, array $fields, array $rows, array $stats) {
    require_once __DIR__ . '/../utils/SimplePDF.php';
    require_once __DIR__ . '/../utils/PdfLetterhead.php';

    $pdf = new SimplePDF();
    pdf_letterhead($pdf, $form['title']);

    $pdf->setFont(10);
    $pdf->text(count($rows) . ' réponse' . (count($rows) > 1 ? 's' : ''));
    $pdf->moveDown(8);

    $keep = function ($points) use ($pdf) {
        if ($pdf->cursorY - $points < $pdf->marginBottom) $pdf->addPage();
    };

    $charted = array_filter($stats, fn($item) => !empty($item['series']));
    if ($charted) {
        $pdf->heading('Synthèse', 12);
        $pdf->moveDown(4);
        foreach ($charted as $item) {
            $keep(40);
            $pdf->setFont(10, true);
            $pdf->paragraph($item['label']);
            $pdf->setFont(10);
            foreach ($item['series'] as $point) {
                $pdf->text('    ' . $point['label'] . ' : ' . $point['count']);
            }
            if (isset($item['average'])) {
                $pdf->text('    Moyenne : ' . number_format($item['average'], 2, ',', ' '));
            }
            $pdf->moveDown(6);
        }
        $pdf->moveDown(4);
    }

    $pdf->heading('Réponses', 12);
    $pdf->moveDown(4);
    foreach ($rows as $index => $row) {
        $keep(40);
        $pdf->setFont(10, true);
        $pdf->text('Réponse ' . ($index + 1) . ' — ' . $row['created_at']);
        $pdf->setFont(10);
        foreach ($fields as $position => $field) {
            if ($row['cells'][$position] === '') continue;
            $keep(28);
            $pdf->paragraph($field['label'] . ' : ' . $row['cells'][$position]);
        }
        $pdf->moveDown(8);
    }

    return $pdf->output();
}

function form_slug($title) {
    // Le suffixe evite qu'on devine l'adresse d'un formulaire a partir de son titre
    return substr(project_slug($title), 0, 100) . '-' . bin2hex(random_bytes(3));
}

function form_load_fields($db, $formId) {
    return $db->fetchAll(
        "SELECT id, type, label, help_text, required, options, position, hidden, chart
         FROM form_fields WHERE form_id = ? ORDER BY position, id",
        [$formId]
    );
}

function form_count_responses($db, $formId) {
    return (int) $db->fetchOne("SELECT COUNT(*) AS total FROM form_responses WHERE form_id = ?", [$formId])['total'];
}

function form_or_404($db, $id) {
    $form = $db->fetchOne("SELECT * FROM forms WHERE id = ? LIMIT 1", [$id]);
    if (!$form) Response::notFound('Formulaire introuvable');
    return $form;
}

/**
 * Enregistre les champs valides par form_normalize_fields.
 */
function form_save_fields($db, $formId, array $fields, array $removed) {
    foreach ($removed as $id) {
        $db->query("DELETE FROM form_fields WHERE id = ? AND form_id = ?", [$id, $formId]);
    }
    foreach ($fields as $field) {
        $values = [$field['type'], $field['label'], $field['help_text'], $field['required'], $field['options'], $field['position'], $field['hidden'], $field['chart']];
        if ($field['id'] === null) {
            $db->query(
                "INSERT INTO form_fields (type, label, help_text, required, options, position, hidden, chart, form_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge($values, [$formId])
            );
        } else {
            $db->query(
                "UPDATE form_fields SET type = ?, label = ?, help_text = ?, required = ?, options = ?, position = ?, hidden = ?, chart = ?
                 WHERE id = ? AND form_id = ?",
                array_merge($values, [$field['id'], $formId])
            );
        }
    }
}

$router = Router::getInstance();
$db = Database::getInstance();

// ---------- Public ----------

$router->get('\/forms/public/:slug', function($params) use ($db) {
    try {
        $form = $db->fetchOne(
            "SELECT id, title, intro, thanks_message, status, closes_at, one_per_device FROM forms WHERE slug = ? LIMIT 1",
            [$params['slug']]
        );
        if (!$form || $form['status'] === 'draft') {
            Response::notFound('Formulaire introuvable');
        }

        $state = form_state($form, form_now());
        $data = ['title' => $form['title'], 'state' => $state];
        if ($state === 'open') {
            $data['intro'] = $form['intro'];
            $data['one_per_device'] = (int) $form['one_per_device'];
            $data['fields'] = array_map(fn($field) => [
                'id' => (int) $field['id'],
                'type' => $field['type'],
                'label' => $field['label'],
                'help_text' => $field['help_text'],
                'required' => (int) $field['required'],
                'options' => form_field_options($field),
            ], array_values(array_filter(form_load_fields($db, $form['id']), fn($field) => !$field['hidden'])));
        }
    } catch (Exception $e) {
        Response::error('Formulaire indisponible pour le moment', 500);
    }
    Response::success($data);
});

$router->post('\/forms/public/:slug/responses', function($params) use ($db) {
    $body = Router::getBody();

    // Piege a robots : un champ invisible que seul un script remplit
    if (!empty($body['website'])) {
        Response::success(null, 'Réponse enregistrée', 201);
    }

    try {
        $form = $db->fetchOne(
            "SELECT id, status, closes_at, one_per_device, thanks_message FROM forms WHERE slug = ? LIMIT 1",
            [$params['slug']]
        );
        if (!$form || $form['status'] === 'draft') {
            Response::notFound('Formulaire introuvable');
        }
        if (form_state($form, form_now()) !== 'open') {
            Response::error("Ce formulaire n'accepte plus de réponses", 403);
        }

        $deviceHash = null;
        if ($form['one_per_device']) {
            $token = $body['device_token'] ?? '';
            if (!is_string($token) || !preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)) {
                Response::error("Votre navigateur n'a pas pu être identifié. Rechargez la page et réessayez.", 400);
            }
            $deviceHash = form_device_hash($form['id'], $token);
        }

        [$values, $error] = form_validate_answers(form_load_fields($db, $form['id']), $body['answers'] ?? []);
        if ($error !== null) {
            Response::error($error, 400);
        }

        if (rate_limited($db, 'form', FORM_MAX_PER_HOUR, 3600)) {
            Response::error('Trop de réponses envoyées depuis cette connexion. Réessayez plus tard.', 429);
        }

        $db->beginTransaction();
        try {
            $db->query("INSERT INTO form_responses (form_id, device_hash) VALUES (?, ?)", [$form['id'], $deviceHash]);
            $responseId = $db->lastInsertId();
            foreach ($values as $fieldId => $value) {
                $db->query("INSERT INTO form_answers (response_id, field_id, value) VALUES (?, ?, ?)", [$responseId, $fieldId, $value]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            Response::error('Une réponse a déjà été envoyée depuis cet appareil', 409);
        }
        Response::error("La réponse n'a pas pu être enregistrée. Réessayez dans un instant.", 500);
    }

    Response::success(['thanks_message' => $form['thanks_message']], 'Réponse enregistrée', 201);
});

// ---------- Administration ----------

$router->get('\/forms', function($params) use ($db) {
    try {
        Response::success($db->fetchAll(
            "SELECT f.id, f.project_id, f.slug, f.title, f.status, f.closes_at, f.one_per_device, p.name AS project_name,
                    (SELECT COUNT(*) FROM form_responses r WHERE r.form_id = f.id) AS response_count
             FROM forms f JOIN projects p ON p.id = f.project_id
             ORDER BY f.created_at DESC, f.id DESC"
        ));
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

$router->post('\/forms', function($params) use ($db) {
    $body = Router::getBody();
    [$meta, $error] = form_validate_meta($body);
    if ($error !== null) {
        Response::error($error, 400);
    }
    [$fields, , $error] = form_normalize_fields($body['fields'] ?? [], [], false);
    if ($error !== null) {
        Response::error($error, 400);
    }

    try {
        $db->beginTransaction();
        $columns = array_keys($meta);
        $db->query(
            "INSERT INTO forms (slug, " . implode(', ', $columns) . ") VALUES (?" . str_repeat(', ?', count($columns)) . ")",
            array_merge([form_slug($meta['title'])], array_values($meta))
        );
        $id = (int) $db->lastInsertId();
        form_save_fields($db, $id, $fields, []);
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        Response::error('Création impossible : ' . $e->getMessage(), 500);
    }
    Response::success(['id' => $id], 'Formulaire créé', 201);
}, ['adminMiddleware']);

$router->get('\/forms/:id', function($params) use ($db) {
    try {
        $form = form_or_404($db, $params['id']);
        $form['fields'] = array_map(function ($field) {
            $field['options'] = form_field_options($field);
            return $field;
        }, form_load_fields($db, $form['id']));
        $form['response_count'] = form_count_responses($db, $form['id']);
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
    Response::success($form);
}, ['adminMiddleware']);

$router->put('\/forms/:id', function($params) use ($db) {
    $body = Router::getBody();
    [$meta, $error] = form_validate_meta($body);
    if ($error !== null) {
        Response::error($error, 400);
    }

    try {
        $form = form_or_404($db, $params['id']);
        [$fields, $removed, $error] = form_normalize_fields(
            $body['fields'] ?? [],
            form_load_fields($db, $form['id']),
            form_count_responses($db, $form['id']) > 0
        );
        if ($error !== null) {
            Response::error($error, 400);
        }

        $db->beginTransaction();
        $assignments = implode(', ', array_map(fn($column) => "{$column} = ?", array_keys($meta)));
        $db->query("UPDATE forms SET {$assignments} WHERE id = ?", array_merge(array_values($meta), [$form['id']]));
        form_save_fields($db, $form['id'], $fields, $removed);
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Formulaire modifié');
}, ['adminMiddleware']);

$router->put('\/forms/:id/status', function($params) use ($db) {
    $status = Router::getBody()['status'] ?? '';
    if (!in_array($status, ['open', 'suspended', 'draft'], true)) {
        Response::error('Statut invalide', 400);
    }
    try {
        form_or_404($db, $params['id']);
        $db->query("UPDATE forms SET status = ? WHERE id = ?", [$status, $params['id']]);
    } catch (Exception $e) {
        Response::error('Modification impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Statut modifié');
}, ['adminMiddleware']);

$router->delete('\/forms/responses/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM form_responses WHERE id = ?", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Réponse supprimée');
}, ['adminMiddleware']);

$router->delete('\/forms/:id', function($params) use ($db) {
    try {
        $db->query("DELETE FROM forms WHERE id = ?", [$params['id']]);
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
    Response::success(null, 'Formulaire supprimé');
}, ['adminMiddleware']);

/**
 * Tout ce qu'il faut pour lire les reponses : tableau et agregats.
 */
function form_results($db, array $form) {
    $fields = form_load_fields($db, $form['id']);
    $responses = $db->fetchAll(
        "SELECT id, created_at FROM form_responses WHERE form_id = ? ORDER BY created_at DESC, id DESC",
        [$form['id']]
    );
    $answers = $db->fetchAll(
        "SELECT a.response_id, a.field_id, a.value
         FROM form_answers a JOIN form_responses r ON r.id = a.response_id
         WHERE r.form_id = ?",
        [$form['id']]
    );

    $byField = [];
    foreach ($answers as $answer) {
        $byField[$answer['field_id']][] = $answer['value'];
    }
    $stats = array_map(fn($field) => form_field_stats($field, $byField[$field['id']] ?? []), $fields);

    return [$fields, form_table($fields, $responses, $answers), $stats];
}

$router->get('\/forms/:id/responses', function($params) use ($db) {
    try {
        $form = form_or_404($db, $params['id']);
        [$fields, $rows, $stats] = form_results($db, $form);
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
    Response::success([
        'title' => $form['title'],
        'columns' => array_map(fn($field) => $field['label'], $fields),
        'rows' => $rows,
        'stats' => $stats,
    ]);
}, ['adminMiddleware']);

$router->get('\/forms/:id/export', function($params) use ($db) {
    $format = strtolower((string) Router::getQueryParam('format', 'csv'));
    if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
        Response::error('Format invalide (csv, excel ou pdf)', 400);
    }

    try {
        $form = form_or_404($db, $params['id']);
        [$fields, $rows, $stats] = form_results($db, $form);
        $name = 'reponses-' . substr(project_slug($form['title']), 0, 60) . '-' . date('Y-m-d');

        if ($format === 'csv') {
            $content = form_export_csv($fields, $rows);
            [$type, $extension] = ['text/csv; charset=utf-8', 'csv'];
        } elseif ($format === 'excel') {
            $content = form_export_excel($form, $fields, $rows);
            [$type, $extension] = ['application/vnd.ms-excel; charset=utf-8', 'xls'];
        } else {
            $content = form_export_pdf($form, $fields, $rows, $stats);
            [$type, $extension] = ['application/pdf', 'pdf'];
        }
    } catch (Exception $e) {
        Response::error('Export impossible : ' . $e->getMessage(), 500);
    }

    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '.' . $extension . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}, ['adminMiddleware']);
