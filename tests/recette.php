<?php
/**
 * Recette de l'API contre une vraie base — ECRIT DANS LA BASE.
 * Refuse tout autre serveur que la machine locale.
 *
 *     php -d upload_max_filesize=110M -d post_max_size=120M -S 127.0.0.1:8770 -t . tests/recette_routeur.php
 *     php tests/recette.php http://127.0.0.1:8770 admin@exemple.org motdepasse
 *
 * Attend une base neuve : structure et migrations de api/sql, un compte admin.
 */

[$script, $base, $email, $password] = $argv + [null, null, null, null];
if (!$password || !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    fwrite(STDERR, "Usage : php tests/recette.php http://127.0.0.1:PORT email motdepasse (machine locale uniquement)\n");
    exit(2);
}

$failures = 0;
$total = 0;
function check($label, $ok, $detail = null) {
    global $failures, $total;
    clearstatcache(true);
    $total++;
    if (!$ok) {
        $failures++;
        echo " FAIL {$label}" . ($detail !== null ? ' : ' . substr(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300) : '') . "\n";
    }
}
function section($title) { echo "== {$title}\n"; }

$allRaw = '';
function req($method, $path, $body = null, $token = null, $files = null) {
    global $base, $allRaw;
    clearstatcache(true);
    $headers = [];
    if ($token) $headers[] = "Authorization: Bearer {$token}";
    $content = null;
    if ($files !== null) {
        $boundary = 'recette' . bin2hex(random_bytes(8));
        $headers[] = "Content-Type: multipart/form-data; boundary={$boundary}";
        $content = '';
        foreach ((array) $body as $key => $value) {
            $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as $key => [$name, $data, $type]) {
            $content .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"; filename=\"{$name}\"\r\nContent-Type: {$type}\r\n\r\n{$data}\r\n";
        }
        $content .= "--{$boundary}--\r\n";
    } elseif ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'ignore_errors' => true, 'timeout' => 30]]);
    $raw = @file_get_contents($base . '/api' . $path, false, $context);
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $status = (int) $m[1];
        else $responseHeaders[strtolower(strtok($line, ':'))] = trim(substr($line, strpos($line, ':') + 1));
    }
    $allRaw .= "\n" . $raw;
    return ['status' => $status, 'raw' => (string) $raw, 'json' => json_decode((string) $raw, true), 'headers' => $responseHeaders];
}
function data($response) { return $response['json']['data'] ?? null; }
function status_is($label, $response, $expected) { check("{$label} : {$expected}", $response['status'] === $expected, "reçu {$response['status']} " . $response['raw']); }

$root = dirname(__DIR__);

section('Connexion');
status_is('mauvais mot de passe', req('POST', '/auth/login', ['email' => $email, 'password' => 'faux']), 401);
$login = req('POST', '/auth/login', ['email' => $email, 'password' => $password]);
status_is('connexion', $login, 200);
$token = data($login)['token'] ?? null;
check('jeton reçu', (bool) $token);
foreach (['/projects/all', '/forms', '/contact', '/partners/all', '/project-testimonials', '/admin/users', '/admin/dashboard/stats', '/attendance'] as $path) {
    status_is("GET {$path} sans jeton", req('GET', $path), 401);
}

section('Organisation');
$org = req('GET', '/organization');
status_is('lecture publique', $org, 200);
check('coordonnées de la migration', data($org)['phone'] === '+509 47 41 8737' && data($org)['address'] === '190, rue 15-16 B, Cap-Haïtien, Haïti', data($org));
check('trois fondateurs dans l\'ordre', array_column(data($org)['founders'], 'name') === ['Rodely JEAN', 'Fabrice SAINTILMA', 'Gueslander Lynx LAURENT'], data($org));
status_is('modification', req('PUT', '/organization', ['name' => 'DevDynamics', 'email' => 'contact@dev-dynamics.org', 'phone' => '+509 47 41 8737', 'address' => 'Adresse modifiée', 'mission' => 'M', 'description' => "Un.\n\nDeux."], $token), 200);
check('modification relue', data(req('GET', '/organization'))['address'] === 'Adresse modifiée');
status_is('téléphone trop long', req('PUT', '/organization', ['name' => 'D', 'email' => 'a@b.org', 'phone' => str_repeat('1', 21)], $token), 400);
status_is('ajout fondateur', req('POST', '/founders', ['name' => 'Test', 'role' => 'R', 'order_position' => 9], $token), 201);
$founders = data(req('GET', '/organization'))['founders'];
$testFounder = end($founders);
status_is('modif fondateur', req('PUT', "/founders/{$testFounder['id']}", ['name' => 'Test2', 'role' => 'R2', 'order_position' => 9], $token), 200);
status_is('suppr fondateur', req('DELETE', "/founders/{$testFounder['id']}", null, $token), 200);
check('fondateur retiré', count(data(req('GET', '/organization'))['founders']) === 3);

