<?php
declare(strict_types=1);

/**
 * Module Signature (CDC 1.8, 8.1) : specimens, file de signature, appositions.
 *
 * Regles appliquees ici, et nulle part ailleurs :
 *  - pas d'apposition sans specimen actif accompagne de son acte de depot ;
 *  - un specimen n'est apposable que par son titulaire, apres reauthentification ;
 *  - deux appositions sur un meme document ne peuvent venir ni du meme compte ni de la meme session ;
 *  - la qualite "reglement" est reservee aux mandataires ;
 *  - chaque apposition porte les empreintes avant/apres, l'horodatage, l'appareil et un code de verification.
 * Le controle du conflit d'interets (mandataire beneficiaire) appartient au module Comptes.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/referentiels.php';
require_once __DIR__ . '/droits.php';
require_once __DIR__ . '/../pdf/generate.php';   // PdfService::autoload_mpdf()

function specimen_actif(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, fi.empreinte AS image_empreinte, fa.nom_genere AS acte_nom
           FROM specimens s
           JOIN fichiers fi ON fi.id = s.image_fichier_id
           JOIN fichiers fa ON fa.id = s.acte_depot_fichier_id
          WHERE s.titulaire_id = ? AND s.date_revocation IS NULL
          ORDER BY s.id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $s = $stmt->fetch();
    return $s ?: null;
}

function specimens_historique(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM specimens WHERE titulaire_id = ? ORDER BY id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Depot d'un specimen : image (pad JPEG, fichier PNG ou JPEG) + acte de depot signe a la main et scanne.
 * Refuse s'il existe deja un specimen actif (le revoquer d'abord).
 */
function deposer_specimen(int $userId, string $imageBinary, array $acteUpload): array
{
    if (specimen_actif($userId)) {
        return ['success' => false, 'error' => 'Un spécimen est déjà actif : révoquez-le avant d\'en déposer un nouveau.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBinary);
    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
        return ['success' => false, 'error' => 'L\'image du spécimen doit être un PNG ou un JPEG.'];
    }
    // Le rendu PDF des images PNG a transparence exige GD ; on normalise en JPEG sur fond blanc.
    if ($mime === 'image/png') {
        if (!extension_loaded('gd')) {
            return ['success' => false, 'error' => 'Ce serveur ne peut pas traiter les PNG (extension GD absente) : tracez la signature dans le cadre ou téléversez un JPEG.'];
        }
        $src = @imagecreatefromstring($imageBinary);
        if ($src === false) {
            return ['success' => false, 'error' => 'Image illisible.'];
        }
        $w = imagesx($src); $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        $blanc = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $blanc);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($dst, null, 92);
        $imageBinary = (string)ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
    }
    $nomBase = 'SPECIMEN-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)user_nom()) . '-' . date('Ymd');
    $acte = enregistrer_upload($acteUpload, 'coffre', $nomBase . '-acte-de-depot.pdf', ALLOWED_DOCUMENT, true);
    if (!$acte['success']) {
        return ['success' => false, 'error' => 'Acte de dépôt : ' . $acte['error']];
    }
    $img = enregistrer_contenu($imageBinary, 'jpg', 'image/jpeg', 'coffre', $nomBase . '.jpg', true);
    if (!$img['success']) {
        return ['success' => false, 'error' => 'Image : ' . $img['error']];
    }
    $stmt = db()->prepare('INSERT INTO specimens (titulaire_id, image_fichier_id, acte_depot_fichier_id, date_depot) VALUES (?,?,?,CURDATE())');
    $stmt->execute([$userId, $img['id'], $acte['id']]);
    $id = (int)db()->lastInsertId();
    audit('signature', 'specimen_depose', 'specimen', $id, 'Image ' . $img['empreinte'] . ' / acte ' . $acte['empreinte'], null, $img['empreinte']);
    return ['success' => true, 'id' => $id];
}

function revoquer_specimen(int $userId, string $motif): bool
{
    $s = specimen_actif($userId);
    if (!$s) {
        return false;
    }
    db()->prepare('UPDATE specimens SET date_revocation = CURDATE(), motif_revocation = ? WHERE id = ?')
        ->execute([mb_substr($motif, 0, 255), (int)$s['id']]);
    audit('signature', 'specimen_revoque', 'specimen', (int)$s['id'], $motif);
    return true;
}

