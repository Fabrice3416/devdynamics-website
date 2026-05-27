<?php
declare(strict_types=1);

// Performances Hostinger mutualise (audit V4)
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../pdf/generate.php';

check_role(['administrateur', 'coordinateur']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/portail/reporting/rapports.php');
}
verify_csrf();

$type = (string)($_POST['type'] ?? '');
$mois = (int)($_POST['mois'] ?? 0);
$annee = (int)($_POST['annee'] ?? date('Y'));
$tri  = (string)($_POST['tri'] ?? 'chronologique');

$cfg = config();

switch ($type) {
    case 'rfm': generate_rfm($mois, $annee); break;
    case 'dj':  generate_dj($mois, $annee, $tri); break;
    case 'cumule':
        $deb = (string)($_POST['periode_debut'] ?? '');
        $fin = (string)($_POST['periode_fin'] ?? '');
        generate_cumule($deb, $fin);
        break;
    default:
        flash_set('danger', 'Type de rapport invalide.');
        redirect('/portail/reporting/rapports.php');
}

// =====================================================================
function generate_rfm(int $mois, int $annee): void
{
    // Stats du mois
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS nb, COALESCE(SUM(d.total_net_a_verser),0) AS total
           FROM decaissements d
           JOIN imputations i ON d.imputation_id = i.id
          WHERE MONTH(i.date_depense) = ? AND YEAR(i.date_depense) = ?"
    );
    $stmt->execute([$mois, $annee]);
    $stats = $stmt->fetch();

    // PDP vs realise
    $stmt = db()->prepare(
        "SELECT lb.code, lb.libelle, lb.budget_initial_htg,
                COALESCE(p.montant_previsionnel, 0) AS previsionnel,
                COALESCE(SUM(d.montant_brut), 0) AS realise_mois,
                COALESCE((SELECT SUM(d2.montant_brut) FROM decaissements d2 JOIN imputations i2 ON d2.imputation_id=i2.id
                          WHERE i2.ligne_budgetaire_id=lb.id AND i2.statut='valide'),0) AS realise_cumul
           FROM lignes_budgetaires lb
           LEFT JOIN plan_decaissement p ON p.ligne_budgetaire_id = lb.id AND p.mois = ?
           LEFT JOIN imputations i ON i.ligne_budgetaire_id = lb.id AND MONTH(i.date_depense)=? AND YEAR(i.date_depense)=? AND i.statut='valide'
           LEFT JOIN decaissements d ON d.imputation_id = i.id
          GROUP BY lb.id, p.montant_previsionnel"
    );
    $stmt->execute([$mois, $mois, $annee]);
    $lignesPdp = $stmt->fetchAll();

    // Journal
    $stmt = db()->prepare(
        "SELECT * FROM journal_depenses
          WHERE MONTH(date_depense)=? AND YEAR(date_depense)=?
          ORDER BY date_depense"
    );
    $stmt->execute([$mois, $annee]);
    $journal = $stmt->fetchAll();

    // Rapprochement
    $stmt = db()->prepare("SELECT * FROM rapprochements_bancaires WHERE mois=? AND annee=? LIMIT 1");
    $stmt->execute([$mois, $annee]);
    $rapprochement = $stmt->fetch();

    // Caisse
    $cfg = config();
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS nb, COALESCE(SUM(montant),0) AS total
           FROM caisse_transactions
          WHERE MONTH(date_depense)=? AND YEAR(date_depense)=?"
    );
    $stmt->execute([$mois, $annee]);
    $caisseMois = $stmt->fetch();

    // Cree l'entree rapports_generes
    $numero = 'RFM-ACP-' . $annee . '-M' . str_pad((string)$mois,2,'0',STR_PAD_LEFT);
    $stmt = db()->prepare(
        "INSERT INTO rapports_generes
            (numero, type_rapport, periode_debut, periode_fin, nb_dossiers, montant_total_htg,
             ordre_tri, statut, genere_par)
         VALUES (?, 'rfm', ?, LAST_DAY(?), ?, ?, 'chronologique', 'en_cours', ?)"
    );
    $periodeDebut = sprintf('%04d-%02d-01', $annee, $mois);
    $stmt->execute([$numero, $periodeDebut, $periodeDebut, (int)$stats['nb'], (float)$stats['total'], (int)user_id()]);
    $rapId = (int)db()->lastInsertId();

    // Genere le PDF
    $service = new PdfService();
    $rel = sprintf('storage/rapports/%d/M%02d/%s.pdf', $annee, $mois, $numero);
    $pdfPath = $service->generate('rfm', [
        'rfm' => [
            'mois' => $mois, 'annee' => $annee,
            'mois_nom' => mois_fr($mois),
            'stats' => $stats,
            'lignes' => $lignesPdp,
            'journal' => $journal,
            'rapprochement' => $rapprochement,
            'caisse_mois' => $caisseMois,
            'budget_total' => $cfg['app']['budget_total'],
        ]
    ], $rel);

    if ($pdfPath && is_file($pdfPath)) {
        $stmt = db()->prepare("UPDATE rapports_generes SET statut='genere', fichier_pdf=? WHERE id=?");
        $stmt->execute([$rel, $rapId]);
        audit_log('rapport_genere', "RFM $numero genere", 'rapports_generes', $rapId);
        flash_set('success', "RFM $numero genere.");
    } else {
        $stmt = db()->prepare("UPDATE rapports_generes SET statut='erreur' WHERE id=?");
        $stmt->execute([$rapId]);
        flash_set('warning', "RFM $numero : PDF non genere (mPDF manquant ?). Entree creee, retentez plus tard.");
    }

    redirect('/portail/reporting/rapports.php');
}

