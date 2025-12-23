<?php
/**
 * Script de réorganisation pour Hostinger
 * À exécuter UNE SEULE FOIS après le git clone sur Hostinger
 *
 * Structure actuelle (après git clone):
 * public_html/
 * ├── frontend/
 * ├── backend/
 * ├── docs/
 * └── ...
 *
 * Structure souhaitée:
 * public_html/
 * ├── api/          (contenu de backend/)
 * ├── assets/       (de frontend/)
 * ├── css/          (de frontend/)
 * ├── js/           (de frontend/)
 * ├── pages/        (de frontend/)
 * └── index.html    (de frontend/)
 */

echo "🚀 Réorganisation de la structure pour Hostinger...\n\n";

// Fonction pour copier récursivement
function copyDirectory($src, $dst) {
    if (!is_dir($src)) {
        echo "❌ Le dossier source n'existe pas: $src\n";
        return false;
    }

    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }

    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    closedir($dir);
    return true;
}

// Fonction pour supprimer récursivement
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

// Étape 1: Créer un dossier temporaire
$tempDir = __DIR__ . '/temp_reorganize';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755);
}

echo "📦 Étape 1: Déplacement des fichiers frontend...\n";

// Copier les fichiers frontend dans temp
$frontendItems = ['assets', 'css', 'js', 'pages', 'index.html'];
foreach ($frontendItems as $item) {
    $src = __DIR__ . '/frontend/' . $item;
    $dst = $tempDir . '/' . $item;

    if (file_exists($src)) {
        if (is_dir($src)) {
            echo "  ➤ Copie du dossier: $item\n";
            copyDirectory($src, $dst);
        } else {
            echo "  ➤ Copie du fichier: $item\n";
            copy($src, $dst);
        }
    }
}

echo "\n📦 Étape 2: Déplacement du backend vers api/...\n";

// Copier backend dans temp/api
$backendSrc = __DIR__ . '/backend';
$backendDst = $tempDir . '/api';

if (is_dir($backendSrc)) {
    echo "  ➤ Copie du dossier backend vers api/\n";
    copyDirectory($backendSrc, $backendDst);
}

echo "\n🗑️  Étape 3: Nettoyage des anciens dossiers...\n";

// Supprimer les anciens dossiers (sauf temp_reorganize)
$foldersToDelete = ['frontend', 'backend', 'docs'];
foreach ($foldersToDelete as $folder) {
    $path = __DIR__ . '/' . $folder;
    if (is_dir($path)) {
        echo "  ➤ Suppression: $folder/\n";
        deleteDirectory($path);
    }
}

// Supprimer les fichiers de développement
$filesToDelete = [
    'README.md',
    'DEPLOIEMENT_RAPIDE.md',
    'deploy-hostinger.sh',
    'devdynamics_export.sql',
    '.gitignore',
    '.git'
];

foreach ($filesToDelete as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        if (is_dir($path)) {
            echo "  ➤ Suppression: $file/\n";
            deleteDirectory($path);
        } else {
            echo "  ➤ Suppression: $file\n";
            unlink($path);
        }
    }
}

echo "\n📥 Étape 4: Déplacement des fichiers à la racine...\n";

// Déplacer tout de temp vers la racine
$tempFiles = array_diff(scandir($tempDir), ['.', '..']);
foreach ($tempFiles as $item) {
    $src = $tempDir . '/' . $item;
    $dst = __DIR__ . '/' . $item;

    echo "  ➤ Déplacement: $item\n";
    rename($src, $dst);
}

// Supprimer le dossier temp
rmdir($tempDir);

echo "\n✅ Étape 5: Vérification de la structure finale...\n\n";

// Afficher la structure finale
echo "Structure finale:\n";
echo "public_html/\n";
$items = array_diff(scandir(__DIR__), ['.', '..']);
foreach ($items as $item) {
    $path = __DIR__ . '/' . $item;
    if (is_dir($path)) {
        echo "├── $item/\n";
    } else {
        echo "├── $item\n";
    }
}

echo "\n✨ Réorganisation terminée avec succès!\n\n";
echo "⚠️  IMPORTANT: Vérifiez que:\n";
echo "1. Le dossier api/ existe et contient les fichiers PHP\n";
echo "2. Les dossiers assets/, css/, js/, pages/ sont à la racine\n";
echo "3. Le fichier index.html est à la racine\n\n";
echo "🔧 Prochaines étapes:\n";
echo "1. Configurez le fichier api/.env avec vos identifiants de base de données\n";
echo "2. Importez la base de données via phpMyAdmin\n";
echo "3. Testez votre site!\n\n";
echo "🗑️  SUPPRIMEZ ce fichier (reorganize-hostinger.php) après utilisation\n";
?>