section('Projets');
$public = req('GET', '/projects');
status_is('liste publique', $public, 200);
check('7 réalisations, Essor en tête', count(data($public)) === 7 && data($public)[0]['slug'] === 'essor', data($public));
check('le statut ne sort pas', !array_key_exists('status', data($public)[0]));
status_is('fiche publique', req('GET', '/projects/zonpam'), 200);
status_is('fiche absente', req('GET', '/projects/absent'), 404);
$draft = ['name' => 'Été à l\'école', 'type' => 'formation', 'status' => 'draft', 'summary' => 'Résumé accentué', 'description' => 'D', 'participants' => '20', 'start_date' => '2026-01-01', 'end_date' => '2026-02-01', 'external_url' => 'https://exemple.org'];
$created = req('POST', '/projects', $draft, $token);
status_is('création', $created, 201);
check('adresse courte sans accent', data($created)['slug'] === 'ete-a-l-ecole', data($created));
$dup = req('POST', '/projects', $draft, $token);
status_is('même nom', $dup, 201);
check('seconde adresse distincte', data($dup)['slug'] === 'ete-a-l-ecole-2', data($dup));
status_is('brouillon invisible (fiche)', req('GET', '/projects/ete-a-l-ecole'), 404);
check('brouillon invisible (liste)', !in_array('ete-a-l-ecole', array_column(data(req('GET', '/projects')), 'slug'), true));
$all = data(req('GET', '/projects/all', null, $token));
$byslug = array_column($all, null, 'slug');
$pid = (int) $byslug['ete-a-l-ecole']['id'];
$pid2 = (int) $byslug['ete-a-l-ecole-2']['id'];
check('accents enregistrés', $byslug['ete-a-l-ecole']['name'] === 'Été à l\'école', $byslug['ete-a-l-ecole']);
status_is('lien javascript refusé', req('POST', '/projects', ['external_url' => 'javascript:alert(1)'] + $draft, $token), 400);
status_is('publication', req('PUT', "/projects/{$pid}", ['status' => 'published', 'name' => 'Nom changé'] + $draft, $token), 200);
$fiche = req('GET', '/projects/ete-a-l-ecole');
status_is('publié visible, adresse stable', $fiche, 200);
check('modification relue', data($fiche)['name'] === 'Nom changé' && (int) data($fiche)['participants'] === 20, data($fiche));
status_is('modifier un absent', req('PUT', '/projects/999999', $draft, $token), 404);
status_is('suppression du doublon', req('DELETE', "/projects/{$pid2}", null, $token), 200);

section('Images');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==');
status_is('envoi sans jeton', req('POST', '/uploads', [], null, ['file' => ['a.png', $png, 'image/png']]), 401);
$up = req('POST', '/uploads', [], $token, ['file' => ['a.png', $png, 'image/png']]);
status_is('envoi PNG', $up, 201);
$image = data($up)['path'] ?? '';
check('fichier sur le disque, nom aléatoire', preg_match('#^uploads/[a-f0-9]{32}\.png$#', $image) && is_file("{$root}/{$image}"), $up);
status_is('PHP déguisé refusé', req('POST', '/uploads', [], $token, ['file' => ['a.png', '<?php echo 1;', 'image/png']]), 400);
status_is('SVG refusé', req('POST', '/uploads', [], $token, ['file' => ['a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml']]), 400);
status_is('image sur un projet', req('PUT', "/projects/{$pid}", ['status' => 'published', 'image_url' => $image] + $draft, $token), 200);
check('image relue', data(req('GET', '/projects/ete-a-l-ecole'))['image_url'] === $image);

