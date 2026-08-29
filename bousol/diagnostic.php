<?php
declare(strict_types=1);

/**
 * Diagnostic d'installation de Bousol.
 *
 * Usage :
 *   - en ligne de commande : php bousol/diagnostic.php
 *   - dans le navigateur   : https://.../bousol/diagnostic.php
 *
 * Acces depuis le navigateur : libre tant qu'aucun utilisateur ne s'est jamais connecte
 * (mode installation, ou l'on a justement besoin du diagnostic sans pouvoir se connecter),
 * puis reserve au Coordinateur. Aucun mot de passe ni cle n'est affiche.
 */

$cli = PHP_SAPI === 'cli';

/** @var array<int, array{0:string,1:string,2:string,3:string}> section, statut, libelle, detail */
$resultats = [];
function verifier(string $section, string $statut, string $libelle, string $detail = ''): void
{
    global $resultats;
    $resultats[] = [$section, $statut, $libelle, $detail];
}

// ---------------------------------------------------------------- Environnement
$phpOk = PHP_VERSION_ID >= 80100;
verifier('Environnement', $phpOk ? 'ok' : 'erreur', 'Version de PHP', PHP_VERSION . ($phpOk ? '' : ' — 8.1 minimum requis'));

foreach (['pdo_mysql' => 'accès à la base', 'mbstring' => 'texte accentué', 'fileinfo' => 'contrôle du type réel des fichiers téléversés', 'openssl' => 'chiffrement du coffre'] as $ext => $usage) {
    verifier('Environnement', extension_loaded($ext) ? 'ok' : 'erreur', 'Extension ' . $ext, $usage);
}
verifier('Environnement', extension_loaded('gd') ? 'ok' : 'avertissement', 'Extension gd',
    extension_loaded('gd') ? 'traitement des images de signature' : 'absente : les spécimens devront être déposés en JPEG, les PNG seront refusés');
$archive = extension_loaded('zip') ? 'zip' : (class_exists('PharData') ? 'phar' : null);
verifier('Environnement', $archive ? 'ok' : 'erreur', 'Création d\'archives',
    $archive ? 'via ' . $archive : 'ni zip ni phar : l\'export de sauvegarde est impossible');

// ---------------------------------------------------------------- Configuration
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$configFile = config_path();
if ($configFile === null) {
    verifier('Configuration', 'erreur', 'Fichier de configuration',
        'absent : copier includes/config.example.php vers ' . dirname(root_dir(), 2) . '/bousol-config.php');
    rendre($resultats, $cli);
}
$horsRacine = config_hors_racine_web();
verifier('Configuration', $horsRacine ? 'ok' : 'avertissement', 'Emplacement du fichier de configuration',
    $horsRacine
        ? 'hors de la racine web, inatteignable par URL'
        : 'sous la racine web (' . str_replace(dirname(root_dir()) . '/', '', $configFile) . ') : le déplacer dans '
          . dirname(root_dir(), 2) . '/bousol-config.php, où ses permissions n\'ont plus d\'incidence');

$perms = substr(sprintf('%o', fileperms($configFile)), -3);
$permsOk = $perms === '600' || ($horsRacine && in_array($perms, ['600', '640', '644'], true));
verifier('Configuration', $permsOk ? 'ok' : 'avertissement', 'Permissions du fichier de configuration',
    $perms . ($perms === '600'
        ? ''
        : ($horsRacine
            ? ' — acceptable hors racine web, 600 reste préférable'
            : ' — viser 600 : le fichier contient le mot de passe de la base et la clé du coffre')));

$cfg = config();
verifier('Configuration', ($cfg['app']['env'] ?? '') === 'production' ? 'ok' : 'avertissement', 'Environnement déclaré',
    (string)($cfg['app']['env'] ?? '(non défini)'));

