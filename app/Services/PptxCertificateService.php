<?php

namespace App\Services;

/**
 * PptxCertificateService
 *
 * Generates digitally-signed PDF certificates from PowerPoint (.pptx) templates.
 *
 * Workflow
 * ────────
 *   1. Copy the PPTX template to a temporary directory.
 *   2. Parse every slide XML:
 *        • Replace {{placeholder}} text with resolved values.
 *        • Locate the {{tte}} shape, record its EMU position, then remove it.
 *   3. Convert the modified PPTX to PNG using LibreOffice headless.
 *   4. Build a synthetic design descriptor:
 *        • The PNG becomes the background (all text already baked in).
 *        • A single 'qrcode' image element is positioned where {{tte}} was.
 *   5. Call PdfSigningService::generate() to produce a digitally-signed PDF.
 *   6. Clean up all temporary files.
 *
 * Requirements
 * ────────────
 *   libreoffice (headless) must be installed.
 *   In the Docker image (Alpine): apk add --no-cache libreoffice font-liberation ttf-freefont
 *   exec() must not be disabled in php.ini.
 *
 * Note on split text runs
 * ───────────────────────
 *   PowerPoint may store a single visible word across multiple <a:r> XML runs
 *   when different formatting is applied mid-word.  This service replaces
 *   placeholders using a direct string search on the raw XML; if a placeholder
 *   is split across runs it will remain unreplaced.  To avoid this, always
 *   type placeholders in the PPTX without changing font/style mid-word.
 */
