<?php
/**
 * Tests des messages, temoignages, partenaires et de l'organisation — sans base.
 *
 *     php tests/modules.php
 */

$ROOT = dirname(__DIR__);

class Halt extends Error {
    public $data;
    public function __construct($code, $message, $data = null) { parent::__construct($message, $code); $this->data = $data; }
}
class Response {
    public static function success($data = null, $message = '', $code = 200) { throw new Halt($code, $message, $data); }
    public static function error($message = '', $code = 400) { throw new Halt($code, $message); }
    public static function notFound($message = '') { throw new Halt(404, $message); }
}
class Router {
    public $routes = [];
    public static $body = [];
    public static function getInstance() { static $i; return $i ??= new self(); }
    public static function getBody() { return self::$body; }
    public function __call($method, $args) { $this->routes["{$method} {$args[0]}"] = ['run' => $args[1], 'mw' => $args[2] ?? []]; }
}
class Database {
    public $log = [];
    public $one = null;
    public $hits = 1;
    public $failInsert = false;
    public static function getInstance() { static $i; return $i ??= new self(); }
    public function query($sql, $params = []) {
        $this->log[] = [$sql, $params];
        if ($this->failInsert && str_contains($sql, 'INSERT INTO contact_messages')) throw new Exception('panne');
    }
    public function fetchAll($sql, $params = []) { $this->log[] = [$sql, $params]; return []; }
    public function fetchOne($sql, $params = []) {
        $this->log[] = [$sql, $params];
        return str_contains($sql, 'rate_limits') ? ['hits' => $this->hits] : $this->one;
    }
}

require "{$ROOT}/api/routes/contact.php";
require "{$ROOT}/api/routes/organization.php";
require "{$ROOT}/api/routes/partners.php";
require "{$ROOT}/api/routes/project-testimonials.php";