section('Formulaires');
$fields = [
    ['type' => 'text', 'label' => 'Nom', 'required' => 1],
    ['type' => 'textarea', 'label' => 'Commentaire'],
    ['type' => 'number', 'label' => 'Âge', 'chart' => 'bar'],
    ['type' => 'date', 'label' => 'Date'],
    ['type' => 'single', 'label' => 'Un choix', 'options' => ['A', 'B'], 'chart' => 'pie'],
    ['type' => 'multiple', 'label' => 'Plusieurs', 'options' => ['X', 'Y', 'Z'], 'chart' => 'bar'],
    ['type' => 'select', 'label' => 'Liste', 'options' => ['L1', 'L2']],
    ['type' => 'scale', 'label' => 'Note', 'chart' => 'bar'],
    ['type' => 'yesno', 'label' => 'Recommandez-vous ?', 'chart' => 'pie'],
];
$meta = ['project_id' => $pid, 'title' => 'Enquête été', 'intro' => 'Intro', 'thanks_message' => 'Merci à vous', 'closes_at' => '', 'one_per_device' => 1];
status_is('sans question', req('POST', '/forms', $meta + ['fields' => []], $token), 400);
status_is('projet inexistant', req('POST', '/forms', ['project_id' => 999999] + $meta + ['fields' => $fields], $token), 400);
$form = req('POST', '/forms', $meta + ['fields' => $fields], $token);
status_is('création', $form, 201);
$fid = (int) (data($form)['id'] ?? 0);
$detail = data(req('GET', "/forms/{$fid}", null, $token));
check('9 questions dans l\'ordre', array_column($detail['fields'], 'label') === array_column($fields, 'label'), $detail['fields'] ?? null);
check('options relues en liste', $detail['fields'][4]['options'] === ['A', 'B'], $detail['fields'][4]);
$slug = $detail['slug'];
$ids = array_column($detail['fields'], 'id', 'label');
status_is('brouillon introuvable', req('GET', "/forms/public/{$slug}"), 404);
status_is('ouverture', req('PUT', "/forms/{$fid}/status", ['status' => 'open'], $token), 200);
$pub = req('GET', "/forms/public/{$slug}");
status_is('page publique', $pub, 200);
check('état ouvert et 9 questions', data($pub)['state'] === 'open' && count(data($pub)['fields']) === 9, data($pub));
check('pas de slug ni d\'id de formulaire exposés en trop', !isset(data($pub)['id']));

$answer = function ($name, $choice, $age, $scale, $yes) use ($ids) {
    return [$ids['Nom'] => $name, $ids['Commentaire'] => 'Très bien', $ids['Âge'] => $age, $ids['Date'] => '2026-11-04', $ids['Un choix'] => $choice,
            $ids['Plusieurs'] => ['X', 'Z'], $ids['Liste'] => 'L2', $ids['Note'] => $scale, $ids['Recommandez-vous ?'] => $yes];
};
$token1 = str_repeat('a', 32);
$send = fn($answers, $device) => req('POST', "/forms/public/{$slug}/responses", ['answers' => $answers, 'device_token' => $device]);
status_is('obligatoire vide', $send([$ids['Commentaire'] => 'x'], $token1), 400);
status_is('sans appareil', req('POST', "/forms/public/{$slug}/responses", ['answers' => $answer('M', 'A', '20', '4', 'oui')]), 400);
$r = $send($answer('=Marié', 'A', '20', '4', 'oui'), $token1);
status_is('première réponse', $r, 201);
check('remerciement rendu', data($r)['thanks_message'] === 'Merci à vous', $r['raw']);
status_is('même appareil refusé', $send($answer('Autre', 'B', '30', '2', 'non'), $token1), 409);
status_is('autre appareil', $send($answer('Jean', 'B', '30', '2', 'non'), str_repeat('b', 32)), 201);
status_is('troisième', $send($answer('Ana', 'A', '40', '5', 'oui'), str_repeat('c', 32)), 201);

