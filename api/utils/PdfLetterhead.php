<?php
/**
 * En-tete DevDynamics des documents PDF : logo a gauche, coordonnees collees a
 * la marge droite, puis le titre du document.
 */

const PDF_LETTERHEAD_CONTACT = [
    '190, rue 15-16 B, Cap-Haïtien, Haïti',
    '+509 47 41 8737',
    'contact@dev-dynamics.org',
];

function pdf_letterhead($pdf, $title) {
    $top = $pdf->cursorY;
    $pdf->setFont(9);
    $right = $pdf->pageWidth - $pdf->marginRight;
    foreach (PDF_LETTERHEAD_CONTACT as $n => $line) {
        $pdf->textRight($line, $right, $top - 9 - $n * 13);
    }
    $pdf->moveDown(8);
    $pdf->image(__DIR__ . '/../../assets/images/logo_6_pdf.jpg', 140);
    $pdf->cursorY = $top - 52;
    $pdf->hr();
    $pdf->moveDown(10);
    $pdf->setFont(14, true);
    $pdf->paragraph($title);
    $pdf->moveDown(8);
}
