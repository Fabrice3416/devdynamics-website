<?php
declare(strict_types=1);

/**
 * Fichiers : upload securise, empreinte, coffre chiffre, table `fichiers`.
 *
 * Regles (CDC 5.3, 7.4) :
 *  - une piece = un fichier ; PDF ou image ; empreinte SHA-256 a l'enregistrement
 *  - un fichier n'est jamais supprime, seulement remplace (fichiers.remplace_id)
 *  - stockage hors racine web servie (storage/ est bloque, acces via pdf/serve.php)
 *  - coffre : chiffrement AES-256-GCM au repos (specimens, pieces d'identite, exports)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;

const ALLOWED_DOCUMENT   = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
const ALLOWED_PDF_ONLY   = ['pdf' => ['application/pdf']];
const ALLOWED_IMAGE_ONLY = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];

function storage_dir(): string
{
    return root_dir() . '/storage';
}

/**
 * Enregistre un upload ($_FILES[...]) et cree la ligne `fichiers`.
 *
 * @param string $categorie  scans | documents | coffre | liasses | exports
 * @param string $nomGenere  nom lisible genere par l'appelant (convention de classement CDC 5.4)
 * @return array{success:bool, id?:int, error?:string}
 */
function enregistrer_upload(array $file, string $categorie, string $nomGenere, array $allowed = ALLOWED_DOCUMENT, bool $coffre = false, ?int $remplaceId = null): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Paramètre fichier invalide'];
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'Aucun fichier transmis'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Erreur upload ' . (int)$file['error']];
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max 10 Mo)'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Upload invalide'];
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['success' => false, 'error' => 'Extension non autorisée : ' . $ext];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        return ['success' => false, 'error' => 'Le contenu ne correspond pas à l\'extension'];
    }
    $contenu = file_get_contents($file['tmp_name']);
    if ($contenu === false) {
        return ['success' => false, 'error' => 'Lecture impossible'];
    }
    return enregistrer_contenu($contenu, $ext, $mime, $categorie, $nomGenere, $coffre, $remplaceId);
}

/** Enregistre un contenu binaire deja en memoire (rendu PDF, image de signature decodee...). */
function enregistrer_contenu(string $contenu, string $ext, string $mime, string $categorie, string $nomGenere, bool $coffre = false, ?int $remplaceId = null): array
{
    $categorie = in_array($categorie, ['scans', 'documents', 'coffre', 'liasses', 'exports'], true) ? $categorie : 'documents';
    if ($coffre) {
        $categorie = 'coffre';
    }
    // Le nom genere porte le code du projet en tete (CDC 5.4) : extraite de Bousol,
    // l'arborescence reste triee par projet avant de l'etre par rubrique.
    $prefixe = $_SESSION['projet_code'] ?? null;
    if ($prefixe !== null && !str_starts_with($nomGenere, $prefixe . '-')) {
        $nomGenere = $prefixe . '-' . $nomGenere;
    }
    $empreinte = hash('sha256', $contenu);
    $rel = sprintf('%s/%s/%s/%s.%s', $categorie, date('Y'), date('m'), bin2hex(random_bytes(12)), $coffre ? 'bin' : $ext);
    $abs = storage_dir() . '/' . $rel;
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true)) {
        return ['success' => false, 'error' => 'Dossier de stockage inaccessible'];
    }
    $aEcrire = $coffre ? coffre_chiffrer($contenu) : $contenu;
    if (file_put_contents($abs, $aEcrire) === false) {
        return ['success' => false, 'error' => 'Écriture impossible'];
    }
    @chmod($abs, 0640);

    $stmt = db()->prepare(
        'INSERT INTO fichiers (nom_genere, chemin, extension, mime, taille, empreinte, coffre, remplace_id, projet_code, auteur_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$nomGenere, $rel, $ext, $mime, strlen($contenu), $empreinte, $coffre ? 1 : 0, $remplaceId,
                    $_SESSION['projet_code'] ?? null, $_SESSION['user_id'] ?? null]);
    $id = (int)db()->lastInsertId();
    audit('noyau', $remplaceId ? 'fichier_remplace' : 'fichier_cree', 'fichier', $id, $nomGenere, null, $empreinte);
    return ['success' => true, 'id' => $id, 'empreinte' => $empreinte];
}