/** Roles du catalogue (annexe E) que l'utilisateur courant peut couvrir. */
function qualites_catalogue_utilisateur(): array
{
    $q = user_role() === null ? [] : [user_role()];
    if (user_role() === 'coordinateur') {
        $q[] = 'representant_legal';
        $q[] = 'coordinateur|assemblee';
    }
    if (user_est_mandataire()) {
        $q[] = 'mandataire';
    }
    return $q;
}

/** Documents en attente de la signature de l'utilisateur courant. */
function documents_a_signer(): array
{
    $mesQualites = qualites_catalogue_utilisateur();
    $types = [];
    foreach (DOCUMENTS_GENERES as $code => [$lib, $signataires]) {
        if (array_intersect($signataires, $mesQualites)) {
            $types[] = $code;
        }
    }
    if (!$types) {
        return [];
    }
    $in = implode(',', array_fill(0, count($types), '?'));
    // La file ne montre que le projet courant : les documents portent le projet en valeur.
    $stmt = db()->prepare(
        "SELECT d.*, f.nom_genere, f.empreinte,
                (SELECT COUNT(*) FROM appositions a WHERE a.document_id = d.id) AS nb_appositions
           FROM documents d LEFT JOIN fichiers f ON f.id = d.fichier_id
          WHERE d.statut = 'a_signer' AND d.type IN ($in) AND d.projet_code = ?
            AND NOT EXISTS (SELECT 1 FROM appositions a WHERE a.document_id = d.id AND a.signataire_id = ?)
          ORDER BY d.created_at"
    );
    $stmt->execute([...$types, projet_code(), user_id()]);
    return $stmt->fetchAll();
}

function mes_appositions(int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT a.*, d.type, d.module, d.objet_type, d.objet_id, d.version
           FROM appositions a JOIN documents d ON d.id = a.document_id
          WHERE a.signataire_id = ? ORDER BY a.id DESC LIMIT ' . (int)$limit
    );
    $stmt->execute([user_id()]);
    return $stmt->fetchAll();
}

function apposition_par_code(string $code): ?array
{
    $stmt = db()->prepare(
        'SELECT a.*, d.type, d.module, d.objet_type, d.objet_id, d.version, d.statut AS document_statut,
                t.nom AS signataire_nom, f.empreinte AS empreinte_courante
           FROM appositions a
           JOIN documents d ON d.id = a.document_id
           JOIN utilisateurs u ON u.id = a.signataire_id
           JOIN tiers t ON t.id = u.tiers_id
           LEFT JOIN fichiers f ON f.id = d.fichier_id
          WHERE a.code_verification = ? LIMIT 1'
    );
    $stmt->execute([strtoupper(trim($code))]);
    $a = $stmt->fetch();
    return $a ?: null;
}

function generer_code_verification(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($s, 0, 4) . '-' . substr($s, 4, 4) . '-' . substr($s, 8, 2);
}

/** Nombre de signatures applicatives attendues pour un type de document (annexe E). */
function signatures_attendues(string $type): int
{
    $s = DOCUMENTS_GENERES[$type][1] ?? [];
    $app = array_filter($s, fn($r) => !in_array($r, ['beneficiaire', 'prestataire'], true));
    return max(1, count($app));
}

/**
 * Apposition du specimen de l'utilisateur courant sur un document.
 * @param string $qualite  approbation | reglement
 * @return array{success:bool, error?:string, code?:string, apposition_id?:int}
 */