$cle = (string)($cfg['security']['coffre_key_hex'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/i', $cle)) {
    verifier('Configuration', 'erreur', 'Clé du coffre', 'absente ou mal formée : 64 caractères hexadécimaux attendus');
} else {
    require_once __DIR__ . '/includes/uploads.php';
    try {
        $temoin = 'Bousòl ' . bin2hex(random_bytes(8));
        $ok = coffre_dechiffrer(coffre_chiffrer($temoin)) === $temoin;
        verifier('Configuration', $ok ? 'ok' : 'erreur', 'Clé du coffre',
            $ok ? 'chiffrement et déchiffrement vérifiés (AES-256-GCM)' : 'le déchiffrement ne rend pas le texte d\'origine');
    } catch (Throwable $e) {
        verifier('Configuration', 'erreur', 'Clé du coffre', $e->getMessage());
    }
}

// ---------------------------------------------------------------- Base de donnees
// La connexion est etablie ici, et non via db(), qui interrompt le script en cas d'echec :
// le diagnostic doit rester lisible quand la base est justement injoignable.
$dbOk = false;
$d = $cfg['db'] ?? [];
$dsn = !empty($d['socket'])
    ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $d['socket'], $d['name'] ?? '', $d['charset'] ?? 'utf8mb4')
    : sprintf('mysql:host=%s;dbname=%s;charset=%s', $d['host'] ?? 'localhost', $d['name'] ?? '', $d['charset'] ?? 'utf8mb4');
