<?php
declare(strict_types=1);

/**
 * Service de rendu documentaire (CDC 7.1) : transforme un gabarit PHP/HTML
 * en PDF via mPDF, puis l'enregistre dans `fichiers` (empreinte, auteur).
 * Ne possede aucune donnee propre : chaque module fournit son gabarit et ses donnees.
 *
 *   $pdf = new PdfService();
 *   $html = $pdf->html('journal_caisse', $data);          // rendu HTML (apercu)
 *   $res  = $pdf->generer('journal_caisse', $data, 'Journal-caisse-M03.pdf'); // ['success'=>true,'id'=>fichier_id]
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/calendrier.php';
require_once __DIR__ . '/../includes/referentiels.php';
require_once __DIR__ . '/../includes/uploads.php';

final class PdfService
{
    private string $tplDir;
    private string $tmpDir;

    public function __construct()
    {
        $this->tplDir = __DIR__ . '/templates';
        $this->tmpDir = root_dir() . '/storage/tmp/mpdf';
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0750, true);
        }
    }

    /** En-tete commun a tous les documents generes (organisation, projet, bailleur, contrat). */
    public static function entete(): array
    {
        return [
            'organisation'  => param('nom_organisation', 'DÉVELOPPEMENT ET DYNAMISME'),
            'projet'        => param('nom_projet', 'KèsKlè'),
            'contrat'       => param('numero_contrat') ?? '________________',
            'bailleur'      => 'Programme d\'Appui aux Initiatives Émergentes de la Société Civile (PAIESC) — financé par l\'Union européenne',
            'compte'        => param('compte_bancaire', ''),
            'debut'         => date_debut(),
            'fin'           => date_fin(),
            'lieu'          => 'Cap-Haïtien, Haïti',
        ];
    }

    public function html(string $template, array $data): string
    {
        $tpl = $this->tplDir . '/' . basename($template) . '.php';
        if (!is_file($tpl)) {
            throw new RuntimeException("Gabarit introuvable : $template");
        }
        $entete = self::entete();
        extract($data, EXTR_SKIP);
        ob_start();
        include $tpl;
        return (string)ob_get_clean();
    }

    /** PDF en memoire, sans enregistrement dans `fichiers` (impressions a signer, apercus). */
    public function rendre_binaire(string $template, array $data): ?string
    {
        $autoload = root_dir() . '/lib/mpdf/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }
        require_once $autoload;
        try {
            $html = $this->html($template, $data);
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'orientation' => $data['orientation'] ?? 'P',
                'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 16, 'margin_bottom' => 18,
                'tempDir' => $this->tmpDir, 'default_font' => 'dejavuserif',
            ]);
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        } catch (Throwable $e) {
            error_log('PdfService rendre_binaire: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param string $exemplaire  Mention imprimee (EXEMPLAIRES[i]) ou '' en regime electronique
     * @return array{success:bool, id?:int, error?:string}
     */
    public function generer(string $template, array $data, string $nomGenere, string $exemplaire = ''): array
    {
        $autoload = root_dir() . '/lib/mpdf/autoload.php';
        if (!is_file($autoload)) {
            return ['success' => false, 'error' => 'mPDF non installé (bousol/lib/mpdf/)'];
        }
        require_once $autoload;
        try {
            $data['exemplaire'] = $exemplaire;
            $html = $this->html($template, $data);
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'orientation' => $data['orientation'] ?? 'P',
                'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 16, 'margin_bottom' => 18,
                'tempDir' => $this->tmpDir, 'default_font' => 'dejavuserif',
            ]);
            $mpdf->SetTitle($nomGenere);
            $mpdf->WriteHTML($html);
            $bin = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
            return enregistrer_contenu($bin, 'pdf', 'application/pdf', 'documents', $nomGenere);
        } catch (Throwable $e) {
            error_log('PdfService: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Échec de génération PDF'];
        }
    }
}