function apposer(int $documentId, string $qualite, string $password): array
{
    if (!is_logged_in()) {
        return ['success' => false, 'error' => 'Session requise.'];
    }
    if (!isset(QUALITES_SIGNATURE[$qualite])) {
        return ['success' => false, 'error' => 'Qualité de signature inconnue.'];
    }
    // « Signer un reglement : Coordinateur E mandataire, RAF -, Mandataire E », et
    // la ligne est fermee en phase 2 comme les autres actes de la depense (annexe B).
    if (droit_ecrivains('signer_reglement') === [] && droit_ecrivains('signer_preparateur') === []) {
        return ['success' => false, 'error' => 'La signature est fermée pendant la phase de suivi post-clôture (annexe B).'];
    }
    if ($qualite === 'reglement') {
        if (($refus = droit_ecriture('signer_reglement')) !== null) {
            return ['success' => false, 'error' => $refus];
        }
        if (!user_est_mandataire()) {
            return ['success' => false, 'error' => 'La signature de règlement est réservée aux mandataires du compte (annexe B).'];
        }
    }
    $stmt = db()->prepare('SELECT d.*, f.empreinte, f.chemin, f.coffre FROM documents d LEFT JOIN fichiers f ON f.id = d.fichier_id WHERE d.id = ?');
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return ['success' => false, 'error' => 'Document introuvable.'];
    }
    // Qui signe quoi vient du catalogue de l'annexe E, et non de la bonne volonte
    // du signataire : « Signer en qualite de preparateur : RAF » (annexe B) veut
    // dire qu'un bon de reception attend le RAF, et que le Coordinateur, qui a
    // pourtant un specimen, n'y a pas sa place.
    $attendues = array_values(array_filter(DOCUMENTS_GENERES[(string)$doc['type']][1] ?? [],
        fn($r) => !in_array($r, ['beneficiaire', 'prestataire'], true)));
    if (array_intersect($attendues, qualites_catalogue_utilisateur()) === []) {
        $libelles = array_map(fn($r) => ROLES_LIBELLES[$r] ?? $r, $attendues);
        audit('signature', 'apposition_refusee', 'document', $documentId,
            'Qualité hors du catalogue · attendu ' . ($libelles ? implode(', ', $libelles) : 'aucun signataire'));
        return ['success' => false, 'error' => $libelles === []
            ? 'Ce document n\'attend aucune signature applicative (annexe B).'
            : 'Ce document se signe en qualité de ' . implode(' ou de ', $libelles) . ' (annexe B).'];
    }
    if ($doc['statut'] !== 'a_signer') {
        return ['success' => false, 'error' => 'Ce document n\'est pas en attente de signature (statut : ' . $doc['statut'] . ').'];
    }
    if (!$doc['fichier_id']) {
        return ['success' => false, 'error' => 'Le document n\'a pas encore de fichier rendu.'];
    }
    $specimen = specimen_actif(user_id());
    if (!$specimen) {
        audit('signature', 'apposition_refusee', 'document', $documentId, 'Aucun spécimen actif avec acte de dépôt');
        return ['success' => false, 'error' => 'Aucun spécimen actif : déposez votre spécimen accompagné de son acte de dépôt signé.'];
    }
    if (!reauthenticate($password)) {
        return ['success' => false, 'error' => 'Réauthentification échouée : mot de passe incorrect.'];
    }
    $sessionEmpreinte = hash('sha256', session_id());
    $st = db()->prepare('SELECT signataire_id, session_empreinte FROM appositions WHERE document_id = ?');
    $st->execute([$documentId]);
    foreach ($st->fetchAll() as $a) {
        if ((int)$a['signataire_id'] === user_id()) {
            return ['success' => false, 'error' => 'Vous avez déjà signé ce document.'];
        }
        if (hash_equals($a['session_empreinte'], $sessionEmpreinte)) {
            audit('signature', 'apposition_refusee', 'document', $documentId, 'Deux appositions depuis la même session');
            return ['success' => false, 'error' => 'Deux signatures ne peuvent pas provenir de la même session.'];
        }
    }

    $code = generer_code_verification();
    $horodatage = date('Y-m-d H:i:s');
    $empreinteAvant = (string)$doc['empreinte'];

    // Rendu visuel : bloc de signature estampille sur la derniere page du PDF courant
    $fichierCourant = fichier((int)$doc['fichier_id']);
    $pdfBytes = $fichierCourant ? lire_fichier($fichierCourant) : null;
    if ($pdfBytes === null) {
        return ['success' => false, 'error' => 'Fichier du document illisible.'];
    }
    $imgFichier = fichier((int)$specimen['image_fichier_id']);
    $imgBytes = $imgFichier ? lire_fichier($imgFichier) : null;
    if ($imgBytes === null) {
        return ['success' => false, 'error' => 'Image du spécimen illisible.'];
    }
    $st->execute([$documentId]);
    $position = count($st->fetchAll());
    try {
        $signe = estampiller_pdf($pdfBytes, $imgBytes, [
            'nom'        => (string)user_nom(),
            'qualite'    => QUALITES_SIGNATURE[$qualite],
            'horodatage' => $horodatage,
            'code'       => $code,
        ], $position);
    } catch (Throwable $e) {
        error_log('estampiller_pdf: ' . $e->getMessage());
        audit('signature', 'apposition_refusee', 'document', $documentId,
            'Estampille impossible · ' . get_class($e) . ' : ' . mb_substr($e->getMessage(), 0, 180));
        return ['success' => false, 'error' => 'Impossible d\'apposer la signature sur le PDF. '
            . 'La raison technique est au journal d\'audit.'];
    }
    $nouveau = enregistrer_contenu($signe, 'pdf', 'application/pdf', 'documents', $fichierCourant['nom_genere'], false, (int)$doc['fichier_id']);
    if (!$nouveau['success']) {
        return ['success' => false, 'error' => $nouveau['error']];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO appositions (document_id, specimen_id, signataire_id, qualite, session_empreinte, empreinte_avant, empreinte_apres, code_verification, ip, appareil, horodatage)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$documentId, (int)$specimen['id'], user_id(), $qualite, $sessionEmpreinte, $empreinteAvant, $nouveau['empreinte'], $code, client_ip(), client_agent(), $horodatage]);
        $appId = (int)$pdo->lastInsertId();
        $nb = $position + 1;
        $statut = $nb >= signatures_attendues((string)$doc['type']) ? 'signe' : 'a_signer';
        $pdo->prepare('UPDATE documents SET fichier_id = ?, statut = ? WHERE id = ?')->execute([$nouveau['id'], $statut, $documentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('apposer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Enregistrement de l\'apposition impossible.'];
    }
    audit('signature', 'apposition', 'document', $documentId, 'Qualité ' . $qualite . ' · code ' . $code, $empreinteAvant, $nouveau['empreinte']);
    return ['success' => true, 'code' => $code, 'apposition_id' => $appId, 'statut' => $statut];
}

