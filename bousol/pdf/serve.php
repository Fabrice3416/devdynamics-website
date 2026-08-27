<?php
declare(strict_types=1);

/**
 * Seul point d'acces aux fichiers de storage/ (CDC 7.4) : verifie la session,
 * les droits, dechiffre le coffre si besoin, journalise la consultation.
 *   /bousol/pdf/serve.php?id=42[&dl=1]
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
$f = $id > 0 ? fichier($id) : null;
if (!$f) {
    http_response_code(404);
    exit('404 - fichier introuvable');
}

// Un fichier rattache a un projet ne se sert que dans ce projet : les pieces d'un bailleur
// n'ont pas a etre visibles depuis un autre (CDC 1.4).
if (!empty($f['projet_code']) && $f['projet_code'] !== projet_code() && !user_est_admin_outil()) {
    audit('noyau', 'acces_refuse', 'fichier', $id, 'Fichier du projet ' . $f['projet_code']);
    http_response_code(403);
    exit('403 - Fichier appartenant à un autre projet');
}

// Coffre : specimens et pieces d'identite. Un specimen n'est visible que par son titulaire
// ou par le Coordinateur ; les autres contenus du coffre sont reserves au Coordinateur et au RAF.
if ((int)$f['coffre'] === 1) {
    $st = db()->prepare('SELECT titulaire_id FROM specimens WHERE image_fichier_id = ? OR acte_depot_fichier_id = ? LIMIT 1');
    $st->execute([$id, $id]);
    $titulaire = $st->fetchColumn();
    $ok = ($titulaire !== false && (int)$titulaire === user_id())
        || in_array(user_role(), ['coordinateur', 'raf'], true)
        || user_est_admin_outil();
    if (!$ok) {
        audit('noyau', 'acces_refuse', 'fichier', $id, 'Coffre');
        http_response_code(403);
        exit('403');
    }
}

$contenu = lire_fichier($f);
if ($contenu === null) {
    http_response_code(404);
    exit('404 - contenu indisponible');
}
audit('noyau', 'fichier_consulte', 'fichier', $id, $f['nom_genere']);

$nom = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['nom_genere']);
if (!str_ends_with(strtolower($nom), '.' . $f['extension'])) {
    $nom .= '.' . $f['extension'];
}
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . strlen($contenu));
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $nom . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $contenu;
exit;
