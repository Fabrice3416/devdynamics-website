<?php
declare(strict_types=1);

/**
 * Noyau - export de sauvegarde pret a copier (CDC 7.6).
 * Produit une archive ZIP : base (SQL), fichiers de storage/, note de lecture.
 * Enregistre la date du dernier export telecharge et alerte au-dela du delai parametre.
 */

require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_role(['coordinateur']);

function dump_sql(): string
{
    $pdo = db();
    $out = "-- Bousol - export de la base " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $out .= "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";
        $rows = $pdo->query("SELECT * FROM `$t`");
        $buffer = [];
        while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r));
            $buffer[] = '(' . implode(',', $vals) . ')';
            if (count($buffer) >= 200) {
                $out .= "INSERT INTO `$t` VALUES " . implode(",\n", $buffer) . ";\n";
                $buffer = [];
            }
        }
        if ($buffer) {
            $out .= "INSERT INTO `$t` VALUES " . implode(",\n", $buffer) . ";\n";
        }
        $out .= "\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'exporter') {
    verify_csrf();
    $dir = storage_dir() . '/exports';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $nom = 'bousol-' . strtolower((string)projet_code()) . '-export-' . date('Ymd-His') . '.zip';
    $abs = $dir . '/' . $nom;
    // ZipArchive (extension zip) ou, a defaut, PharData (extension phar) : les deux produisent un ZIP standard.
    $zip = null;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($abs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $zip = null;
        }
    } elseif (class_exists('PharData')) {
        try {
            $zip = new PharData($abs, 0, null, Phar::ZIP);
        } catch (Throwable $e) {
            error_log('PharData: ' . $e->getMessage());
            $zip = null;
        }
    }
    if ($zip === null) {
        flash_set('danger', 'Impossible de créer l\'archive (extensions zip et phar indisponibles ?).');
        redirect(base_path('modules/noyau/sauvegarde.php'));
    }
    $zip->addFromString('base/bousol.sql', dump_sql());
    $nbFichiers = 0;
    foreach (['scans', 'documents', 'coffre', 'liasses'] as $cat) {
        $base = storage_dir() . '/' . $cat;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $zip->addFile($f->getPathname(), 'fichiers/' . $cat . '/' . substr($f->getPathname(), strlen($base) + 1));
            $nbFichiers++;
        }
    }
    $note = "BOUSOL - EXPORT DE SAUVEGARDE\nDate : " . date('d/m/Y H:i') . "\nPar : " . user_nom() . "\n\n"
          . "base/bousol.sql      : structure et contenu complet de la base (49 tables).\n"
          . "fichiers/            : scans, documents generes, coffre (contenus chiffres AES-256-GCM, cle dans config.php hors depot), liasses.\n"
          . "Les chemins sous fichiers/ correspondent a la colonne fichiers.chemin de la base.\n"
          . "Restauration : creer une base vide, importer base/bousol.sql, copier fichiers/* dans bousol/storage/, remettre config.php avec la meme cle de coffre.\n";
    $zip->addFromString('LISEZ-MOI.txt', $note);
    if ($zip instanceof ZipArchive) {
        $zip->close();
    }
    unset($zip);
    clearstatcache(true, $abs);

    param_set('dernier_export_le', date('Y-m-d'), 'Export de sauvegarde ' . $nom);
    audit('noyau', 'export_sauvegarde', 'fichier', $nom, $nbFichiers . ' fichiers, ' . filesize($abs) . ' octets', null, empreinte_fichier($abs));

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

$etat = sauvegarde_etat();
$exports = [];
if (is_dir(storage_dir() . '/exports')) {
    foreach (glob(storage_dir() . '/exports/*.zip') ?: [] as $f) {
        $exports[] = ['nom' => basename($f), 'taille' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))];
    }
    rsort($exports);
}

page_start('Sauvegarde', 'noyau');
$ongletActif = 'sauvegarde';
require __DIR__ . '/_nav.php';
?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Trois niveaux de sauvegarde (CDC 7.6)</div>
            <div class="card-body small">
                <ol class="mb-3">
                    <li><b>Quotidienne, automatique</b> — base et fichiers, conservée sur l'hébergement (à activer dans hPanel).</li>
                    <li><b>Hebdomadaire, hors site</b> — copie chiffrée sur support amovible détenu par le Coordinateur : téléchargez l'export ci-dessous et copiez-le.</li>
                    <li><b>Mensuelle, complète</b> — le même export, conservé comme archive de mois.</li>
                </ol>
                <p class="mb-3">Bousòl ne peut pas garantir la copie, mais il rend son absence visible : dernier export téléchargé le
                    <b><?= $etat['dernier'] ? e(date_fr($etat['dernier'])) : 'jamais' ?></b>
                    <?php if ($etat['delai'] > 0): ?>, délai d'alerte <?= $etat['delai'] ?> jours<?php else: ?> (délai d'alerte à définir dans les paramètres)<?php endif; ?>.
                    <?php if ($etat['retard']): ?><span class="badge text-bg-danger">en retard</span><?php endif; ?>
                </p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="exporter">
                    <button class="btn btn-primary"><i class="bi bi-download"></i> Générer et télécharger l'export complet</button>
                </form>
                <p class="text-muted mt-3 mb-0">L'archive contient la base (SQL), les fichiers de <code>storage/</code> et une note de lecture. Les contenus du coffre restent chiffrés : conservez la clé (<code>config.php</code>) séparément.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Exports générés sur le serveur</div>
            <?php if (!$exports): ?><div class="card-body small text-muted">Aucun export pour l'instant.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <thead><tr><th>Archive</th><th>Date</th><th class="text-end">Taille</th></tr></thead>
                <tbody><?php foreach ($exports as $x): ?><tr><td><?= e($x['nom']) ?></td><td><?= e($x['date']) ?></td><td class="text-end"><?= number_format($x['taille'] / 1024, 0, ',', ' ') ?> Ko</td></tr><?php endforeach; ?></tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php page_end();
