<?php
/**
 * SimplePDF — minimal PDF writer in pure PHP (no dependency).
 * Supports Helvetica / Helvetica-Bold, multi-page, basic text wrapping,
 * and horizontal lines. Intended for short structured documents.
 *
 * Coordinates are PDF-native (origin = bottom-left of page, units = points).
 * The class also exposes a top-down "cursor" API (cursorY decreases downward).
 */

class SimplePDF {
    private $pages = [];
    private $currentPageIdx = -1;

    private $fontSize = 11;
    private $isBold = false;
    private $lineHeight = 14;

    public $pageWidth = 595;   // A4 width  (pt)
    public $pageHeight = 842;  // A4 height (pt)
    public $marginTop = 50;
    public $marginLeft = 50;
    public $marginRight = 50;
    public $marginBottom = 50;

    public $cursorY = 0;

    public function __construct() {
        $this->addPage();
    }

    public function addPage() {
        $this->pages[] = ['stream' => ''];
        $this->currentPageIdx = count($this->pages) - 1;
        $this->cursorY = $this->pageHeight - $this->marginTop;
    }

    public function setFont($size, $bold = false) {
        $this->fontSize = $size;
        $this->isBold = $bold;
        $this->lineHeight = (int) ceil($size * 1.35);
    }

    public function setLineHeight($lh) {
        $this->lineHeight = (int) $lh;
    }

    public function moveDown($pts) {
        $this->cursorY -= $pts;
    }

    /**
     * Draw a single line of text at the current cursor, then advance one line.
     * Auto-paginates when the cursor passes the bottom margin.
     */
    public function text($str, $x = null) {
        if ($x === null) $x = $this->marginLeft;
        $this->ensureSpace();
        $font = $this->isBold ? 'F2' : 'F1';
        $escaped = $this->escape($str);
        $y = $this->cursorY;
        $this->appendStream("BT /{$font} {$this->fontSize} Tf {$x} {$y} Td ({$escaped}) Tj ET\n");
        $this->cursorY -= $this->lineHeight;
    }

    /**
     * Draw a heading: bold, larger size for one line, then restore.
     */
    public function heading($str, $size = 13) {
        $prevSize = $this->fontSize;
        $prevBold = $this->isBold;
        $this->setFont($size, true);
        $this->text($str);
        $this->setFont($prevSize, $prevBold);
    }

    /**
     * Draw a label/value pair on the same line: bold label, normal value.
     */
    public function labelValue($label, $value, $labelWidth = 180) {
        $this->ensureSpace();
        $font = 'F2';
        $y = $this->cursorY;
        $x = $this->marginLeft;
        $escapedLabel = $this->escape($label);
        $this->appendStream("BT /{$font} {$this->fontSize} Tf {$x} {$y} Td ({$escapedLabel}) Tj ET\n");

        $valX = $x + $labelWidth;
        $valWidth = $this->pageWidth - $this->marginRight - $valX;
        $maxChars = max(20, (int) floor($valWidth / ($this->fontSize * 0.5)));

        $value = (string) ($value === null || $value === '' ? '—' : $value);
        $lines = $this->wrapText($value, $maxChars);

        $first = true;
        foreach ($lines as $line) {
            if (!$first) {
                $this->cursorY -= $this->lineHeight;
                $this->ensureSpace();
                $y = $this->cursorY;
            }
            $escaped = $this->escape($line);
            $this->appendStream("BT /F1 {$this->fontSize} Tf {$valX} {$y} Td ({$escaped}) Tj ET\n");
            $first = false;
        }
        $this->cursorY -= $this->lineHeight;
    }

    /**
     * Multi-line text block (for long paragraphs).
     */
    public function paragraph($str) {
        $maxChars = $this->approxCharsPerLine();
        $lines = $this->wrapText((string) $str, $maxChars);
        foreach ($lines as $line) {
            $this->text($line);
        }
    }

    public function hr() {
        $y = $this->cursorY + $this->lineHeight - 4;
        $x1 = $this->marginLeft;
        $x2 = $this->pageWidth - $this->marginRight;
        $this->appendStream("0.7 g {$x1} {$y} m {$x2} {$y} l S 0 g\n");
    }

    /**
     * Output the PDF as a binary string.
     */
    public function output() {
        $body = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [0];
        $offset = strlen($body);

        // 1: Catalog
        $offsets[1] = $offset;
        $obj = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $body .= $obj;
        $offset += strlen($obj);

        // 2: Pages (will reference page object IDs starting at 5)
        $pageCount = count($this->pages);
        $kidIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kidIds[] = (5 + $i * 2) . ' 0 R';
        }
        $offsets[2] = $offset;
        $obj = "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $kidIds) . "] /Count {$pageCount} >>\nendobj\n";
        $body .= $obj;
        $offset += strlen($obj);

        // 3: F1 Helvetica
        $offsets[3] = $offset;
        $obj = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $body .= $obj;
        $offset += strlen($obj);

        // 4: F2 Helvetica-Bold
        $offsets[4] = $offset;
        $obj = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";
        $body .= $obj;
        $offset += strlen($obj);

        // Page objects + their content streams
        for ($i = 0; $i < $pageCount; $i++) {
            $pageId = 5 + $i * 2;
            $contentId = $pageId + 1;

            $offsets[$pageId] = $offset;
            $obj = "{$pageId} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>\nendobj\n";
            $body .= $obj;
            $offset += strlen($obj);

            $stream = $this->pages[$i]['stream'];
            $len = strlen($stream);
            $offsets[$contentId] = $offset;
            $obj = "{$contentId} 0 obj\n<< /Length {$len} >>\nstream\n{$stream}endstream\nendobj\n";
            $body .= $obj;
            $offset += strlen($obj);
        }

        $totalObjects = 4 + $pageCount * 2;

        // xref table
        $xrefOffset = $offset;
        $xref = "xref\n0 " . ($totalObjects + 1) . "\n";
        $xref .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $totalObjects; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $body .= $xref;

        // trailer
        $body .= "trailer\n<< /Size " . ($totalObjects + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $body;
    }

    public function save($path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return file_put_contents($path, $this->output()) !== false;
    }

    // ------- internals -------

    private function appendStream($str) {
        $this->pages[$this->currentPageIdx]['stream'] .= $str;
    }

    private function ensureSpace() {
        if ($this->cursorY < $this->marginBottom) {
            $this->addPage();
        }
    }

    private function approxCharsPerLine() {
        $usable = $this->pageWidth - $this->marginLeft - $this->marginRight;
        return max(20, (int) floor($usable / ($this->fontSize * 0.5)));
    }

    private function wrapText($str, $maxChars) {
        $paragraphs = preg_split("/\r\n|\n|\r/", (string) $str);
        $output = [];
        foreach ($paragraphs as $para) {
            if ($para === '') { $output[] = ''; continue; }
            if (strlen($para) <= $maxChars) { $output[] = $para; continue; }

            $words = preg_split('/\s+/', $para);
            $line = '';
            foreach ($words as $word) {
                if ($line === '') {
                    $line = $word;
                } elseif (strlen($line) + 1 + strlen($word) <= $maxChars) {
                    $line .= ' ' . $word;
                } else {
                    $output[] = $line;
                    $line = $word;
                }
            }
            if ($line !== '') $output[] = $line;
        }
        return $output;
    }

    private function escape($str) {
        $str = (string) $str;
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($str, 'Windows-1252', 'UTF-8');
            if ($converted !== false) $str = $converted;
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $str);
            if ($converted !== false) $str = $converted;
        }
        $str = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $str);
        return $str;
    }
}
