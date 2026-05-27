<?php
declare(strict_types=1);

/**
 * Helpers transverses
 */

/**
 * Echappe une chaine pour affichage HTML (XSS-safe).
 */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formatage HTG avec separateur de milliers.
 */
function format_htg(float|int|string|null $montant): string
{
    if ($montant === null || $montant === '') {
        return '0,00 HTG';
    }
    return number_format((float)$montant, 2, ',', ' ') . ' HTG';
}

/**
 * Genere un numero metier sequentiel : F01-ACP-001-2026, BC-ACP-042-2026, etc.
 * Garantit l'unicite via la table cible.
 *
 * @param string $prefix     Prefixe (ex: F01, F02, ASF, NH, FRP, BC, BR, TCD, RENF, F-PC, RFM, DJ)
 * @param string $table      Nom de la table cible
 * @param string $column     Colonne du numero (defaut: 'numero')
 * @param int|null $year     Annee (defaut: annee courante)
 */
function generate_numero(string $prefix, string $table, string $column = 'numero', ?int $year = null): string
{
    $year = $year ?? (int)date('Y');

    $sql = "SELECT $column FROM $table
            WHERE $column LIKE ?
            ORDER BY id DESC LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([$prefix . '-ACP-%-' . $year]);
    $last = $stmt->fetchColumn();

    $seq = 1;
    if ($last && preg_match('/-(\d+)-' . $year . '$/', (string)$last, $m)) {
        $seq = ((int)$m[1]) + 1;
    }

    return sprintf('%s-ACP-%03d-%d', $prefix, $seq, $year);
}

/**
 * Genere un numero mensuel : FRP-ACP-001-M01, FECP-ACP-01-M03, etc.
 */
function generate_numero_mensuel(string $prefix, int $sequence, int $mois): string
{
    return sprintf('%s-ACP-%03d-M%02d', $prefix, $sequence, $mois);
}

/**
 * Redirige vers une URL et termine le script.
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Flash messages (info, success, warning, danger)
 */
function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }
}

function flash_get(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Verifie qu'une valeur est dans une liste blanche.
 */
function in_whitelist($value, array $whitelist): bool
{
    return in_array($value, $whitelist, true);
}

/**
 * Decode un Base64 PNG (provenant de signature_pad.js) et le sauvegarde.
 * Retourne le chemin relatif au portail, ou null en cas d'echec.
 */
function save_base64_png(string $base64, string $destPath): ?string
{
    // Format attendu : "data:image/png;base64,iVBORw0KGgo..."
    if (!preg_match('#^data:image/png;base64,(.+)$#', $base64, $m)) {
        return null;
    }
    $binary = base64_decode($m[1], true);
    if ($binary === false || strlen($binary) < 100) {
        return null;
    }
    // Validation MIME reelle
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($binary);
    if ($mime !== 'image/png') {
        return null;
    }
    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return null;
    }
    if (file_put_contents($destPath, $binary) === false) {
        return null;
    }
    return $destPath;
}

/**
 * Retourne l'URL complete vers /portail/.
 */
function portail_url(string $path = ''): string
{
    $base = '/portail/';
    return $base . ltrim($path, '/');
}

/**
 * Date utilitaire : nom du mois en francais (M01 -> Janvier...).
 */
function mois_fr(int $m): string
{
    $noms = ['', 'Janvier', 'Fevrier', 'Mars', 'Avril', 'Mai', 'Juin',
             'Juillet', 'Aout', 'Septembre', 'Octobre', 'Novembre', 'Decembre'];
    return $noms[$m] ?? '';
}
