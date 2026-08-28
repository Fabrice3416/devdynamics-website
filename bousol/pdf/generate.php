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
    /**
     * En-tete commun a tous les documents generes. Le projet et son bailleur se
     * lisent dans la table des projets et non dans une constante : le meme gabarit
     * sert KesKle et Koule Ki Pale, et un document qui annoncerait le mauvais
     * bailleur serait irrecevable. La mention longue exigee par certains contrats
     * - « financé par l'Union européenne » - se met dans le parametre du projet.
     */
    public static function entete(): array
    {
        $projet = null;
        if (projet_id() !== null) {
            $st = db()->prepare('SELECT intitule, bailleur FROM projets WHERE id = ?');
            $st->execute([projet_id()]);
            $projet = $st->fetch() ?: null;
        }
        return [
            'organisation'  => param('nom_organisation', 'DÉVELOPPEMENT ET DYNAMISME'),
            'projet'        => $projet['intitule'] ?? (projet_intitule() ?? ''),
            'contrat'       => param('numero_contrat') ?? '________________',
            'bailleur'      => param('mention_bailleur') ?? ($projet['bailleur'] ?? ''),
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
            // Un null silencieux a deja coute une demi-journee : la bibliotheque
            // n'est pas dans le depot et ne se deploie pas par git pull.
            error_log('PdfService : mPDF absent, ' . $autoload . ' introuvable (voir DEPLOIEMENT.md §5)');
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
