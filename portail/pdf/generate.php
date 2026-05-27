<?php
declare(strict_types=1);

/**
 * Service de generation PDF via mPDF 8.x.
 *
 * Stub Phase 2-3. mPDF doit etre depose dans portail/lib/mpdf/ avant utilisation.
 * Telechargement : composer require mpdf/mpdf (en local) puis FTP vers le serveur.
 *
 * Usage typique (Phase 2+) :
 *
 *   require_once __DIR__ . '/portail/pdf/generate.php';
 *   $service = new PdfService();
 *   $path = $service->generate('f02', [
 *       'imputation' => $imputation,
 *       'decaissement' => $decaissement,
 *       'signatures' => [...],
 *   ], 'storage/dossiers/2026/M01/DEP-001/f02.pdf');
 */

final class PdfService
{
    private string $tplDir;
    private string $tmpDir;

    public function __construct()
    {
        $this->tplDir = __DIR__ . '/templates';
        $this->tmpDir = __DIR__ . '/../lib/mpdf/tmp';
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0755, true);
        }
    }

    /**
     * Genere un PDF depuis un template HTML.
     *
     * @param string $template     Nom du template (sans extension), ex: 'f01', 'f02', 'dossier_complet'
     * @param array  $data         Donnees a injecter (passees par extract() au template)
     * @param string $destRelative Chemin relatif depuis portail/ (ex: 'storage/dossiers/2026/M01/DEP-001/f02.pdf')
     * @return string|null         Chemin absolu si succes, null si echec
     */
    public function generate(string $template, array $data, string $destRelative): ?string
    {
        $tplFile = $this->tplDir . '/' . basename($template) . '.html';
        if (!is_file($tplFile)) {
            error_log("PDF template manquant : $tplFile");
            return null;
        }

        // Charge mPDF si disponible
        $mpdfAutoload = __DIR__ . '/../lib/mpdf/autoload.php';
        if (!file_exists($mpdfAutoload)) {
            error_log('mPDF library not installed. Run composer require mpdf/mpdf and copy /vendor/mpdf/mpdf to portail/lib/mpdf/');
            return null;
        }
        require_once $mpdfAutoload;

        try {
            // Rendu du template HTML
            extract($data, EXTR_SKIP);
            ob_start();
            include $tplFile;
            $html = (string)ob_get_clean();

            $mpdf = new \Mpdf\Mpdf([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_left'   => 15,
                'margin_right'  => 15,
                'margin_top'    => 20,
                'margin_bottom' => 20,
                'tempDir'       => $this->tmpDir,
                'default_font'  => 'Arial',
            ]);

            $absPath = dirname(__DIR__) . '/' . ltrim($destRelative, '/');
            $dir = dirname($absPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $mpdf->WriteHTML($html);
            $mpdf->Output($absPath, \Mpdf\Output\Destination::FILE);

            return $absPath;
        } catch (Throwable $e) {
            error_log('PdfService generate failed: ' . $e->getMessage());
            return null;
        }
    }
}