try {
    $pdo = new PDO($dsn, (string)($d['user'] ?? ''), (string)($d['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $dbOk = true;
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $base    = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    verifier('Base de données', 'ok', 'Connexion', $base . ' · ' . $version);

    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $st->execute([$base]);
    $nbTables = (int)$st->fetchColumn();
    verifier('Base de données', $nbTables === 52 ? 'ok' : 'erreur', 'Tables', $nbTables . ' / 52');

    require_once __DIR__ . '/includes/calendrier.php';
    $integrite = integrite_triggers();
    verifier('Base de données', $integrite['ok'] ? 'ok' : 'erreur', 'Garde-fous d\'immuabilité',
        $integrite['presents'] . ' / ' . $integrite['attendus']
        . ($integrite['ok'] ? '' : ' — manquants : ' . implode(', ', array_keys($integrite['manquants'])) . ' (importer database/schema_triggers.sql)'));

    // Un garde-fou installe doit refuser l'ecriture. Tentative annulee immediatement.
    if ($integrite['ok'] && (int)$pdo->query('SELECT COUNT(*) FROM journal_audit')->fetchColumn() > 0) {
        $refuse = false;
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE journal_audit SET detail = 'diagnostic' WHERE id = (SELECT * FROM (SELECT MIN(id) FROM journal_audit) x)");
        } catch (Throwable $e) {
            $refuse = true;
        } finally {
            $pdo->rollBack();
        }
        verifier('Base de données', $refuse ? 'ok' : 'erreur', 'Journal d\'audit non modifiable',
            $refuse ? 'la base refuse la modification' : 'la modification a été acceptée : le garde-fou ne joue pas son rôle');
    }

    $st = $pdo->prepare('SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $st->execute([$base, 'rapports', 'contenu_json']);
    $typeJson = (string)$st->fetchColumn();
    $jsonOk = (int)$pdo->query("SELECT JSON_VALID('{\"a\":1}')")->fetchColumn() === 1;
    verifier('Base de données', $jsonOk ? 'ok' : 'avertissement', 'Support JSON',
        'colonne rapports.contenu_json de type ' . ($typeJson ?: 'inconnu') . ($jsonOk ? ', fonctions JSON disponibles' : ', JSON_VALID indisponible'));

    $projets = $pdo->query('SELECT p.code, p.intitule,
            (SELECT COUNT(*) FROM lignes_budgetaires l WHERE l.projet_id = p.id) lignes,
            (SELECT COUNT(DISTINCT cle) FROM parametres x WHERE x.projet_id = p.id) params
        FROM projets p ORDER BY p.code')->fetchAll();
    verifier('Base de données', $projets ? 'ok' : 'avertissement', 'Projets',
        $projets ? implode(' · ', array_map(fn($p) => $p['code'] . ' (' . $p['lignes'] . ' lignes, ' . $p['params'] . ' paramètres)', $projets))
                 : 'aucun projet : l\'administrateur de l\'outil doit en créer un');

    $orphelines = (int)$pdo->query('SELECT COUNT(*) FROM parametres WHERE projet_id IS NULL')->fetchColumn();
    verifier('Base de données', $orphelines === 0 ? 'ok' : 'erreur', 'Cloisonnement des paramètres',
        $orphelines === 0 ? 'aucun paramètre global, comme l\'exige le cahier des charges' : $orphelines . ' paramètre(s) sans projet');

    $collation = (string)$pdo->query('SELECT @@collation_database')->fetchColumn();
    verifier('Base de données', str_starts_with($collation, 'utf8mb4') ? 'ok' : 'avertissement', 'Interclassement', $collation);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $piste = str_contains($msg, 'Access denied')
        ? 'identifiant ou mot de passe refusé : vérifier db.user et db.pass dans config.php, et que cet utilisateur a bien des droits sur la base'
        : (str_contains($msg, 'Unknown database')
            ? 'la base nommée dans config.php n\'existe pas sous ce nom : vérifier db.name dans hPanel'
            : 'vérifier db.host, db.name, db.user et db.pass dans config.php');
    verifier('Base de données', 'erreur', 'Connexion',
        'base « ' . ($d['name'] ?? '?') . ' », utilisateur « ' . ($d['user'] ?? '?') . ' » — ' . $piste);
    verifier('Base de données', 'erreur', 'Message du serveur', $msg);
}

// ---------------------------------------------------------------- Stockage
$storage = __DIR__ . '/storage';
verifier('Stockage', is_file($storage . '/.htaccess') ? 'ok' : 'erreur', 'storage/.htaccess',
    is_file($storage . '/.htaccess') ? 'présent (accès direct interdit)' : 'absent : les fichiers seraient accessibles par URL');

foreach (['scans', 'documents', 'coffre', 'liasses', 'exports', 'tmp'] as $sous) {
    $chemin = $storage . '/' . $sous;
    if (!is_dir($chemin)) {
        @mkdir($chemin, 0750, true);
    }
    $inscriptible = is_dir($chemin) && is_writable($chemin);
    verifier('Stockage', $inscriptible ? 'ok' : 'erreur', 'storage/' . $sous, $inscriptible ? 'inscriptible' : 'absent ou non inscriptible');
}

$temoin = $storage . '/tmp/diagnostic-' . bin2hex(random_bytes(4)) . '.txt';
$cycle = @file_put_contents($temoin, 'test') !== false && @file_get_contents($temoin) === 'test' && @unlink($temoin);
verifier('Stockage', $cycle ? 'ok' : 'erreur', 'Écriture, lecture, suppression', $cycle ? 'cycle complet vérifié' : 'échec du cycle dans storage/tmp');

// ---------------------------------------------------------------- Rendu documentaire
$autoload = PdfService::autoload_mpdf();
if ($autoload === null) {
    verifier('Documents', 'erreur', 'Bibliothèque mPDF',
        'absente : déposer mPDF hors de la racine web et renseigner app.mpdf, ou dans bousol/lib/mpdf/');
} else {
    verifier('Documents', 'ok', 'Bibliothèque mPDF', 'présente — ' . $autoload);
    if (!$dbOk) {
        // L'en-tete des documents lit le nom du projet et le numero de contrat en base.
        verifier('Documents', 'avertissement', 'Rendu d\'un PDF', 'non testé : la connexion à la base doit d\'abord fonctionner');
    } else try {
        require_once __DIR__ . '/pdf/generate.php';
        $svc = new PdfService();
        $bin = $svc->rendre_binaire('acte_depot', [
            'titulaire' => 'Diagnostic', 'fonction' => '—', 'role' => '—', 'mandataire' => false, 'email' => '—',
        ]);
        $rendu = $bin !== null && str_starts_with($bin, '%PDF');
        verifier('Documents', $rendu ? 'ok' : 'erreur', 'Rendu d\'un PDF',
            $rendu ? strlen((string)$bin) . ' octets, en-tête et logo compris' : 'la génération a échoué');
    } catch (Throwable $e) {
        verifier('Documents', 'erreur', 'Rendu d\'un PDF', $e->getMessage());
    }
}
$logo = __DIR__ . '/assets/images/logo.jpg';
verifier('Documents', is_readable($logo) ? 'ok' : 'avertissement', 'Logo de l\'en-tête',
    is_readable($logo) ? 'assets/images/logo.jpg' : 'logo.jpg illisible : les documents sortiront sans logo');

// ---------------------------------------------------------------- Sortie
rendre($resultats, $cli);

/** @param array<int, array{0:string,1:string,2:string,3:string}> $resultats */
function rendre(array $resultats, bool $cli): void
{
    // Hors CLI : libre en mode installation, sinon reserve au Coordinateur.
    if (!$cli) {
        $installation = true;
        global $pdo, $dbOk;
        if (!empty($dbOk)) {
            try {
                $installation = (int)$pdo->query('SELECT COUNT(*) FROM utilisateurs WHERE derniere_connexion IS NOT NULL')->fetchColumn() === 0;
            } catch (Throwable) {
                // Tables absentes : l'installation n'est pas terminee, le diagnostic reste accessible.
            }
        }
        if (!$installation) {
            require_once __DIR__ . '/includes/auth.php';
            require_role(['coordinateur']);
        }
    }

    $compte = ['ok' => 0, 'avertissement' => 0, 'erreur' => 0];
    foreach ($resultats as [, $statut]) {
        $compte[$statut] = ($compte[$statut] ?? 0) + 1;
    }

    if ($cli) {
        $symboles = ['ok' => '  OK  ', 'avertissement' => ' ATTN ', 'erreur' => 'ERREUR'];
        $section = null;
        foreach ($resultats as [$sec, $statut, $libelle, $detail]) {
            if ($sec !== $section) {
                echo PHP_EOL . '== ' . $sec . PHP_EOL;
                $section = $sec;
            }
            echo $symboles[$statut] . '  ' . $libelle . ($detail !== '' ? '  —  ' . $detail : '') . PHP_EOL;
        }
        echo PHP_EOL . sprintf('%d OK, %d avertissement(s), %d erreur(s)', $compte['ok'], $compte['avertissement'], $compte['erreur']) . PHP_EOL;
        exit($compte['erreur'] > 0 ? 1 : 0);
    }

    $couleurs = ['ok' => '#4c5a47', 'avertissement' => '#8a6d3b', 'erreur' => '#8a2f22'];
    $puces    = ['ok' => '&#10003;', 'avertissement' => '!', 'erreur' => '&#10007;'];
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Diagnostic · Bousòl</title>
<style>
  body { font-family: Candara, "Segoe UI", sans-serif; color: #2a2a28; background: #f7f6f2; margin: 0; padding: 2.5rem 1.5rem; }
  main { max-width: 60rem; margin: 0 auto; }
  h1 { font-family: "Palatino Linotype", Georgia, serif; color: #4c5a47; margin: 0 0 .25rem; }
  .resume { color: #6b6a66; margin-bottom: 2rem; }
  h2 { font-family: "Palatino Linotype", Georgia, serif; color: #4c5a47; font-size: 1.05rem; margin: 1.75rem 0 .5rem; border-bottom: 1px solid #c9c4ba; padding-bottom: .25rem; }
  table { border-collapse: collapse; width: 100%; }
  td { padding: .35rem .5rem; border-bottom: 1px solid #efede8; vertical-align: top; font-size: .95rem; }
  td.p { width: 1.5rem; font-weight: bold; text-align: center; }
  td.l { width: 34%; }
  td.d { color: #6b6a66; }
</style>
</head>
<body>
<main>
  <h1>Diagnostic d'installation</h1>
  <p class="resume">
    <?= (int)$compte['ok'] ?> contrôle(s) réussi(s),
    <?= (int)$compte['avertissement'] ?> avertissement(s),
    <?= (int)$compte['erreur'] ?> erreur(s).
    <?php if ($compte['erreur'] === 0): ?>L'installation est exploitable.<?php endif; ?>
  </p>
  <?php $section = null; foreach ($resultats as [$sec, $statut, $libelle, $detail]):
      if ($sec !== $section) { if ($section !== null) { echo '</table>'; } echo '<h2>' . htmlspecialchars($sec) . '</h2><table>'; $section = $sec; } ?>
    <tr>
      <td class="p" style="color:<?= $couleurs[$statut] ?>"><?= $puces[$statut] ?></td>
      <td class="l"><?= htmlspecialchars($libelle) ?></td>
      <td class="d"><?= htmlspecialchars($detail) ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
</main>
</body>
</html>
    <?php
    exit;
}