section('Règles après réponses');
$saved = data(req('GET', "/forms/{$fid}", null, $token));
$asInput = array_map(fn($f) => ['id' => $f['id'], 'type' => $f['type'], 'label' => $f['label'], 'options' => $f['options'], 'required' => $f['required'], 'hidden' => $f['hidden'], 'chart' => $f['chart']], $saved['fields']);
check('compteur de réponses', (int) $saved['response_count'] === 3, $saved['response_count']);
$without = array_slice($asInput, 1);
status_is('retirer une question', req('PUT', "/forms/{$fid}", $meta + ['fields' => $without], $token), 400);
$changed = $asInput; $changed[0]['type'] = 'number';
status_is('changer le type', req('PUT', "/forms/{$fid}", $meta + ['fields' => $changed], $token), 400);
$changed = $asInput; $changed[4]['options'] = ['A'];
status_is('retirer un choix', req('PUT', "/forms/{$fid}", $meta + ['fields' => $changed], $token), 400);
$changed = $asInput; $changed[4]['options'] = ['A', 'B', 'C']; $changed[1]['hidden'] = 1; $changed[0]['label'] = 'Votre nom'; $changed[] = ['type' => 'yesno', 'label' => 'Nouvelle'];
status_is('ajouter, masquer, renommer', req('PUT', "/forms/{$fid}", $meta + ['fields' => $changed], $token), 200);
$after = data(req('GET', "/forms/{$fid}", null, $token));
check('10 questions, libellé changé', count($after['fields']) === 10 && $after['fields'][0]['label'] === 'Votre nom', $after['fields']);
$publicNow = data(req('GET', "/forms/public/{$slug}"));
check('question masquée absente du public', !in_array('Commentaire', array_column($publicNow['fields'], 'label'), true) && count($publicNow['fields']) === 9, $publicNow);

// Comme le constructeur : on renvoie toujours les questions avec leur identifiant
$current = array_map(fn($f) => ['id' => $f['id'], 'type' => $f['type'], 'label' => $f['label'], 'options' => $f['options'], 'required' => $f['required'], 'hidden' => $f['hidden'], 'chart' => $f['chart']], $after['fields']);

section('Réponses, graphiques, exports');
$res = data(req('GET', "/forms/{$fid}/responses", null, $token));
check('3 lignes, 10 colonnes', count($res['rows']) === 3 && count($res['columns']) === 10, $res);
check('réponse masquée toujours lisible', in_array('Très bien', $res['rows'][0]['cells'], true), $res['rows'][0]);
$stats = array_column($res['stats'], null, 'label');
check('choix : A = 2, B = 1, C = 0', array_column($stats['Un choix']['series'], 'count', 'label') === ['A' => 2, 'B' => 1, 'C' => 0], $stats['Un choix']);
check('multiple : X = 3, Y = 0, Z = 3', array_column($stats['Plusieurs']['series'], 'count', 'label') === ['X' => 3, 'Y' => 0, 'Z' => 3], $stats['Plusieurs']);
check('échelle : moyenne 3,67', $stats['Note']['average'] === 3.67, $stats['Note']);
check('nombre : 20 à 40, moyenne 30', $stats['Âge']['min'] == 20 && $stats['Âge']['max'] == 40 && $stats['Âge']['average'] == 30, $stats['Âge']);
check('oui/non lisible', array_column($stats['Recommandez-vous ?']['series'], 'count', 'label') === ['Oui' => 2, 'Non' => 1], $stats['Recommandez-vous ?']);

$csv = req('GET', "/forms/{$fid}/export?format=csv", null, $token);
status_is('CSV', $csv, 200);
check('CSV type, BOM, accents, formule neutralisée', str_contains($csv['headers']['content-type'] ?? '', 'text/csv') && str_starts_with($csv['raw'], "\xEF\xBB\xBF") && str_contains($csv['raw'], 'Âge') && str_contains($csv['raw'], "'=Marié"), $csv['raw']);
check('CSV : nom de fichier', str_contains($csv['headers']['content-disposition'] ?? '', 'attachment') && str_contains($csv['headers']['content-disposition'] ?? '', '.csv'), $csv['headers']);
$xls = req('GET', "/forms/{$fid}/export?format=excel", null, $token);
status_is('Excel', $xls, 200);
check('Excel : XML valide', @simplexml_load_string($xls['raw']) !== false, substr($xls['raw'], 0, 200));
$pdf = req('GET', "/forms/{$fid}/export?format=pdf", null, $token);
status_is('PDF', $pdf, 200);
check('PDF : document et logo', str_starts_with($pdf['raw'], '%PDF-') && str_contains($pdf['raw'], '/DCTDecode') && ($pdf['headers']['content-type'] ?? '') === 'application/pdf', substr($pdf['raw'], 0, 50));
file_put_contents(sys_get_temp_dir() . '/recette-formulaire.pdf', $pdf['raw']);
status_is('format inconnu', req('GET', "/forms/{$fid}/export?format=doc", null, $token), 400);
status_is('export sans jeton', req('GET', "/forms/{$fid}/export?format=csv"), 401);

