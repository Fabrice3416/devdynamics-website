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

        // -------- Header --------
        $pdf->setFont(16, true);
        $pdf->text('DevDynamics — Académie des Cartographes Populaires');

        $pdf->setFont(11, false);
        $pdf->text('+509 47 41 8737  |  contact@dev-dynamics.org  |  Rues 15-16 B, Cap-Haïtien');

        $pdf->moveDown(6);
        $pdf->hr();
        $pdf->moveDown(8);

        $pdf->setFont(14, true);
        $pdf->text('Formulaire de Candidature');
        $pdf->moveDown(4);

        $pdf->setFont(11, false);
        $pdf->labelValue('N° de candidature :', $candidatureId);
        $pdf->labelValue('Date de soumission :', date('d/m/Y H:i'));
        $pdf->moveDown(8);
        $pdf->hr();
        $pdf->moveDown(6);

        // -------- Section 1 : Identification --------
        $pdf->heading('1. Identification', 13);
        $pdf->moveDown(2);
        $pdf->setFont(11, false);

        $pdf->labelValue('Prénom(s) :', $data['prenom'] ?? '');
        $pdf->labelValue('Nom de famille :', $data['nom'] ?? '');
        $pdf->labelValue('Date de naissance :', self::formatDate($data['date_naissance'] ?? ''));
        $pdf->labelValue('Sexe :', $data['sexe'] ?? '');
        $pdf->labelValue('Lieu de naissance :', $data['lieu_naissance'] ?? '');
        $pdf->labelValue('N° pièce d\'identité :', $data['piece_identite'] ?? '');
        $pdf->moveDown(8);

        // -------- Section 2 : Coordonnées --------
        $pdf->heading('2. Coordonnées', 13);
        $pdf->moveDown(2);
        $pdf->setFont(11, false);

        $pdf->labelValue('Adresse :', $data['adresse'] ?? '');
        $pdf->labelValue('Commune :', $data['commune'] ?? '');
        $pdf->labelValue('Département :', $data['departement'] ?? '');
        $pdf->labelValue('Téléphone principal :', $data['telephone'] ?? '');
        $pdf->labelValue('WhatsApp :', $data['whatsapp'] ?? '');
        $pdf->labelValue('Email :', $data['email'] ?? '');
        $pdf->labelValue('Source de l\'info :', $data['source'] ?? '');
        $pdf->moveDown(8);

        // -------- Section 3 : Parcours --------
        $pdf->heading('3. Parcours académique et professionnel', 13);
        $pdf->moveDown(2);
        $pdf->setFont(11, false);

        $pdf->labelValue('Niveau d\'études :', $data['niveau_etudes'] ?? '');
        $pdf->labelValue('Établissement :', $data['etablissement'] ?? '');
        $pdf->labelValue('Filière / Spécialité :', $data['filiere'] ?? '');
        $pdf->labelValue('Situation actuelle :', $data['situation'] ?? '');

        $pdf->moveDown(2);
        $pdf->setFont(11, true);
        $pdf->text('Expérience pertinente (cartographie, SIG, informatique) :');
        $pdf->setFont(11, false);
        $pdf->paragraph(self::nonEmpty($data['experience'] ?? '', '—'));
        $pdf->moveDown(8);

        // -------- Section 4 : Motivation --------
        $pdf->heading('4. Motivation et engagement', 13);
        $pdf->moveDown(2);

        $pdf->setFont(11, true);
        $pdf->text('Pourquoi rejoindre l\'Académie :');
        $pdf->setFont(11, false);
        $pdf->paragraph(self::nonEmpty($data['motivation'] ?? '', '—'));
        $pdf->moveDown(4);

        $pdf->setFont(11, true);
        $pdf->text('Usage envisagé des compétences acquises :');
        $pdf->setFont(11, false);
        $pdf->paragraph(self::nonEmpty($data['usage_envisage'] ?? '', '—'));
        $pdf->moveDown(4);

        $pdf->labelValue('Disponibilité :', $data['disponibilite'] ?? '');
        if (!empty($data['contraintes'])) {
            $pdf->setFont(11, true);
            $pdf->text('Contraintes précisées :');
            $pdf->setFont(11, false);
            $pdf->paragraph($data['contraintes']);
        }
        $pdf->moveDown(8);

        // -------- Section 5 : Déclarations --------
        $pdf->heading('5. Déclarations', 13);
        $pdf->moveDown(2);
        $pdf->setFont(11, false);

        $pdf->paragraph('☑ Le/la candidat(e) s\'engage à transmettre une copie de sa pièce d\'identité valide (CIN, passeport ou document officiel avec photo) à contact@dev-dynamics.org ou en personne au siège.');
        $pdf->moveDown(2);
        $pdf->paragraph('☑ Le/la candidat(e) certifie sur l\'honneur l\'exactitude des informations fournies et déclare remplir les critères d\'éligibilité (18-25 ans, résidence dans l\'arrondissement du Cap-Haïtien).');

        $pdf->moveDown(12);
        $pdf->hr();
        $pdf->moveDown(4);
        $pdf->setFont(9, false);
        $pdf->paragraph('Financé par l\'Union européenne dans le cadre du PAIESC (Contrat N° PAIESC/CS/04-2026/021) — Programme d\'Appui aux Initiatives Émergentes de la Société Civile en Haïti.');

        return $pdf->output();
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
}
