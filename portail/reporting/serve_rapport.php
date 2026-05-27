<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

check_role(['administrateur', 'coordinateur']);

$id = (int)($_GET['id'] ?? 0);
$format = (string)($_GET['format'] ?? 'pdf');

$stmt = db()->prepare('SELECT * FROM rapports_generes WHERE id = ?');
$stmt->execute([$id]);
$rap = $stmt->fetch();
if (!$rap) { http_response_code(404); exit('404'); }

$file = $format === 'zip' ? $rap['fichier_zip'] : $rap['fichier_pdf'];
if (!$file) { http_response_code(404); exit('Fichier non disponible.'); }
$abs = storage_absolute_path($file);
if (!is_file($abs)) { http_response_code(404); exit('Fichier introuvable.'); }

$mime = $format === 'zip' ? 'application/zip' : 'application/pdf';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
header('Cache-Control: private, no-cache');
readfile($abs);
exit;
