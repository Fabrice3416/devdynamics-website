<?php
/**
 * CandidaturePDF — builds a PDF document for an ACP application
 * using SimplePDF.
 */

require_once __DIR__ . '/SimplePDF.php';

class CandidaturePDF {
    /**
     * Build the PDF and return its binary content.
     *
     * @param string $candidatureId  e.g. "20260429-1"
     * @param array  $data           form data (associative array)
     * @return string                PDF binary
     */
    public static function build($candidatureId, $data) {
        $pdf = new SimplePDF();
        // Tighter margins than the SimplePDF defaults so a complete
        // application fits on a single A4 page.
        $pdf->marginTop = 40;
        $pdf->marginBottom = 40;
        $pdf->marginLeft = 45;
        $pdf->marginRight = 45;
        // Re-anchor the cursor since margins changed after construction.
        $pdf->cursorY = $pdf->pageHeight - $pdf->marginTop;

        // -------- Header (logo + masthead) --------
        $logoPath = self::ensureLogoJpeg();
        if ($logoPath) {
            // Source logo is 457x73 → at 140pt wide it is ~22.4pt tall.
            $pdf->image($logoPath, 140, null, 'left');
            // Logo bottom now sits at cursorY; reserve enough room for the
            // 15pt title's ascent so the next text line does not overlap it.
            $pdf->moveDown(20);
        }

        $pdf->setFont(15, true);
        $pdf->text('Académie des Cartographes Populaires');

        $pdf->setFont(10, false);
        $pdf->text('+509 47 41 8737  |  contact@dev-dynamics.org  |  Rues 15-16 B, Cap-Haïtien');

        $pdf->moveDown(4);
        $pdf->hr();
        $pdf->moveDown(6);

        // -------- Title block --------
        $pdf->setFont(13, true);
        $pdf->text('Formulaire de Candidature');
        $pdf->moveDown(2);

        $pdf->setFont(10, false);
        $pdf->labelValue('N° de candidature :', $candidatureId);
        $pdf->labelValue('Date de soumission :', date('d/m/Y H:i'));
        $pdf->moveDown(4);

        // -------- Section 1 : Identification --------
        self::sectionHeading($pdf, '1. Identification');
        $pdf->labelValue('Prénom(s) :', $data['prenom'] ?? '');
        $pdf->labelValue('Nom de famille :', $data['nom'] ?? '');
        $pdf->labelValue('Date de naissance :', self::formatDate($data['date_naissance'] ?? ''));
        $pdf->labelValue('Sexe :', $data['sexe'] ?? '');
        $pdf->labelValue('Lieu de naissance :', $data['lieu_naissance'] ?? '');
        $pdf->labelValue('N° pièce d\'identité :', $data['piece_identite'] ?? '');
        $pdf->moveDown(4);

        // -------- Section 2 : Coordonnées --------
        self::sectionHeading($pdf, '2. Coordonnées');
        $pdf->labelValue('Adresse :', $data['adresse'] ?? '');
        $pdf->labelValue('Commune :', $data['commune'] ?? '');
        $pdf->labelValue('Département :', $data['departement'] ?? '');
        $pdf->labelValue('Téléphone principal :', $data['telephone'] ?? '');
        $pdf->labelValue('WhatsApp :', $data['whatsapp'] ?? '');
        $pdf->labelValue('Email :', $data['email'] ?? '');
        $pdf->labelValue('Source de l\'info :', $data['source'] ?? '');
        $pdf->moveDown(4);

        // -------- Section 3 : Parcours --------
        self::sectionHeading($pdf, '3. Parcours académique et professionnel');
        $pdf->labelValue('Niveau d\'études :', $data['niveau_etudes'] ?? '');
        $pdf->labelValue('Établissement :', $data['etablissement'] ?? '');
        $pdf->labelValue('Filière / Spécialité :', $data['filiere'] ?? '');
        $pdf->labelValue('Situation actuelle :', $data['situation'] ?? '');

        $pdf->moveDown(2);
        $pdf->setFont(10, true);
        $pdf->text('Expérience pertinente (cartographie, SIG, informatique) :');
        $pdf->setFont(10, false);
        $pdf->paragraph(self::nonEmpty($data['experience'] ?? '', '—'));
        $pdf->moveDown(4);

        // -------- Section 4 : Motivation --------
        self::sectionHeading($pdf, '4. Motivation et engagement');

        $pdf->setFont(10, true);
        $pdf->text('Pourquoi rejoindre l\'Académie :');
        $pdf->setFont(10, false);
        $pdf->paragraph(self::nonEmpty($data['motivation'] ?? '', '—'));
        $pdf->moveDown(2);

        $pdf->setFont(10, true);
        $pdf->text('Usage envisagé des compétences acquises :');
        $pdf->setFont(10, false);
        $pdf->paragraph(self::nonEmpty($data['usage_envisage'] ?? '', '—'));
        $pdf->moveDown(2);

        $pdf->labelValue('Disponibilité :', $data['disponibilite'] ?? '');
        if (!empty($data['contraintes'])) {
            $pdf->setFont(10, true);
            $pdf->text('Contraintes précisées :');
            $pdf->setFont(10, false);
            $pdf->paragraph($data['contraintes']);
        }
        $pdf->moveDown(4);

        // -------- Section 5 : Déclarations --------
        self::sectionHeading($pdf, '5. Déclarations');
        // Bullet (Win-1252 0x95) renders reliably; checkbox glyphs do not.
        $pdf->paragraph("\xE2\x80\xA2 Le/la candidat(e) s'engage à transmettre une copie de sa pièce d'identité valide (CIN, passeport ou document officiel avec photo) à contact@dev-dynamics.org ou en personne au siège.");
        $pdf->moveDown(2);
        $pdf->paragraph("\xE2\x80\xA2 Le/la candidat(e) certifie sur l'honneur l'exactitude des informations fournies et déclare remplir les critères d'éligibilité (18-25 ans, résidence dans l'arrondissement du Cap-Haïtien).");

        // -------- Funding notice --------
        $pdf->moveDown(6);
        $pdf->hr();
        $pdf->moveDown(3);
        $pdf->setFont(8, false);
        $pdf->paragraph('Financé par l\'Union européenne dans le cadre du PAIESC (Contrat N° PAIESC/CS/04-2026/021) — Programme d\'Appui aux Initiatives Émergentes de la Société Civile en Haïti.');

        return $pdf->output();
    }