function generate_dj(int $mois, int $annee, string $tri): void
{
    // Recupere les FRP clos du mois
    $orderBy = match($tri) {
        'ligne_budgetaire' => 'lb.code, i.date_depense',
        'type_contrat'     => 'c.type_contrat, i.date_depense',
        default            => 'i.date_depense',
    };

    $sql = "SELECT fr.id AS frp_id, i.id AS imputation_id, i.numero AS f01_numero,
                   i.date_depense, c.type_contrat, lb.code AS ligne_code,
                   d.total_net_a_verser, p.nom_complet AS prestataire
              FROM fiches_reglement fr
              JOIN imputations i  ON fr.imputation_id = i.id
              JOIN contrats c     ON i.contrat_id = c.id
              JOIN prestataires p ON c.prestataire_id = p.id
              JOIN lignes_budgetaires lb ON i.ligne_budgetaire_id = lb.id
              JOIN decaissements d ON d.imputation_id = i.id
             WHERE MONTH(i.date_depense) = ? AND YEAR(i.date_depense) = ?
               AND fr.date_cloture IS NOT NULL
             ORDER BY $orderBy";
    $stmt = db()->prepare($sql);
    $stmt->execute([$mois, $annee]);
    $dossiers = $stmt->fetchAll();

    if (count($dossiers) > 30) {
        flash_set('warning', 'Plus de 30 dossiers cloturees ce mois (' . count($dossiers) . '). Decoupez en plusieurs exports.');
        redirect('/portail/reporting/rapports.php');
    }

    $numero = 'DJ-ACP-' . $annee . '-M' . str_pad((string)$mois,2,'0',STR_PAD_LEFT);
    $stmt = db()->prepare(
        "INSERT INTO rapports_generes
            (numero, type_rapport, periode_debut, periode_fin, nb_dossiers, montant_total_htg,
             ordre_tri, statut, genere_par)
         VALUES (?, 'dj', ?, LAST_DAY(?), ?, ?, ?, 'en_cours', ?)"
    );
    $periodeDebut = sprintf('%04d-%02d-01', $annee, $mois);
    $totalMontant = array_sum(array_column($dossiers, 'total_net_a_verser'));
    $stmt->execute([$numero, $periodeDebut, $periodeDebut, count($dossiers), $totalMontant, $tri, (int)user_id()]);
    $rapId = (int)db()->lastInsertId();

    // Cree le ZIP
    $zipDir = __DIR__ . '/../storage/rapports/' . $annee . '/M' . str_pad((string)$mois,2,'0',STR_PAD_LEFT);
    if (!is_dir($zipDir)) @mkdir($zipDir, 0755, true);
    $zipPath = $zipDir . '/' . $numero . '.zip';

    try {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de creer le ZIP.');
        }

        // Index simple en texte
        $indexContent = "DOSSIER DE JUSTIFICATIFS - $numero\n";
        $indexContent .= "DEVDYNAMICS / Academie des Cartographes Populaires\n";
        $indexContent .= "Periode : " . mois_fr($mois) . " $annee\n";
        $indexContent .= "Nb dossiers : " . count($dossiers) . "\n";
        $indexContent .= "Montant total : " . format_htg($totalMontant) . "\n";
        $indexContent .= "Tri : $tri\n\n";

        foreach ($dossiers as $i => $d) {
            $indexContent .= sprintf("%02d. %s - %s - %s HTG\n",
                $i+1, $d['f01_numero'], $d['prestataire'], number_format((float)$d['total_net_a_verser'], 2));

            // Tente d'inclure le dossier_complet.pdf s'il existe
            $dossPath = sprintf('%s/../storage/dossiers/%d/M%02d/DEP-%03d/dossier_complet.pdf',
                __DIR__, $annee, $mois, (int)$d['imputation_id']);
            if (is_file($dossPath)) {
                $entry = sprintf('02_Dossiers_Depenses/DEP-%03d_%s/Dossier_Complet.pdf',
                    (int)$d['imputation_id'],
                    preg_replace('/[^a-zA-Z0-9_-]/', '_', $d['prestataire']));
                $zip->addFile($dossPath, $entry);
            }
        }

        $zip->addFromString('00_INDEX.txt', $indexContent);
        $zip->close();

        $stmt = db()->prepare("UPDATE rapports_generes SET statut='genere', fichier_zip=? WHERE id=?");
        $relPath = 'storage/rapports/' . $annee . '/M' . str_pad((string)$mois,2,'0',STR_PAD_LEFT) . '/' . $numero . '.zip';
        $stmt->execute([$relPath, $rapId]);
        audit_log('rapport_genere', "DJ $numero genere (" . count($dossiers) . " dossiers)", 'rapports_generes', $rapId);
        flash_set('success', "DJ $numero genere - " . count($dossiers) . " dossier(s).");
    } catch (Throwable $e) {
        $stmt = db()->prepare("UPDATE rapports_generes SET statut='erreur' WHERE id=?");
        $stmt->execute([$rapId]);
        error_log('DJ ZIP failed: ' . $e->getMessage());
        flash_set('danger', 'Erreur ZIP: ' . $e->getMessage());
    }

    redirect('/portail/reporting/rapports.php');
}