section('Suspension et fermeture');
status_is('suspendre', req('PUT', "/forms/{$fid}/status", ['status' => 'suspended'], $token), 200);
$sus = data(req('GET', "/forms/public/{$slug}"));
check('page suspendue, sans questions', $sus['state'] === 'suspended' && !isset($sus['fields']), $sus);
status_is('envoi refusé', $send($answer('X', 'A', '1', '1', 'oui'), str_repeat('d', 32)), 403);
status_is('rouvrir', req('PUT', "/forms/{$fid}/status", ['status' => 'open'], $token), 200);
status_is('date passée', req('PUT', "/forms/{$fid}", ['closes_at' => '2020-01-01T10:00'] + $meta + ['fields' => $current], $token), 200);
check('page fermée', data(req('GET', "/forms/public/{$slug}"))['state'] === 'closed');
status_is('envoi refusé après la date', $send($answer('X', 'A', '1', '1', 'oui'), str_repeat('d', 32)), 403);
status_is('date future', req('PUT', "/forms/{$fid}", ['closes_at' => '2099-01-01T10:00'] + $meta + ['fields' => $current], $token), 200);
check('page rouverte', data(req('GET', "/forms/public/{$slug}"))['state'] === 'open');
$noDevice = ['one_per_device' => 0] + $meta;
status_is('contrôle désactivé', req('PUT', "/forms/{$fid}", $noDevice + ['fields' => $current], $token), 200);
$a = $answer('Partagé', 'A', '5', '1', 'oui'); unset($a[$ids['Commentaire']]);
status_is('téléphone partagé : 1', req('POST', "/forms/public/{$slug}/responses", ['answers' => $a]), 201);
status_is('téléphone partagé : 2', req('POST', "/forms/public/{$slug}/responses", ['answers' => $a]), 201);
$rows = data(req('GET', "/forms/{$fid}/responses", null, $token))['rows'];
check('5 réponses', count($rows) === 5, count($rows));
status_is('supprimer une réponse', req('DELETE', "/forms/responses/{$rows[0]['id']}", null, $token), 200);
check('reste 4 réponses, liste admin à jour', (int) (array_column(data(req('GET', '/forms', null, $token)), 'response_count', 'id')[$fid] ?? 0) === 4);

section('Protection des projets');
status_is('projet avec formulaire protégé', req('DELETE', "/projects/{$pid}", null, $token), 409);