class PptxCertificateService
{
    public function __construct(
        private readonly PdfSigningService $signingService
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate a signed PDF from a PPTX template.
     *
     * @param  string $templateStoragePath  Relative to storage/app/public/,
     *                                      e.g. "kegiatan/template_sertifikat/file.pptx"
     * @param  array  $placeholders         {{key}} → value map for text elements
     * @param  string $qrCodePath           Absolute path to the pre-generated QR code PNG
     * @param  string $outputPath           Absolute destination path for the signed PDF
     *
     * @throws \RuntimeException on missing template or conversion failure
     */
    public function generate(
        string $templateStoragePath,
        array  $placeholders,
        string $qrCodePath,
        string $outputPath
    ): void {
        // --- Validate + resolve source path ------------------------------------
        // If the path contains no directory separator it is a bare filename
        // (e.g. "template_sertifikat.pptx") → the file lives in public/.
        // Otherwise it is a user-uploaded file under storage/app/public/.
        $isBareFilename = ! str_contains($templateStoragePath, '/') &&
                          ! str_contains($templateStoragePath, DIRECTORY_SEPARATOR);

        if ($isBareFilename) {
            $absoluteSource = realpath(public_path($templateStoragePath));
            $allowedBase    = realpath(public_path());

            if ($absoluteSource === false || ! str_starts_with($absoluteSource, $allowedBase)) {
                throw new \RuntimeException("Template PPTX tidak ditemukan di public/: {$templateStoragePath}");
            }
        } else {
            $storagePub     = realpath(storage_path('app/public'));
            $absoluteSource = realpath(storage_path("app/public/{$templateStoragePath}"));

            if ($absoluteSource === false || ! str_starts_with($absoluteSource, $storagePub)) {
                throw new \RuntimeException("Template PPTX tidak ditemukan atau path tidak valid: {$templateStoragePath}");
            }
        }

        if (strtolower(pathinfo($absoluteSource, PATHINFO_EXTENSION)) !== 'pptx') {
            throw new \RuntimeException('File template harus berformat .pptx');
        }

        // --- Prepare temp workspace --------------------------------------------
        $tempId  = uniqid('cert_pptx_', true);
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempId;
        @mkdir($tempDir, 0755, true);

        // PNG is stored in public storage so PdfSigningService can read it
        $pngRelPath = "temp/{$tempId}.png";
        $pngAbsPath = storage_path("app/public/{$pngRelPath}");

        try {
            // --- Step 1: process PPTX (text replace + locate {{tte}}) ----------
            $modifiedPptx = $tempDir . DIRECTORY_SEPARATOR . 'certificate.pptx';
            $slideDim     = $this->getSlideDimensions($absoluteSource);
            $qrEmu        = $this->processPptx($absoluteSource, $placeholders, $modifiedPptx);

            // --- Step 2: PPTX → PNG via LibreOffice ----------------------------
            $this->convertPptxToPng($modifiedPptx, $tempDir);
            $generatedPng = $this->findFirstPng($tempDir);

            // --- Step 3: move PNG to public-accessible storage -----------------
            $pubTempDir = storage_path('app/public/temp');
            if (! is_dir($pubTempDir)) {
                mkdir($pubTempDir, 0755, true);
            }
            copy($generatedPng, $pngAbsPath);

            // --- Step 4: build synthetic design descriptor ---------------------
            [$pngW, $pngH] = getimagesize($pngAbsPath);

            $elements = [];
            if ($qrEmu !== null && $slideDim['cx'] > 0 && $slideDim['cy'] > 0) {
                $elements[] = [
                    'type'   => 'image',
                    'id'     => 'qrcode',
                    'x'      => ($qrEmu['x']  / $slideDim['cx']) * $pngW,
                    'y'      => ($qrEmu['y']  / $slideDim['cy']) * $pngH,
                    'width'  => ($qrEmu['cx'] / $slideDim['cx']) * $pngW,
                    'height' => ($qrEmu['cy'] / $slideDim['cy']) * $pngH,
                ];
            }

            $design = [
                'width'      => $pngW,
                'height'     => $pngH,
                'background' => $pngRelPath,
                'elements'   => $elements,
            ];

            // --- Step 5: render + digitally sign PDF ---------------------------
            // Pass empty placeholders: text is already baked into the PNG.
            $this->signingService->generate($design, [], $qrCodePath, $outputPath);

        } finally {
            $this->removeDirectory($tempDir);
            if (file_exists($pngAbsPath)) {
                unlink($pngAbsPath);
            }
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Copy the PPTX, process every slide: replace text placeholders and locate
     * (then remove) the {{tte}} shape.
     *
     * @return array|null  ['x','y','cx','cy'] in EMU, or null if {{tte}} not found
     */
    private function processPptx(string $sourcePath, array $placeholders, string $destPath): ?array
    {
        copy($sourcePath, $destPath);

        $zip = new \ZipArchive();
        if ($zip->open($destPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Gagal membuka PPTX: {$destPath}");
        }

        $qrPosition = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Only process slide XML files
            if (! preg_match('#^ppt/slides/slide\d+\.xml$#i', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);
            if ($xml === false) {
                continue;
            }

            // Search for {{tte}} only on slides not yet found
            if ($qrPosition === null) {
                $result = $this->extractTteShape($xml);
                if ($result !== null) {
                    $qrPosition = $result['position'];
                    $xml        = $result['xml'];
                }
            }

            $xml = $this->replaceTextInSlide($xml, $placeholders);

            $zip->addFromString($name, $xml);
        }

        $zip->close();

        return $qrPosition;
    }

    /**
     * Find the shape whose concatenated <a:t> text contains "{{tte}}".
     * Returns its EMU transform and the slide XML with that shape removed.
     * Returns null if no such shape exists.
     *
     * Uses strpos-based scanning instead of dotall regex on the full XML to
     * avoid catastrophic backtracking on large slide files.
     */
    private function extractTteShape(string $xml): ?array
    {
        $cursor = 0;

        while (($shapeOpen = strpos($xml, '<p:sp', $cursor)) !== false) {
            // Find the end of the opening tag
            $gt = strpos($xml, '>', $shapeOpen);
            if ($gt === false) break;

            // Self-closing <p:sp/> — skip
            if ($xml[$gt - 1] === '/') {
                $cursor = $gt + 1;
                continue;
            }

            // Find </p:sp>
            $shapeClose = strpos($xml, '</p:sp>', $gt);
            if ($shapeClose === false) break;

            $endPos   = $shapeClose + 7; // strlen('</p:sp>')
            $shapeXml = substr($xml, $shapeOpen, $endPos - $shapeOpen);

            // Concatenate all <a:t> text values inside this shape
            $text = '';
            $tCursor = 0;
            while (($tOpen = strpos($shapeXml, '<a:t', $tCursor)) !== false) {
                $tGt = strpos($shapeXml, '>', $tOpen);
                if ($tGt === false) break;
                // Skip self-closing <a:t/>
                if ($shapeXml[$tGt - 1] === '/') { $tCursor = $tGt + 1; continue; }
                $tClose = strpos($shapeXml, '</a:t>', $tGt);
                if ($tClose === false) break;
                $text   .= substr($shapeXml, $tGt + 1, $tClose - $tGt - 1);
                $tCursor = $tClose + 6;
            }

            if (! str_contains($text, '{{tte}}')) {
                $cursor = $endPos;
                continue;
            }

            // Extract EMU position/size from <a:xfrm><a:off .../><a:ext .../>
            $position = null;
            if (
                preg_match('/<a:off[^>]+x="(-?\d+)"[^>]+y="(-?\d+)"/', $shapeXml, $off) &&
                preg_match('/<a:ext[^>]+cx="(\d+)"[^>]+cy="(\d+)"/', $shapeXml, $ext)
            ) {
                $position = [
                    'x'  => (float) $off[1],
                    'y'  => (float) $off[2],
                    'cx' => (float) $ext[1],
                    'cy' => (float) $ext[2],
                ];
            }

            // Remove the shape from the slide XML
            return [
                'position' => $position,
                'xml'      => substr($xml, 0, $shapeOpen) . substr($xml, $endPos),
            ];
        }

        return null;
    }

    /**
     * Replace {{placeholder}} markers in the raw slide XML.
     * Replacement values are XML-escaped to prevent malformed output.
     */
    /**
     * Replace {{placeholder}} markers in slide XML.
     *
     * TWO-PASS STRATEGY
     * ─────────────────
     * Pass 1 — simple str_replace on the raw XML.
     *   Works when a placeholder is stored entirely inside a single <a:t> node
     *   (the common case when the template was typed without mid-word format
     *   changes).
     *
     * Pass 2 — paragraph-level run merging (split-run case).
     *   PowerPoint sometimes stores one visible word across several <a:r> runs
     *   when formatting changes mid-word.  For any placeholder still present
     *   after pass 1 we walk the XML paragraph by paragraph using strpos
     *   (NOT dotall regex) and merge runs whose concatenated text contains the
     *   placeholder, then substitute the value into the merged run.
     *
     *   Using strpos avoids the catastrophic PCRE backtracking that a dotall
     *   regex (e.g. /<a:p\b.*?<\/a:p>/s) can trigger on large slide XML,
     *   which would make preg_replace_callback return null and wipe all text.
     */
    private function replaceTextInSlide(string $xml, array $placeholders): string
    {
        // Pass 1: simple replacement — handles single-run placeholders
        foreach ($placeholders as $key => $value) {
            $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml = str_replace($key, $safeValue, $xml);
        }

        // Pass 2: handle split-run placeholders that were not resolved above.
        // For split-run placeholders the key does NOT appear literally in the
        // raw XML (each <a:r> stores only a fragment), so str_contains on the
        // full XML cannot detect them.  Always forward every placeholder to
        // replaceInParagraph; that method performs a cheap early-exit when the
        // merged run text does not contain any placeholder.
        if (empty($placeholders)) {
            return $xml;
        }

        // Walk paragraph by paragraph with strpos — no dotall regex on full doc
        $out    = '';
        $cursor = 0;

        while (($pOpen = strpos($xml, '<a:p', $cursor)) !== false) {
            $out .= substr($xml, $cursor, $pOpen - $cursor);

            $gt = strpos($xml, '>', $pOpen);
            if ($gt === false) {
                $out .= substr($xml, $pOpen);
                $cursor = strlen($xml);
                break;
            }

            // Self-closing <a:p .../>
            if ($xml[$gt - 1] === '/') {
                $out   .= substr($xml, $pOpen, $gt - $pOpen + 1);
                $cursor = $gt + 1;
                continue;
            }

            $pClose = strpos($xml, '</a:p>', $gt);
            if ($pClose === false) {
                $out .= substr($xml, $pOpen);
                $cursor = strlen($xml);
                break;
            }

            $endPos  = $pClose + 6;
            $paraXml = substr($xml, $pOpen, $endPos - $pOpen);

            $out   .= $this->replaceInParagraph($paraXml, $placeholders);
            $cursor = $endPos;
        }

        $out .= substr($xml, $cursor);

        return $out;
    }

    /**
     * Merge all <a:r> runs in a paragraph and substitute placeholders.
     *
     * Uses strpos-based scanning throughout (no dotall regex) so that nested
     * self-closing elements inside <a:rPr> (e.g. <a:solidFill><a:srgbClr/>)
     * never cause the run-properties extraction to stop prematurely and produce
     * malformed XML — which was the root cause of "text disappears" reports.
     */
    private function replaceInParagraph(string $paraXml, array $placeholders): string
    {
        // --- Collect all <a:r> runs using strpos ----------------------------
        $runs   = [];
        $cursor = 0;

        while (($rOpen = strpos($paraXml, '<a:r', $cursor)) !== false) {
            // Distinguish <a:r> / <a:r<space> from <a:rPr>, <a:rSld>, etc.
            $ch = $paraXml[$rOpen + 4] ?? '';
            if ($ch !== '>' && $ch !== ' ' && $ch !== "\t" && $ch !== "\r" && $ch !== "\n") {
                $cursor = $rOpen + 1;
                continue;
            }

            $gt = strpos($paraXml, '>', $rOpen);
            if ($gt === false) break;

            // Self-closing <a:r/> carries no text — skip
            if ($paraXml[$gt - 1] === '/') {
                $cursor = $gt + 1;
                continue;
            }

            $rClose = strpos($paraXml, '</a:r>', $gt);
            if ($rClose === false) break;

            $runEnd = $rClose + 6; // strlen('</a:r>') === 6
            $runXml = substr($paraXml, $rOpen, $runEnd - $rOpen);

            // Extract text content from <a:t> inside this run
            $text    = '';
            $tCursor = 0;
            while (($tOpen = strpos($runXml, '<a:t', $tCursor)) !== false) {
                $tCh = $runXml[$tOpen + 4] ?? '';
                if ($tCh !== '>' && $tCh !== ' ' && $tCh !== "\t") {
                    $tCursor = $tOpen + 1;
                    continue;
                }
                $tGt = strpos($runXml, '>', $tOpen);
                if ($tGt === false) break;
                if ($runXml[$tGt - 1] === '/') { $tCursor = $tGt + 1; continue; }
                $tClose = strpos($runXml, '</a:t>', $tGt);
                if ($tClose === false) break;
                $text   .= html_entity_decode(
                    substr($runXml, $tGt + 1, $tClose - $tGt - 1),
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8'
                );
                $tCursor = $tClose + 6;
            }

            $runs[] = ['start' => $rOpen, 'end' => $runEnd, 'xml' => $runXml, 'text' => $text];
            $cursor = $runEnd;
        }

        if (empty($runs)) {
            return $paraXml;
        }

        // --- Check whether any placeholder is present in merged text --------
        $combinedText = implode('', array_column($runs, 'text'));

        $needsReplacement = false;
        foreach (array_keys($placeholders) as $key) {
            if (str_contains($combinedText, $key)) {
                $needsReplacement = true;
                break;
            }
        }

        if (! $needsReplacement) {
            return $paraXml;
        }

        // --- Substitute placeholders ----------------------------------------
        $replacedText = $combinedText;
        foreach ($placeholders as $key => $value) {
            $replacedText = str_replace(
                $key,
                htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                $replacedText
            );
        }

        // --- Extract <a:rPr> from the first run with strpos -----------------
        // Using a regex like /<a:rPr\b.*?(?:\/>|<\/a:rPr>)/s stops at the
        // FIRST '/>' it encounters, which may be inside a nested child element
        // such as <a:solidFill><a:srgbClr val="FF0000"/>.  That truncates the
        // rPr, producing malformed XML that LibreOffice renders as blank text.
        // strpos correctly finds the MATCHING closing tag instead.
        $rprXml   = '';
        $firstXml = $runs[0]['xml'];
        $rprStart = strpos($firstXml, '<a:rPr');
        if ($rprStart !== false) {
            $rprGt = strpos($firstXml, '>', $rprStart);
            if ($rprGt !== false) {
                if ($firstXml[$rprGt - 1] === '/') {
                    // Self-closing: <a:rPr ... />
                    $rprXml = substr($firstXml, $rprStart, $rprGt - $rprStart + 1);
                } else {
                    // Block element: <a:rPr ...>...</a:rPr>
                    $rprClose = strpos($firstXml, '</a:rPr>', $rprGt);
                    if ($rprClose !== false) {
                        $rprXml = substr($firstXml, $rprStart, $rprClose - $rprStart + 8);
                    }
                }
            }
        }

        // --- Build merged run -----------------------------------------------
        $mergedRun = '<a:r>' . $rprXml . '<a:t>' . $replacedText . '</a:t></a:r>';

        // Remove all original runs from the paragraph using recorded positions
        $newPara = '';
        $pCursor = 0;
        foreach ($runs as $run) {
            $newPara .= substr($paraXml, $pCursor, $run['start'] - $pCursor);
            $pCursor  = $run['end'];
        }
        $newPara .= substr($paraXml, $pCursor);

        // Insert merged run immediately before </a:p> (use strrpos for safety)
        $closePos = strrpos($newPara, '</a:p>');
        if ($closePos === false) {
            return $paraXml; // malformed paragraph — return original unchanged
        }

        return substr($newPara, 0, $closePos) . $mergedRun . '</a:p>';
    }

    /**
     * Read slide dimensions (cx, cy) from ppt/presentation.xml in EMU units.
     *
     * @return array{cx: float, cy: float}
     */
    private function getSlideDimensions(string $pptxPath): array
    {
        // Standard 10 × 7.5 inch in EMU
        $default = ['cx' => 9144000.0, 'cy' => 6858000.0];

        $zip = new \ZipArchive();
        if ($zip->open($pptxPath) !== true) {
            return $default;
        }

        $xml = $zip->getFromName('ppt/presentation.xml');
        $zip->close();

        if ($xml === false) {
            return $default;
        }

        // <p:sldSz cx="..." cy="..."/>
        if (preg_match('/<p:sldSz[^>]+cx="(\d+)"[^>]+cy="(\d+)"/', $xml, $m)) {
            return ['cx' => (float) $m[1], 'cy' => (float) $m[2]];
        }

        return $default;
    }

    /**
     * Convert a PPTX file to PNG in the given output directory.
     *
     * Strategy (in order):
     *   1. LibreOffice headless  — works on Linux (Docker) and Windows if installed.
     *   2. COM / Microsoft PowerPoint — Windows-only fallback when Office is installed
     *      and the PHP com_dotnet extension is enabled.
     */
    private function convertPptxToPng(string $pptxPath, string $outputDir): void
    {
        // --- Try LibreOffice ---------------------------------------------------
        $binary = $this->detectLibreOfficeBinary();

        if ($binary !== null) {
            // Wrap binary in double-quotes to handle paths with spaces (e.g. C:\Program Files\...)
            $quotedBinary = '"' . $binary . '"';

            if (PHP_OS_FAMILY !== 'Windows') {
                // HOME=/tmp prevents "no home directory" errors when running as www-data
                $cmd = sprintf(
                    'HOME=/tmp %s --headless --convert-to png --outdir %s %s 2>&1',
                    $quotedBinary,
                    escapeshellarg($outputDir),
                    escapeshellarg($pptxPath)
                );
            } else {
                $cmd = sprintf(
                    '%s --headless --convert-to png --outdir %s %s 2>&1',
                    $quotedBinary,
                    escapeshellarg($outputDir),
                    escapeshellarg($pptxPath)
                );
            }

            exec($cmd, $output, $exitCode);

            if ($exitCode === 0) {
                return;
            }

            // LibreOffice found but failed — surface the error directly
            throw new \RuntimeException(
                "LibreOffice gagal mengkonversi PPTX ke PNG (exit {$exitCode}):\n" .
                implode("\n", $output)
            );
        }

        // --- Try COM / Microsoft PowerPoint (Windows only) ------------------
        if (PHP_OS_FAMILY === 'Windows' && extension_loaded('com_dotnet')) {
            $this->convertPptxToPngViaCom($pptxPath, $outputDir);
            return;
        }

        // --- Nothing available -----------------------------------------------
        throw new \RuntimeException(
            "Tidak ada converter PPTX yang tersedia. Pilih salah satu:\n" .
            "  1. Install LibreOffice: https://www.libreoffice.org/download/download/\n" .
            "     Setelah install, restart terminal/server.\n" .
            "  2. Pastikan Microsoft PowerPoint terinstall dan ekstensi PHP \"com_dotnet\" aktif\n" .
            "     (uncomment extension=com_dotnet di php.ini, lalu restart PHP)."
        );
    }

    /**
     * Convert a PPTX to PNG using Microsoft PowerPoint via COM automation.
     *
     * Requirements:
     *   - Windows OS
     *   - Microsoft PowerPoint installed
     *   - PHP extension com_dotnet enabled in php.ini
     *
     * The first slide is exported as a PNG into $outputDir.
     */
    private function convertPptxToPngViaCom(string $pptxPath, string $outputDir): void
    {
        try {
            $pptApp = new \COM('PowerPoint.Application');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'COM: Microsoft PowerPoint tidak tersedia. ' . $e->getMessage() .
                "\nInstall LibreOffice atau aktifkan PowerPoint dan com_dotnet di php.ini."
            );
        }

        try {
            $pptApp->Visible = false;

            // Open read-only, untitled window hidden
            $presentation = $pptApp->Presentations->Open(
                str_replace('/', '\\', $pptxPath),
                true,   // ReadOnly
                true,   // Untitled (no window title bar)
                false   // WithWindow
            );

            // Export every slide; only the first slide file is used downstream
            foreach (range(1, $presentation->Slides->Count) as $i) {
                $outFile = $outputDir . DIRECTORY_SEPARATOR . 'certificate' . ($i > 1 ? "_slide{$i}" : '') . '.png';
                $presentation->Slides->Item($i)->Export(
                    str_replace('/', '\\', $outFile),
                    'PNG'
                );
            }

            $presentation->Close();
        } finally {
            $pptApp->Quit();
            unset($pptApp);
        }
    }

    /**
     * Return the absolute path of the first PNG file found in a directory.
     *
     * @throws \RuntimeException if no PNG was produced
     */
    private function findFirstPng(string $dir): string
    {
        $pngs = glob($dir . DIRECTORY_SEPARATOR . '*.png');

        if (empty($pngs)) {
            throw new \RuntimeException("LibreOffice tidak menghasilkan PNG di: {$dir}");
        }

        sort($pngs);

        return $pngs[0];
    }

    /**
     * Locate the LibreOffice/soffice binary from well-known paths or PATH.
     *
     * Returns null (instead of throwing) so callers can fall back to other converters.
     */
    private function detectLibreOfficeBinary(): ?string
    {
        $candidates = [
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
            '/usr/local/bin/libreoffice',
            '/usr/local/bin/soffice',
            '/usr/lib/libreoffice/program/soffice',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                // Return the raw path — caller wraps it in double-quotes for the shell command
                return $path;
            }
        }

        // Fall back to PATH lookup
        $lookupCmd = PHP_OS_FAMILY === 'Windows'
            ? 'where soffice 2>NUL'
            : 'which libreoffice 2>/dev/null || which soffice 2>/dev/null';

        exec($lookupCmd, $out);

        foreach ($out as $line) {
            $line = trim($line);
            if ($line !== '' && file_exists($line)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path), ['.', '..']) as $item) {
            $full = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}
