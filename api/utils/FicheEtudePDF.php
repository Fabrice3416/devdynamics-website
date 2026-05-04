<?php
/**
 * FicheEtudePDF — builds a PDF document for an ACP teacher study sheet
 * (Fiche d'étude — Enseignant(e)) using SimplePDF.
 */

require_once __DIR__ . '/SimplePDF.php';

class FicheEtudePDF {
    /**
     * Build the PDF and return its binary content.
     *
     * @param string $ficheId  e.g. "20260504-1"
     * @param array  $data     form data (associative array)
     * @return string          PDF binary
     */
    public static function build($ficheId, $data) {
        $pdf = new SimplePDF();
        $pdf->marginTop = 40;
        $pdf->marginBottom = 40;
        $pdf->marginLeft = 45;
        $pdf->marginRight = 45;
        $pdf->cursorY = $pdf->pageHeight - $pdf->marginTop;

        // -------- Header (logo + masthead) --------
        $logoPath = self::ensureLogoJpeg();
        if ($logoPath) {
            $pdf->image($logoPath, 140, null, 'left');
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
        $pdf->text('Fiche d\'étude — Enseignant(e)');
        $pdf->setFont(10, false);
        $pdf->text('« Ma carte, mon quartier, mon danger » — Séance de 15 minutes en classe');
        $pdf->moveDown(3);

        $pdf->labelValue('N° de fiche :', $ficheId);
        $pdf->labelValue('Date de soumission :', date('d/m/Y H:i'));
        $pdf->moveDown(4);

        // -------- 1. Identification --------
        self::sectionHeading($pdf, '1. Identification de l\'établissement et de l\'enseignant(e)');
        $pdf->labelValue('Nom de l\'établissement :', $data['etablissement_nom'] ?? '');
        $pdf->labelValue('Adresse complète :', $data['etablissement_adresse'] ?? '');
        $pdf->labelValue('Enseignant(e) :', trim(($data['enseignant_prenom'] ?? '') . ' ' . ($data['enseignant_nom'] ?? '')));
        $pdf->labelValue('Téléphone :', $data['telephone'] ?? '');
        $pdf->labelValue('WhatsApp :', $data['whatsapp'] ?? '');
        $pdf->labelValue('Adresse courriel :', $data['email'] ?? '');
        $pdf->moveDown(4);

        // -------- 2. Description de la classe --------
        self::sectionHeading($pdf, '2. Description de la classe proposée');
        $pdf->labelValue('Niveau (classe) :', $data['niveau_classe'] ?? '');
        $pdf->labelValue('Nombre d\'élèves :', $data['nombre_eleves'] ?? '');
        $pdf->labelValue('Âge moyen des élèves :', $data['age_moyen'] ?? '');
        $pdf->labelValue('Meilleur créneau :', $data['creneau'] ?? '');
        $pdf->moveDown(4);

        // -------- 3. Contexte environnemental --------
        self::sectionHeading($pdf, '3. Contexte environnemental du quartier');

        $risques = $data['risques'] ?? [];
        if (is_array($risques) && !empty($risques)) {
            $pdf->labelValue('Risques présents :', implode(' ; ', $risques));
        } else {
            $pdf->labelValue('Risques présents :', '—');
        }
        if (!empty($data['risque_autre'])) {
            $pdf->labelValue('Autre risque :', $data['risque_autre']);
        }

        $touches = $data['eleves_touches'] ?? '';
        if ($touches === 'Oui' && !empty($data['eleves_touches_lequel'])) {
            $touches .= ' — ' . $data['eleves_touches_lequel'];
        }
        $pdf->labelValue('Élèves touchés :', $touches);

        if (!empty($data['risque_visible'])) {
            $pdf->moveDown(2);
            $pdf->setFont(10, true);
            $pdf->text('Risque visible depuis l\'école / sur le chemin :');
            $pdf->setFont(10, false);
            $pdf->paragraph($data['risque_visible']);
        }

        $zones = $data['zones_a_eviter'] ?? '';
        if ($zones === 'Oui' && !empty($data['zones_precisions'])) {
            $zones .= ' — ' . $data['zones_precisions'];
        }
        $pdf->labelValue('Zones à éviter :', $zones);
        $pdf->moveDown(4);

        // -------- 4. Niveau et comportement --------
        self::sectionHeading($pdf, '4. Niveau et comportement de la classe');
        $pdf->labelValue('Niveau lecture/écriture :', self::scoreLabel($data['niveau_lecture'] ?? ''));
        $pdf->labelValue('Capacité d\'attention :', self::scoreLabel($data['capacite_attention'] ?? ''));
        $pdf->labelValue('Aisance à l\'oral :', self::scoreLabel($data['aisance_oral'] ?? ''));
        $pdf->labelValue('Notions de géographie :', $data['notions_geo'] ?? '');
        $pdf->labelValue('Sait dessiner un plan :', $data['plan_simple'] ?? '');

        $besoins = $data['besoins_particuliers'] ?? '';
        if ($besoins === 'Oui' && !empty($data['besoins_precisions'])) {
            $besoins .= ' — ' . $data['besoins_precisions'];
        }
        $pdf->labelValue('Besoins particuliers :', $besoins);
        $pdf->labelValue('Langue d\'instruction :', $data['langue_instruction'] ?? '');
        $pdf->moveDown(4);

        // -------- 5. Logistique --------
        self::sectionHeading($pdf, '5. Logistique et disponibilité');
        $pdf->labelValue('Date souhaitée (1er choix) :', self::formatDate($data['date_souhaitee_1'] ?? ''));
        $pdf->labelValue('Date souhaitée (2e choix) :', self::formatDate($data['date_souhaitee_2'] ?? ''));

        $videoproj = $data['videoprojecteur'] ?? '';
        if (!empty($data['videoprojecteur_precision'])) {
            $videoproj .= ' — ' . $data['videoprojecteur_precision'];
        }
        $pdf->labelValue('Vidéoprojecteur / écran :', $videoproj);

        $imprimante = $data['imprimante'] ?? '';
        if (!empty($data['imprimante_precision'])) {
            $imprimante .= ' — ' . $data['imprimante_precision'];
        }
        $pdf->labelValue('Imprimante :', $imprimante);

        if (!empty($data['nb_feuilles'])) {
            $pdf->labelValue('Nb feuilles à imprimer :', $data['nb_feuilles']);
        }
        $pdf->labelValue('Langue d\'animation :', $data['langue_animation'] ?? '');

        $disponible = $data['disponible_encadrer'] ?? '';
        if (!empty($data['disponible_precision'])) {
            $disponible .= ' — ' . $data['disponible_precision'];
        }
        $pdf->labelValue('Disponible pour encadrer :', $disponible);

        $conserver = $data['conserver_feuilles'] ?? '';
        if (!empty($data['conserver_precision'])) {
            $conserver .= ' — ' . $data['conserver_precision'];
        }
        $pdf->labelValue('Conserver les feuilles :', $conserver);
        $pdf->moveDown(4);

        // -------- 6. Motivation --------
        self::sectionHeading($pdf, '6. Motivation et attentes');

        $pdf->setFont(10, true);
        $pdf->text('Pourquoi accueillir cette séance :');
        $pdf->setFont(10, false);
        $pdf->paragraph(self::nonEmpty($data['motivation'] ?? '', '—'));
        $pdf->moveDown(2);

        $pdf->setFont(10, true);
        $pdf->text('Ce que les élèves devraient retenir :');
        $pdf->setFont(10, false);
        $pdf->paragraph(self::nonEmpty($data['attentes'] ?? '', '—'));
        $pdf->moveDown(2);

        $pdf->labelValue('Collaboration future :', $data['collaboration_future'] ?? '');

        if (!empty($data['suggestions'])) {
            $pdf->moveDown(2);
            $pdf->setFont(10, true);
            $pdf->text('Suggestions / demandes spécifiques :');
            $pdf->setFont(10, false);
            $pdf->paragraph($data['suggestions']);
        }
        $pdf->moveDown(4);

        // -------- 7. Autorisations --------
        self::sectionHeading($pdf, '7. Autorisations');
        $pdf->paragraph("\xE2\x80\xA2 " . self::checkLine('Animer une séance pédagogique dans la classe à la date convenue.', !empty($data['auth_animation'])));
        $pdf->moveDown(2);
        $pdf->paragraph("\xE2\x80\xA2 " . self::checkLine('Photographier les activités des élèves à des fins de documentation (sans publication de photos identifiables d\'enfants sans accord parental).', !empty($data['auth_photos'])));
        $pdf->moveDown(2);
        $pdf->paragraph("\xE2\x80\xA2 " . self::checkLine('Conserver les feuilles d\'activité remplies comme données du projet Académie des Cartographes Populaires.', !empty($data['auth_conservation'])));

        // -------- Funding notice --------
        $pdf->moveDown(6);
        $pdf->hr();
        $pdf->moveDown(3);
        $pdf->setFont(8, false);
        $pdf->paragraph('Financé par l\'Union européenne dans le cadre du PAIESC (Contrat N° PAIESC/CS/04-2026/021) — Programme d\'Appui aux Initiatives Émergentes de la Société Civile en Haïti.');

        return $pdf->output();
    }

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

    private static function scoreLabel($v) {
        $v = trim((string) $v);
        if ($v === '') return '—';
        return $v . ' / 5';
    }

    private static function checkLine($text, $checked) {
        return ($checked ? '[X] ' : '[ ] ') . $text;
    }

    private static function nonEmpty($v, $default) {
        $v = trim((string) $v);
        return $v === '' ? $default : $v;
    }

    /**
     * Reuses the same logo cache file as CandidaturePDF so we don't duplicate
     * the JPEG conversion. See CandidaturePDF::ensureLogoJpeg for details.
     */
    private static function ensureLogoJpeg() {
        $cacheDir = __DIR__ . '/../storage/cache';
        $cachePath = $cacheDir . '/logo.jpg';
        $pngPath = __DIR__ . '/../../assets/images/logo_6.png';

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