function generate_cumule(string $debut, string $fin): void
{
    // debut/fin format YYYY-MM
    if (!preg_match('/^\d{4}-\d{2}$/', $debut) || !preg_match('/^\d{4}-\d{2}$/', $fin)) {
        flash_set('danger', 'Plage invalide.');
        redirect('/portail/reporting/rapports.php');
    }
    $debutSql = $debut . '-01';
    $finSql   = date('Y-m-t', strtotime($fin . '-01'));

    $stmt = db()->prepare(
        "SELECT COUNT(*) AS nb, COALESCE(SUM(d.total_net_a_verser),0) AS total
           FROM decaissements d
           JOIN imputations i ON d.imputation_id = i.id
          WHERE i.date_depense BETWEEN ? AND ?"
    );
    $stmt->execute([$debutSql, $finSql]);
    $stats = $stmt->fetch();

    $numero = 'RC-ACP-' . $debut . '-a-' . $fin;
    $numero = str_replace('-', '', $numero); // simplifie
    $numero = substr($numero, 0, 25);

    $stmt = db()->prepare(
        "INSERT INTO rapports_generes
            (numero, type_rapport, periode_debut, periode_fin, nb_dossiers, montant_total_htg,
             ordre_tri, statut, genere_par)
         VALUES (?, 'cumule', ?, ?, ?, ?, 'chronologique', 'genere', ?)"
    );
    $stmt->execute([$numero, $debutSql, $finSql, (int)$stats['nb'], (float)$stats['total'], (int)user_id()]);
    audit_log('rapport_genere', "Rapport cumule $debut a $fin", 'rapports_generes', (int)db()->lastInsertId());
    flash_set('info', 'Entree creee. Le PDF Rapport Cumule sera genere quand mPDF sera disponible.');
    redirect('/portail/reporting/rapports.php');
}