    /**
     * Render a section heading with consistent spacing above and below.
     */
    private static function sectionHeading($pdf, $title) {
        $pdf->setFont(12, true);
        $pdf->text($title);
        $pdf->moveDown(1);
        $pdf->setFont(10, false);
    }

    private static function formatDate($iso) {
        if (empty($iso)) return '';
        $ts = strtotime($iso);
        return $ts ? date('d/m/Y', $ts) : $iso;
    }

    private static function nonEmpty($v, $default) {
        $v = trim((string) $v);
        return $v === '' ? $default : $v;
    }

    /**
     * Convert the PNG logo to a flattened JPEG (white background) on first
     * use, cache it under api/storage/cache/, and return its path.
     * Returns null if GD is unavailable or the source PNG is missing.
     */
    private static function ensureLogoJpeg() {
        $cacheDir = __DIR__ . '/../storage/cache';
        $cachePath = $cacheDir . '/logo.jpg';
        $pngPath = __DIR__ . '/../../assets/images/logo_6.png';

        // Reuse the cached JPEG only if it is at least as fresh as the source.
        if (file_exists($cachePath) && file_exists($pngPath)
            && filemtime($cachePath) >= filemtime($pngPath)) {
            return $cachePath;
        }
        if (!file_exists($pngPath)) return null;
        if (!function_exists('imagecreatefrompng')) return null;

        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        $png = @imagecreatefrompng($pngPath);
        if (!$png) return null;
        $w = imagesx($png);
        $h = imagesy($png);

        // Flatten transparency onto a white background so the JPEG has no
        // alpha channel (PDFs can't render alpha through DCTDecode).
        $jpg = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($jpg, 255, 255, 255);
        imagefilledrectangle($jpg, 0, 0, $w, $h, $white);
        imagecopy($jpg, $png, 0, 0, 0, 0, $w, $h);

        $ok = @imagejpeg($jpg, $cachePath, 90);
        imagedestroy($png);
        imagedestroy($jpg);
        return $ok ? $cachePath : null;
    }
}