section('Témoignages');
$mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 256);
$depot = ['name' => 'Marie', 'role_title' => 'Étudiante', 'content' => 'Très utile <b>vraiment</b>', 'consent' => 1];
status_is('collecte fermée', req('POST', '/project-testimonials/collect/ete-a-l-ecole', $depot), 403);
check('état fermé', (int) data(req('GET', '/project-testimonials/collect/ete-a-l-ecole'))['open'] === 0);
status_is('ouvrir la collecte', req('PUT', "/projects/{$pid}/testimonials", ['open' => true], $token), 200);
status_is('sans accord', req('POST', '/project-testimonials/collect/ete-a-l-ecole', ['consent' => 0] + $depot), 400);
status_is('lien non YouTube', req('POST', '/project-testimonials/collect/ete-a-l-ecole', ['video_url' => 'https://evil.org/x'] + $depot), 400);
status_is('dépôt simple', req('POST', '/project-testimonials/collect/ete-a-l-ecole', ['video_url' => 'https://youtu.be/dQw4w9WgXcQ'] + $depot), 201);
status_is('dépôt avec vidéo', req('POST', '/project-testimonials/collect/ete-a-l-ecole', ['name' => 'Jean', 'content' => 'Avec vidéo', 'consent' => '1'], null, ['video' => ['ma video.mp4', $mp4, 'video/mp4']]), 201);
status_is('faux fichier vidéo', req('POST', '/project-testimonials/collect/ete-a-l-ecole', ['name' => 'Faux', 'content' => 'x', 'consent' => '1'], null, ['video' => ['a.mp4', '<?php echo 1;', 'video/mp4']]), 400);
$list = data(req('GET', '/project-testimonials', null, $token));
check('2 dépôts en attente', count($list) === 2 && array_unique(array_column($list, 'status')) === ['pending'], $list);
$byName = array_column($list, null, 'name');
$video = $byName['Jean'];
$file = $video['video_file'];
check('nom aléatoire, dossier fermé', preg_match('/^[a-f0-9]{32}\.mp4$/', $file) && is_file("{$root}/uploads/videos-attente/{$file}") && !is_file("{$root}/uploads/videos/{$file}"), $video);
check('rien de public avant validation', data(req('GET', '/project-testimonials/project/ete-a-l-ecole')) === []);
$read = req('GET', "/project-testimonials/{$video['id']}/video", null, $token);
status_is('admin lit la vidéo', $read, 200);
check('type vidéo, contenu intact', ($read['headers']['content-type'] ?? '') === 'video/mp4' && $read['raw'] === $mp4, $read['headers']);
status_is('vidéo sans jeton', req('GET', "/project-testimonials/{$video['id']}/video"), 401);
status_is('publier', req('PUT', "/project-testimonials/{$video['id']}", ['status' => 'approved', 'featured' => true], $token), 200);
check('la vidéo devient publique', is_file("{$root}/uploads/videos/{$file}") && !is_file("{$root}/uploads/videos-attente/{$file}"));
$pubT = data(req('GET', '/project-testimonials/project/ete-a-l-ecole'));
check('visible sur la fiche', count($pubT) === 1 && $pubT[0]['video_file'] === $file, $pubT);
check("visible sur l'accueil", count(data(req('GET', '/project-testimonials/featured'))) === 1);
status_is('admin lit encore après publication', req('GET', "/project-testimonials/{$video['id']}/video", null, $token), 200);
status_is('refuser', req('PUT', "/project-testimonials/{$video['id']}", ['status' => 'rejected', 'featured' => true], $token), 200);
check('la vidéo redevient privée', !is_file("{$root}/uploads/videos/{$file}") && is_file("{$root}/uploads/videos-attente/{$file}"));
check("retiré du public et de l'accueil", data(req('GET', '/project-testimonials/project/ete-a-l-ecole')) === [] && data(req('GET', '/project-testimonials/featured')) === []);
$replaced = req('POST', "/project-testimonials/{$video['id']}/video", [], $token, ['video' => ['b.mp4', $mp4, 'video/mp4']]);
status_is('admin remplace', $replaced, 201);
check("l'ancien fichier disparaît", !is_file("{$root}/uploads/videos-attente/{$file}"));
$other = $byName['Marie'];
status_is('admin ajoute une vidéo', req('POST', "/project-testimonials/{$other['id']}/video", [], $token, ['video' => ['c.mp4', $mp4, 'video/mp4']]), 201);
status_is('retirer la vidéo', req('DELETE', "/project-testimonials/{$other['id']}/video", null, $token), 200);
check('colonne vidée', array_column(data(req('GET', '/project-testimonials', null, $token)), 'video_file', 'name')['Marie'] === null);
status_is('projet avec témoignage protégé (après formulaire)', req('DELETE', "/forms/{$fid}", null, $token), 200);
status_is('témoignages encore là : projet protégé', req('DELETE', "/projects/{$pid}", null, $token), 409);
$again = data(req('GET', '/project-testimonials', null, $token));
$left = array_column($again, 'video_file', 'id')[$video['id']];
foreach ($again as $item) {
    status_is('suppression témoignage', req('DELETE', "/project-testimonials/{$item['id']}", null, $token), 200);
}
check('le fichier part avec le témoignage', !is_file("{$root}/uploads/videos-attente/{$left}"));
status_is('projet libre : supprimé', req('DELETE', "/projects/{$pid}", null, $token), 200);

