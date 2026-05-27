<?php
declare(strict_types=1);

/**
 * Genere a la volee un PDF pour une entite donnee.
 *
 * Usage :
 *   /portail/pdf/render.php?type=f01&id=42
 *   /portail/pdf/render.php?type=f02&id=42
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/generate.php';

check_role(['administrateur', 'coordinateur', 'comptable']);

$type = (string)($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('400 - id manquant');
}

$service = new PdfService();
$pdfPath = null;
$filename = null;

switch ($type) {
    case 'f01':
        require_once __DIR__ . '/../models/ImputationModel.php';
        $imputation = ImputationModel::find($id);
        if (!$imputation) { http_response_code(404); exit('404 - F01 introuvable'); }

        $rel = sprintf('storage/dossiers/%s/M%02d/DEP-%03d/f01.pdf',
            date('Y', strtotime($imputation['date_depense'])),
            (int)date('m', strtotime($imputation['date_depense'])),
            (int)$imputation['id']
        );
        $pdfPath = $service->generate('f01', ['imputation' => $imputation], $rel);
        $filename = $imputation['numero'] . '.pdf';
        break;

    case 'f02':
        require_once __DIR__ . '/../models/DecaissementModel.php';
        $f02 = DecaissementModel::find($id);
        if (!$f02) { http_response_code(404); exit('404 - F02 introuvable'); }

        // Recupere la signature admin (chemin absolu pour mPDF)
        $stmt = db()->prepare('SELECT signature_image FROM users WHERE id=?');
        $stmt->execute([(int)$f02['valide_par']]);
        $sigRel = $stmt->fetchColumn();
        $sigAbs = $sigRel ? storage_absolute_path($sigRel) : null;
        if ($sigAbs && !is_file($sigAbs)) $sigAbs = null;

        $rel = sprintf('storage/dossiers/%s/M%02d/DEP-%03d/f02.pdf',
            date('Y', strtotime($f02['date_depense'])),
            (int)date('m', strtotime($f02['date_depense'])),
            (int)$f02['imputation_id']
        );
        $pdfPath = $service->generate('f02', [
            'f02' => $f02,
            'sig_admin_abs' => $sigAbs,
        ], $rel);
        $filename = $f02['numero'] . '.pdf';
        break;

    case 'asf':
        require_once __DIR__ . '/../models/AsfModel.php';
        $asf = AsfModel::find($id);
        if (!$asf) { http_response_code(404); exit('404'); }
        $stmt = db()->prepare('SELECT signature_image FROM users WHERE id=?');
        $stmt->execute([(int)$asf['certifie_par']]);
        $sigRel = $stmt->fetchColumn();
        $sigAbs = $sigRel ? storage_absolute_path($sigRel) : null;
        if ($sigAbs && !is_file($sigAbs)) $sigAbs = null;

        $rel = sprintf('storage/dossiers/%s/M%02d/DEP-%03d/asf.pdf',
            date('Y'), (int)date('m'), (int)$asf['imputation_id']);
        $pdfPath = $service->generate('asf', ['asf' => $asf, 'sig_coord_abs' => $sigAbs], $rel);
        $filename = $asf['numero'] . '.pdf';
        break;

    case 'nh':
        require_once __DIR__ . '/../models/NoteHonoraireModel.php';
        $nh = NoteHonoraireModel::find($id);
        if (!$nh) { http_response_code(404); exit('404'); }
        $sigAbs = $nh['sig_presta_scan'] ? storage_absolute_path($nh['sig_presta_scan']) : null;
        if ($sigAbs && !is_file($sigAbs)) $sigAbs = null;
        $rel = sprintf('storage/dossiers/%s/M%02d/DEP-%03d/nh.pdf',
            date('Y'), (int)date('m'), (int)$nh['imputation_id']);
        $pdfPath = $service->generate('nh', ['nh' => $nh, 'sig_presta_abs' => $sigAbs], $rel);
        $filename = $nh['numero'] . '.pdf';
        break;

    case 'dossier':
        // id = FRP id
        require_once __DIR__ . '/../models/AsfModel.php';
        require_once __DIR__ . '/../models/NoteHonoraireModel.php';
        require_once __DIR__ . '/../models/FicheReglementModel.php';

        $frp = FicheReglementModel::find($id);
        if (!$frp) { http_response_code(404); exit('404 - FRP introuvable'); }

        $imputation = ImputationModel::find((int)$frp['imputation_id']);
        $f02 = DecaissementModel::findByImputation((int)$frp['imputation_id']);
        $asf = AsfModel::findByImputation((int)$frp['imputation_id']);
        $nh  = NoteHonoraireModel::findByImputation((int)$frp['imputation_id']);

        // Signatures
        $sigAdminAbs = null;
        if ($f02 && $f02['sig_admin_scan']) {
            $p = storage_absolute_path($f02['sig_admin_scan']);
            if (is_file($p)) $sigAdminAbs = $p;
        }
        $sigCoordAbs = null;
        if ($asf && $asf['sig_coord_scan']) {
            $p = storage_absolute_path($asf['sig_coord_scan']);
            if (is_file($p)) $sigCoordAbs = $p;
        }
        $sigPrestaNhAbs = null;
        if ($nh && $nh['sig_presta_scan']) {
            $p = storage_absolute_path($nh['sig_presta_scan']);
            if (is_file($p)) $sigPrestaNhAbs = $p;
        }
        // Sig presta FRP : on cherche dans tokens
        $stmt = db()->prepare(
            "SELECT sig_presta_scan FROM tokens
              WHERE imputation_id=? AND type='signature_frp' AND sig_presta_scan IS NOT NULL
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([(int)$frp['imputation_id']]);
        $sigPrestaFrpRel = $stmt->fetchColumn();
        $sigPrestaFrpAbs = $sigPrestaFrpRel ? storage_absolute_path($sigPrestaFrpRel) : null;
        if ($sigPrestaFrpAbs && !is_file($sigPrestaFrpAbs)) $sigPrestaFrpAbs = null;

        // Merge frp <-> f02 fields (frp::find inclut deja les champs F02)
        // mais imputation est independante - on assure que les champs FRP-style sont la
        $f02 = array_merge($f02 ?? [], [
            'is_cps01' => $imputation['is_cps01'] ?? 0,
        ]);

        $rel = sprintf('storage/dossiers/%s/M%02d/DEP-%03d/dossier_complet.pdf',
            date('Y', strtotime($imputation['date_depense'])),
            (int)date('m', strtotime($imputation['date_depense'])),
            (int)$imputation['id']);

        $pdfPath = $service->generate('dossier_complet', [
            'imputation'         => $imputation,
            'f02'                => $f02,
            'asf'                => $asf,
            'nh'                 => $nh,
            'frp'                => $frp,
            'sig_admin_abs'      => $sigAdminAbs,
            'sig_coord_abs'      => $sigCoordAbs,
            'sig_presta_nh_abs'  => $sigPrestaNhAbs,
            'sig_presta_frp_abs' => $sigPrestaFrpAbs,
        ], $rel);

        $filename = 'Dossier_' . $imputation['numero'] . '.pdf';
        break;

    default:
        http_response_code(400);
        exit('400 - type inconnu');
}

if (!$pdfPath || !is_file($pdfPath)) {
    http_response_code(500);
    exit('500 - Echec generation PDF (mPDF library manquante ?)');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($pdfPath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, no-cache');
readfile($pdfPath);
exit;