/**
 * Estampille un bloc de signature (image + identite + code) sur la derniere page d'un PDF.
 * Les gabarits Bousol reservent les 45 mm du bas de leur derniere page a cet effet.
 */
function estampiller_pdf(string $pdfBytes, string $imgBytes, array $bloc, int $position): string
{
    // mPDF ne vit plus forcement sous la racine web : un deploiement qui synchronise
    // l'arborescence l'a emporte trois fois, et il a ete mis a cote de la
    // configuration. Le chemin se resout donc au meme endroit que pour le rendu -
    // ici il etait reste en dur, et l'estampille echouait la ou le rendu passait.
    $autoload = PdfService::autoload_mpdf();
    if ($autoload === null) {
        throw new RuntimeException('mPDF introuvable : voir DEPLOIEMENT.md §5 et la clé app.mpdf');
    }
    require_once $autoload;
    $tmpDir = root_dir() . '/storage/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0750, true);
    }
    $src = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.pdf';
    $ext = (new finfo(FILEINFO_MIME_TYPE))->buffer($imgBytes) === 'image/png' ? 'png' : 'jpg';
    $img = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    file_put_contents($src, $pdfBytes);
    file_put_contents($img, $imgBytes);
    try {
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'tempDir' => $tmpDir . '/mpdf', 'default_font' => 'dejavusans']);
        $pages = $mpdf->setSourceFile($src);
        for ($p = 1; $p <= $pages; $p++) {
            $tpl = $mpdf->importPage($p);
            $size = $mpdf->getTemplateSize($tpl);
            $mpdf->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P', 'sheet-size' => [$size['width'], $size['height']]]);
            $mpdf->useTemplate($tpl);
            if ($p === $pages) {
                $w = 62; $h = 34;
                $x = 14 + ($position % 3) * ($w + 3);
                $y = $size['height'] - 44 - intdiv($position, 3) * ($h + 2);
                // Sans hauteur fixe et en overflow "hidden" : mPDF ne genere pas de page supplementaire.
                $html = '<div style="font-family:dejavusans;font-size:6.7pt;color:#2a2a28;border:0.4pt solid #4c5a47;padding:1.6mm;line-height:1.25">'
                      . '<img src="' . $img . '" style="height:11mm;max-width:48mm"><br>'
                      . '<b>' . htmlspecialchars($bloc['nom']) . '</b><br>'
                      . htmlspecialchars($bloc['qualite']) . '<br>'
                      . 'Signé électroniquement le ' . htmlspecialchars(date('d/m/Y H:i', strtotime($bloc['horodatage']))) . '<br>'
                      . 'Code : <b>' . htmlspecialchars($bloc['code']) . '</b></div>';
                $mpdf->WriteFixedPosHTML($html, $x, $y, $w, $h, 'hidden');
            }
        }
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } finally {
        @unlink($src);
        @unlink($img);
    }
}