section('Partenaires');
$partners = req('GET', '/partners');
status_is('lecture publique', $partners, 200);
check('Alliance Française, sans lien', data($partners)[0]['name'] === 'Alliance Française du Cap-Haïtien' && array_keys(data($partners)[0]) === ['name', 'logo_url'], data($partners));
status_is('ajout', req('POST', '/partners', ['name' => 'Second', 'logo_url' => $image, 'is_active' => 1], $token), 201);
status_is('doublon', req('POST', '/partners', ['name' => 'Second', 'is_active' => 1], $token), 409);
$allP = data(req('GET', '/partners/all', null, $token));
$second = array_column($allP, null, 'name')['Second'];
status_is('ordre', req('PUT', '/partners/order', ['ids' => [(int) $second['id'], (int) $allP[0]['id']]], $token), 200);
check('ordre relu', data(req('GET', '/partners'))[0]['name'] === 'Second');
status_is('masquer', req('PUT', "/partners/{$second['id']}", ['name' => 'Second', 'logo_url' => $image, 'is_active' => 0], $token), 200);
check('masqué du public', count(data(req('GET', '/partners'))) === 1);
status_is('logo externe refusé', req('PUT', "/partners/{$second['id']}", ['name' => 'Second', 'logo_url' => 'https://evil.org/a.png'], $token), 400);
status_is('suppression', req('DELETE', "/partners/{$second['id']}", null, $token), 200);

section('Feuille de présence');
$presence = ['last_name' => 'Pierre', 'first_name' => 'Anaïs', 'identifier' => '001-234-567-8', 'gender' => 'F', 'age' => 24];
status_is('inscription', req('POST', '/attendance', $presence), 201);
status_is('doublon', req('POST', '/attendance', ['identifier' => '0012345678'] + $presence), 409);
$att = req('GET', '/attendance', null, $token);
status_is('liste', $att, 200);
$attPdf = req('GET', '/attendance/export', null, $token);
check('PDF présence', str_starts_with($attPdf['raw'], '%PDF-') && str_contains($attPdf['raw'], 'contact@dev-dynamics.org'), substr($attPdf['raw'], 0, 50));
status_is('suppression', req('DELETE', '/attendance/' . data($att)[0]['id'], null, $token), 200);

section('Administration');
$stats = req('GET', '/admin/dashboard/stats', null, $token);
status_is('tableau de bord', $stats, 200);
foreach (['messages_new', 'testimonials_pending', 'forms_open', 'responses_week', 'projects_published'] as $key) {
    check("compteur {$key} lu", is_int(data($stats)[$key] ?? null), data($stats));
}
status_is('utilisateurs', req('GET', '/admin/users', null, $token), 200);

section('Messages');
$message = ['name' => 'Visiteur', 'email' => 'visiteur@exemple.org', 'message' => "Bonjour,\nje vous écris."];
status_is('sans sujet ni téléphone', req('POST', '/contact', $message), 201);
status_is('complet', req('POST', '/contact', ['subject' => 'Sujet é', 'phone' => '+509 1'] + $message), 201);
status_is('e-mail invalide', req('POST', '/contact', ['email' => 'x'] + $message), 400);
$messages = data(req('GET', '/contact', null, $token));
check('2 messages, statut nouveau, sujet vide accepté', count($messages) === 2 && $messages[1]['status'] === 'new' && $messages[1]['subject'] === '', $messages);
check('corps lisible avec accents', str_contains($messages[0]['message'], 'écris'), $messages[0]);
check('compteur non lus', data(req('GET', '/admin/dashboard/stats', null, $token))['messages_new'] === 2);
status_is('marquer lu', req('PUT', "/contact/{$messages[0]['id']}/status", ['status' => 'read'], $token), 200);
check('compteur à jour', data(req('GET', '/admin/dashboard/stats', null, $token))['messages_new'] === 1);
status_is('statut inconnu', req('PUT', "/contact/{$messages[0]['id']}/status", ['status' => 'unread'], $token), 400);
status_is('suppression', req('DELETE', "/contact/{$messages[0]['id']}", null, $token), 200);
$limit = null;
for ($i = 0; $i < 12; $i++) {
    $limit = req('POST', '/contact', $message);
    if ($limit['status'] === 429) break;
}
status_is('limite anti-spam', $limit, 429);
check('limite atteinte vers le 10e envoi', $i <= 10, $i);

section('Fuites');
check("l'empreinte d'appareil n'apparaît dans aucune réponse", !str_contains($allRaw, 'device_hash') && !str_contains($allRaw, hash('sha256', 'x')) && !str_contains($allRaw, $token1));
check('aucun détail SQL exposé au public', !preg_match('/SQLSTATE/', implode("\n", [req('POST', '/contact', [])['raw'], req('GET', '/projects/%00')['raw']])));

echo "\n" . ($total - $failures) . " / {$total} contrôles réussis\n";
exit($failures ? 1 : 0);