$failures = 0;
$check = function ($label, $ok) use (&$failures) {
    echo ($ok ? '  ok  ' : ' FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};
$call = function ($route, $params = [], $body = []) {
    Router::$body = $body;
    try { (Router::getInstance()->routes[$route]['run'])($params); } catch (Halt $halt) { return $halt; }
    return null;
};
$db = Database::getInstance();

// ---------- Routes ----------

$publiques = ['post \/contact', 'get \/organization', 'get \/partners',
    'get \/project-testimonials/collect/:slug', 'post \/project-testimonials/collect/:slug',
    'get \/project-testimonials/project/:slug', 'get \/project-testimonials/featured'];
foreach (Router::getInstance()->routes as $route => $definition) {
    $public = in_array($route, $publiques, true);
    $check($route . ($public ? ' public' : ' réservé aux admins'), $public ? $definition['mw'] === [] : $definition['mw'] === ['adminMiddleware']);
}
$keys = array_keys(Router::getInstance()->routes);
$check('/partners/order passe avant /partners/:id', array_search('put \/partners/order', $keys) < array_search('put \/partners/:id', $keys));

// ---------- Messages ----------

$message = ['name' => ' Marie ', 'email' => 'marie@exemple.org', 'message' => ' Bonjour '];
[$values, $error] = contact_validate($message);
$check('un message minimal passe', $error === null && $values['name'] === 'Marie');
$check('un sujet absent devient vide, jamais NULL', $values['subject'] === '');
$check('un téléphone absent devient NULL', $values['phone'] === null);
foreach ([
    'nom vide' => ['name' => ''], 'e-mail invalide' => ['email' => 'pas-un-mail'],
    'e-mail avec retour à la ligne' => ['email' => "a@b.org\nBcc: c@d.org"],
    'téléphone trop long pour la colonne' => ['phone' => str_repeat('1', 21)],
    'sujet trop long' => ['subject' => str_repeat('a', 256)],
    'message vide' => ['message' => ' '], 'message trop long' => ['message' => str_repeat('a', 10001)],
    'nom en tableau' => ['name' => ['x']],
] as $label => $override) {
    $check("refusé : {$label}", contact_validate(array_merge($message, $override))[1] !== null);
}

$db->log = [];
$halt = $call('post \/contact', [], $message);
$insert = current(array_filter($db->log, fn($q) => str_contains($q[0], 'INSERT INTO contact_messages')));
$check('le message est enregistré', $halt->getCode() === 201 && $insert !== false);
$check("le statut écrit est accepté par l'enum", str_contains($insert[0], "'new'") && !str_contains($insert[0], 'unread'));

$db->log = [];
$check('champ piège rempli : rien n\'est écrit', $call('post \/contact', [], $message + ['website' => 'bot'])->getCode() === 201 && $db->log === []);

$db->hits = CONTACT_MAX_PER_HOUR + 1;
$db->log = [];
$check('trop de messages : 429 sans écriture', $call('post \/contact', [], $message)->getCode() === 429
    && !array_filter($db->log, fn($q) => str_contains($q[0], 'contact_messages')));
$db->hits = 1;

$db->failInsert = true;
$halt = $call('post \/contact', [], $message);
$check('panne de base : pas de détail technique', $halt->getCode() === 500 && !str_contains($halt->getMessage(), 'panne'));
$db->failInsert = false;

$check('statut « unread » refusé', $call('put \/contact/:id/status', ['id' => 1], ['status' => 'unread'])->getCode() === 400);
$check('statut « read » accepté', $call('put \/contact/:id/status', ['id' => 1], ['status' => 'read'])->getCode() === 200);

$corps = Mailer::contactBody(['name' => 'Marie', 'email' => 'm@e.org', 'phone' => null, 'subject' => '', 'message' => 'Le texte']);
$check('la notification porte le message', str_contains($corps, 'Le texte') && str_contains($corps, 'm@e.org'));
putenv('SMTP_PASSWORD');
$check('sans mot de passe, aucun envoi et aucune erreur', Mailer::notifyContact(['name' => 'M', 'email' => 'm@e.org', 'phone' => null, 'subject' => '', 'message' => 'x']) === false);

// ---------- Temoignages ----------

foreach ([
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?t=3',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/watch?list=a&v=dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ',
] as $url) {
    $check("vidéo reconnue : {$url}", testimonial_video_id($url) === 'dQw4w9WgXcQ');
}
foreach (['https://evil.org/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'https://youtube.com.evil.org/watch?v=dQw4w9WgXcQ', 'https://youtu.be/"><script>'] as $url) {
    $check("vidéo refusée : {$url}", testimonial_video_id($url) === null);
}

$depot = ['name' => 'Jean', 'content' => 'Merci', 'consent' => 1];
$check('un témoignage complet passe', testimonial_validate($depot)[1] === null);
$check('sans accord : refusé', testimonial_validate(['consent' => 0] + $depot)[1] !== null);
$check('sans texte : refusé', testimonial_validate(['content' => ' '] + $depot)[1] !== null);
$check('lien non YouTube : refusé', testimonial_validate(['video_url' => 'https://evil.org/a'] + $depot)[1] !== null);

$db->one = ['id' => 4, 'testimonials_open' => 0, 'name' => 'P'];
$check('collecte fermée : dépôt refusé', $call('post \/project-testimonials/collect/:slug', ['slug' => 'p'], $depot)->getCode() === 403);
$db->one = ['id' => 4, 'testimonials_open' => 1, 'name' => 'P'];
$db->log = [];
$check('collecte ouverte : dépôt accepté', $call('post \/project-testimonials/collect/:slug', ['slug' => 'p'], $depot)->getCode() === 201);
$insert = current(array_filter($db->log, fn($q) => str_contains($q[0], 'INSERT INTO project_testimonials')));
$check('un dépôt arrive toujours en attente', str_contains($insert[0], "'pending'"));
$db->one = null;
$check('projet inconnu : 404', $call('post \/project-testimonials/collect/:slug', ['slug' => 'p'], $depot)->getCode() === 404);

$db->log = [];
$call('get \/project-testimonials/project/:slug', ['slug' => 'p']);
$lecture = end($db->log)[0];
$check('le public ne lit que les témoignages publiés', str_contains($lecture, "t.status = 'approved'") && str_contains($lecture, "p.status = 'published'"));
$call('get \/project-testimonials/featured');
$lecture = end($db->log)[0];
$check("l'accueil ne lit que les témoignages publiés et choisis", str_contains($lecture, "t.status = 'approved'") && str_contains($lecture, 't.featured = 1'));

$db->log = [];
$db->one = ['video_file' => null];
$call('put \/project-testimonials/:id', ['id' => 1], ['status' => 'rejected', 'featured' => 1]);
$check("un témoignage refusé quitte l'accueil", end($db->log)[1][1] === 0);
$call('put \/project-testimonials/:id', ['id' => 1], ['status' => 'approved', 'featured' => 1]);
$check('un témoignage publié peut aller sur l\'accueil', end($db->log)[1][1] === 1);
$db->one = null;
$check('témoignage inconnu : 404', $call('put \/project-testimonials/:id', ['id' => 9], ['status' => 'approved'])->getCode() === 404);

// ---------- Videos ----------

$dir = sys_get_temp_dir() . '/videos-test-' . uniqid();
mkdir("{$dir}/videos-attente", 0755, true);
$mp4 = "{$dir}/a.mp4";
file_put_contents($mp4, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64));
$mov = "{$dir}/a.mov";
file_put_contents($mov, "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  " . str_repeat("\x00", 64));
$php = "{$dir}/photo.mp4";
file_put_contents($php, '<?php echo 1;');
$check('un MP4 passe', testimonial_video_check($mp4, filesize($mp4), UPLOAD_ERR_OK)[0] === 'mp4');
$check("un MOV d'iPhone passe", testimonial_video_check($mov, filesize($mov), UPLOAD_ERR_OK)[0] === 'mov');
$check('du PHP déguisé en MP4 est refusé', testimonial_video_check($php, filesize($php), UPLOAD_ERR_OK)[1] !== null);
$check('plus de 100 Mo est refusé', testimonial_video_check($mp4, TESTIMONIAL_VIDEO_MAX_BYTES + 1, UPLOAD_ERR_OK)[1] !== null);
$check('une limite du serveur dépassée est dite clairement', str_contains(testimonial_video_check($mp4, 0, UPLOAD_ERR_INI_SIZE)[1], '100 Mo'));

$name = 'abc.mp4';
rename($mp4, "{$dir}/videos-attente/{$name}");
$check('en attente : compté dans le plafond', testimonial_pending_bytes($dir) > 0);
$check('publier déplace la vidéo', testimonial_sync_video($name, true, $dir) && is_file("{$dir}/videos/{$name}") && !is_file("{$dir}/videos-attente/{$name}"));
$check('republier ne casse rien', testimonial_sync_video($name, true, $dir));
$check('refuser la retire du public', testimonial_sync_video($name, false, $dir) && !is_file("{$dir}/videos/{$name}") && is_file("{$dir}/videos-attente/{$name}"));
$check('sans vidéo : rien à déplacer', testimonial_sync_video(null, true, $dir));
$check('fichier disparu : signalé', testimonial_sync_video('absent.mp4', true, $dir) === false);
testimonial_delete_video($name, $dir);
$check('supprimer efface le fichier', !is_file("{$dir}/videos-attente/{$name}") && !is_file("{$dir}/videos/{$name}"));
array_map('unlink', [$mov, $php]);
rmdir("{$dir}/videos"); rmdir("{$dir}/videos-attente"); rmdir($dir);

$_SERVER['CONTENT_LENGTH'] = 500000000; $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=x';
$check('envoi trop lourd : 413 au lieu de « Indiquez votre nom »', $call('post \/project-testimonials/collect/:slug', ['slug' => 'p'], [])->getCode() === 413);
unset($_SERVER['CONTENT_LENGTH'], $_SERVER['CONTENT_TYPE']);

$check('le dossier en attente est fermé', str_contains(file_get_contents("{$ROOT}/uploads/videos-attente/.htaccess"), 'Require all denied'));
$check('le serveur accepte la taille annoncée', (int) parse_ini_file("{$ROOT}/api/.user.ini")['upload_max_filesize'] * 1024 * 1024 > TESTIMONIAL_VIDEO_MAX_BYTES);
$publiques = file_get_contents("{$ROOT}/js/site.js");
$check('le site ne lit que le dossier publié', str_contains($publiques, 'uploads/videos/') && !str_contains($publiques, 'videos-attente'));

// ---------- Partenaires ----------

$check('un partenaire sans logo passe', partner_validate(['name' => 'AF', 'is_active' => 1])[0]['logo_url'] === null);
$check('sans nom : refusé', partner_validate(['name' => ''])[1] !== null);
$check('logo envoyé : accepté', partner_validate(['name' => 'AF', 'logo_url' => 'uploads/ab12.png'])[1] === null);
foreach (['https://evil.org/a.png', '../api/.env', 'javascript:alert(1)'] as $logo) {
    $check("logo refusé : {$logo}", partner_validate(['name' => 'AF', 'logo_url' => $logo])[1] !== null);
}
$db->log = [];
$call('get \/partners');
$check('le public ne voit que les partenaires visibles, sans lien', str_contains(end($db->log)[0], 'is_active = 1') && !str_contains(end($db->log)[0], 'url,'));

// ---------- Organisation ----------

$org = ['name' => 'DevDynamics', 'email' => 'contact@dev-dynamics.org', 'phone' => '+509 47 41 8737'];
$check('coordonnées valides', organization_validate($org)[1] === null);
$check('e-mail invalide refusé', organization_validate(['email' => 'x'] + $org)[1] !== null);
$check('téléphone trop long refusé', organization_validate(['phone' => str_repeat('1', 21)] + $org)[1] !== null);
$check('fondateur sans fonction refusé', founder_validate(['name' => 'A'])[1] !== null);
$check('photo externe refusée', founder_validate(['name' => 'A', 'role' => 'B', 'image_url' => 'https://evil.org/a.png'])[1] !== null);
$source = file_get_contents("{$ROOT}/api/routes/organization.php");
$check('le tri utilise la vraie colonne', str_contains($source, 'ORDER BY order_position') && !str_contains($source, 'display_order'));

// ---------- Schema ----------

$sql = file_get_contents("{$ROOT}/api/sql/refonte_03_modules.sql");
foreach (['Alliance Française du Cap-Haïtien', '190, rue 15-16 B, Cap-Haïtien, Haïti', '+509 47 41 8737', 'contact@dev-dynamics.org',
          'Rodely JEAN', 'Fabrice SAINTILMA', 'Gueslander Lynx LAURENT', 'Responsable administratif',
          'Responsable technique et développeur full stack', 'Responsable financier et data scientist'] as $attendu) {
    $check("migration : {$attendu}", str_contains($sql, $attendu));
}
$check('les témoignages arrivent en attente par défaut', str_contains($sql, "DEFAULT 'pending'"));
$check("les anciennes tables n'y sont pas touchées", !preg_match('/DROP TABLE|testimonials\b(?<!project_testimonials)/', $sql));
$check('le téléphone tient dans sa colonne', strlen('+509 47 41 8737') <= 20);

$env = file_get_contents("{$ROOT}/api/.env.example");
$check("le modèle d'environnement ne porte aucun mot de passe", preg_match('/^SMTP_PASSWORD=$/m', $env) === 1);
$check('PHPMailer est livré avec le site', is_file("{$ROOT}/api/lib/PHPMailer/PHPMailer.php"));

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