function fichier(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fichiers WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    return $f ?: null;
}

/**
 * Un meme fichier ne peut justifier deux dossiers de projets differents, ni deux pieces
 * d'un meme dossier (CDC 7.3). Le controle est bloquant et ne se leve pas.
 * @return array{0:bool, 1:string} conforme, motif
 */
function empreinte_deja_utilisee(string $empreinte, ?int $exclureFichierId = null): array
{
    $stmt = db()->prepare(
        'SELECT f.id, f.projet_code, f.nom_genere FROM fichiers f
          WHERE f.empreinte = ? AND (? IS NULL OR f.id <> ?) LIMIT 1'
    );
    $stmt->execute([$empreinte, $exclureFichierId, $exclureFichierId]);
    $f = $stmt->fetch();
    if (!$f) {
        return [false, ''];
    }
    return [true, 'Ce fichier a déjà été versé sous le nom « ' . $f['nom_genere'] . ' »'
        . ($f['projet_code'] ? ' dans le projet ' . $f['projet_code'] : '') . '.'];
}

/** Contenu en clair d'un fichier (dechiffre si coffre). */
function lire_fichier(array $f): ?string
{
    $abs = storage_dir() . '/' . $f['chemin'];
    if (!is_file($abs)) {
        return null;
    }
    $raw = file_get_contents($abs);
    if ($raw === false) {
        return null;
    }
    return (int)$f['coffre'] === 1 ? coffre_dechiffrer($raw) : $raw;
}

function coffre_cle(): string
{
    $hex = (string)(config()['security']['coffre_key_hex'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
        throw new RuntimeException('Clé du coffre absente ou invalide dans config.php');
    }
    return hex2bin($hex);
}

function coffre_chiffrer(string $clair): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($clair, 'aes-256-gcm', coffre_cle(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($c === false) {
        throw new RuntimeException('Chiffrement impossible');
    }
    return 'BSL1' . $iv . $tag . $c;
}

function coffre_dechiffrer(string $blob): ?string
{
    if (!str_starts_with($blob, 'BSL1') || strlen($blob) < 4 + 12 + 16) {
        return null;
    }
    $iv  = substr($blob, 4, 12);
    $tag = substr($blob, 16, 16);
    $c   = substr($blob, 32);
    $p = openssl_decrypt($c, 'aes-256-gcm', coffre_cle(), OPENSSL_RAW_DATA, $iv, $tag);
    return $p === false ? null : $p;
}

/** Decode un PNG base64 (signature_pad) et le verifie reellement. */
function decoder_png_base64(string $dataUrl): ?string
{
    if (!preg_match('#^data:image/png;base64,(.+)$#', $dataUrl, $m)) {
        return null;
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 100) {
        return null;
    }
    return (new finfo(FILEINFO_MIME_TYPE))->buffer($bin) === 'image/png' ? $bin : null;
}

/**
 * Cree un document (acte) rattache a un objet metier, en portant le projet en valeur.
 * Le document est distinct du fichier : l'un est un acte, l'autre des octets (CDC 8.1).
 */
function creer_document(string $type, string $module, string $objetType, int $objetId,
                        ?int $fichierId = null, string $statut = 'brouillon', ?string $regime = null,
                        int $version = 1): int
{
    $stmt = db()->prepare(
        'INSERT INTO documents (type, module, objet_type, objet_id, projet_code, version, statut, regime, fichier_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$type, $module, $objetType, $objetId, $_SESSION['projet_code'] ?? null,
                    $version, $statut, $regime ?? 'papier', $fichierId, $_SESSION['user_id'] ?? null]);
    $id = (int)db()->lastInsertId();
    audit($module, 'document_cree', 'document', $id, $type . ($version > 1 ? ' · version ' . $version : ''));
    return $id;
}
