<?php
declare(strict_types=1);

/** Signature - acte de depot de specimen a imprimer (PDF, non stocke : c'est la version signee et scannee qui est conservee). */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../pdf/generate.php';
require_login();

$st = db()->prepare('SELECT t.nom, t.fonction, t.est_mandataire, u.email FROM utilisateurs u JOIN tiers t ON t.id = u.tiers_id WHERE u.id = ?');
$st->execute([user_id()]);
$me = $st->fetch();

$pdf = new PdfService();
$bin = $pdf->rendre_binaire('acte_depot', [
    'titulaire'  => $me['nom'],
    'fonction'   => $me['fonction'] ?? '',
    'role'       => ROLES_LIBELLES[user_role()] ?? user_role(),
    'mandataire' => (int)$me['est_mandataire'] === 1,
    'email'      => $me['email'],
]);
if ($bin === null) {
    http_response_code(500);
    exit('Génération PDF impossible (mPDF manquant ?)');
}
audit('signature', 'acte_depot_imprime', 'utilisateur', user_id());
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="acte-depot-specimen.pdf"');
header('Cache-Control: private, no-store');
echo $bin;
