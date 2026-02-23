<?php

namespace App\Services;

use TCPDF;

/**
 * PdfSigningService
 *
 * Renders certificate designs (stored as JSON) to PDF using TCPDF and embeds
 * an OpenSSL SHA-256 digital signature (PKCS#7 / CAdES-B).
 *
 * WHY SIGNATURE !== HASH
 * ──────────────────────
 * The old approach stored a SHA-256 hash of the server-side file and compared
 * it on every verify request.  That worked for the server copy but could NOT
 * detect tampering in the copy the user downloaded.
 *
 * A PDF digital signature (ISO 32000-1, §12.8) works differently:
 *   1. TCPDF reserves a byte-range inside the PDF for the signature value.
 *   2. It hashes ALL other bytes of the document with SHA-256.
 *   3. It encrypts that hash with the private RSA key and stores the result.
 *
 * When a PDF viewer opens the file it:
 *   a) Re-hashes the exact same byte ranges with SHA-256.
 *   b) Decrypts the stored value with the embedded public certificate.
 *   c) Compares – a single bit changed anywhere ⇒ "Signature INVALID".
 *
 * Even the tiniest text edit, metadata tweak, or page insertion breaks the
 * signature because it alters the byte ranges that were hashed.
 *
 * Dependencies
 * ────────────
 *   composer require tecnickcom/tcpdf
 *   PHP ext-openssl  (bundled with most PHP distributions)
 *
 * Key generation
 * ──────────────
 *   php artisan certificate:generate-keys
 */
class PdfSigningService
{
    /**
     * Design-canvas dots-per-inch assumption.
     * The frontend editor outputs pixel coordinates at this DPI.
     * Adjust to 96 if the design canvas uses screen-pixel dimensions.
     */
    private const DESIGN_DPI = 300;

    /**
     * A4 landscape page size in PDF points (1 pt = 1/72 inch).
     * 297 mm × 210 mm  →  841.89 pt × 595.28 pt.
     * All generated certificates are forced to this size regardless of the
     * design canvas dimensions.
     */
    private const A4_W_PT = 841.89;
    private const A4_H_PT = 595.28;

    // -------------------------------------------------------------------------
    // Configuration (populated from config/certificate.php via constructor)
    // -------------------------------------------------------------------------

    private string $privateKeyPath;
    private string $certificatePath;
    private string $privateKeyPassword;
    private string $signerName;
    private string $location;
    private string $reason;
    private string $contactInfo;
    private bool   $signatureVisible;
    private float  $sigX;
    private float  $sigY;
    private float  $sigW;
    private float  $sigH;

    public function __construct()
    {
        $this->privateKeyPath     = config('certificate.private_key_path');
        $this->certificatePath    = config('certificate.certificate_path');
        $this->privateKeyPassword = config('certificate.private_key_password', '');
        $this->signerName         = config('certificate.signer_name', config('app.name'));
        $this->location           = config('certificate.location', 'Indonesia');
        $this->reason             = config('certificate.reason', 'Sertifikat resmi');
        $this->contactInfo        = config('certificate.contact_info', config('app.url'));
        $this->signatureVisible   = (bool) config('certificate.signature_visible', true);
        $this->sigX               = (float) config('certificate.signature_x', 20);
        $this->sigY               = (float) config('certificate.signature_y', 20);
        $this->sigW               = (float) config('certificate.signature_width', 200);
        $this->sigH               = (float) config('certificate.signature_height', 60);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate a digitally-signed PDF from a certificate design.
     *
     * @param  array   $design       JSON design object (width, height, background, elements[])
     * @param  array   $placeholders Key-value map of {{placeholder}} → replacement text
     * @param  string  $qrCodePath   Absolute path to the pre-generated QR code PNG
     * @param  string  $outputPath   Absolute path where the signed PDF will be saved
     * @return void
     *
     * @throws \RuntimeException if signing keys are missing or PDF cannot be saved
     */
    public function generate(
        array  $design,
        array  $placeholders,
        string $qrCodePath,
        string $outputPath
    ): void {
        // --- Validate signing keys -----------------------------------------------
        $this->ensureSigningKeysExist();

        // --- Dimensions ----------------------------------------------------------
        $designW = (float) ($design['width']  ?? 3508);
        $designH = (float) ($design['height'] ?? 2480);

        // Always output A4 landscape (841.89 × 595.28 pt) regardless of the
        // design canvas size.  Use a uniform scale factor (preserving aspect
        // ratio) so nothing is distorted; the design is scaled to fill width.
        $pageW       = self::A4_W_PT;
        $pageH       = self::A4_H_PT;
        $orientation = 'L';

        // Uniform scale: fit the design into A4 landscape.
        // min() ensures the content never overflows either axis.
        $sf = min($pageW / $designW, $pageH / $designH);

        // --- Create TCPDF instance -----------------------------------------------
        $pdf = $this->createTcpdf($orientation, $pageW, $pageH);

        // --- Step 1: setSignature() MUST be called before the first AddPage() ---
        //     It allocates an object ID for the signature dictionary.
        $this->configureSignature($pdf);

        // --- Add page ------------------------------------------------------------
        //     Pages are 1-indexed in TCPDF. setSignatureAppearance() must come
        //     AFTER AddPage() so the stored page number is 1, not 0.
        //     "Undefined array key 0" in _enddoc() is exactly this bug.
        $pdf->AddPage($orientation, [$pageW, $pageH]);

        // --- Step 2: setSignatureAppearance() AFTER AddPage() --------------------
        $this->applySignatureAppearance($pdf, $sf);

        // --- Background image ----------------------------------------------------
        $this->renderBackground($pdf, $design, $pageW, $pageH);

        // --- Design elements -----------------------------------------------------
        foreach ($design['elements'] ?? [] as $element) {
            $type = $element['type'] ?? '';

            if ($type === 'text') {
                $this->renderTextElement($pdf, $element, $placeholders, $sf);
            } elseif ($type === 'image') {
                $this->renderImageElement($pdf, $element, $qrCodePath, $sf);
            }
        }

        // --- Ensure directory exists ---------------------------------------------
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // --- Ensure OPENSSL_CONF is set before Output() --------------------------
        //     TCPDF calls openssl_pkcs7_sign() inside Output(). On Windows this
        //     fails silently when OPENSSL_CONF points to a missing file, which
        //     causes an "Undefined array key" crash when parsing the result.
        $this->ensureOpensslConf();

        // --- Save ----------------------------------------------------------------
        $pdf->Output($outputPath, 'F');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Create and configure a TCPDF instance.
     */
    private function createTcpdf(string $orientation, float $pageW, float $pageH): TCPDF
    {
        // Suppress TCPDF's default header/footer
        $pdf = new TCPDF($orientation, 'pt', [$pageW, $pageH], true, 'UTF-8', false);

        $pdf->SetCreator(config('app.name'));
        $pdf->SetAuthor($this->signerName);
        $pdf->SetTitle('Sertifikat');
        $pdf->SetSubject('Digital Certificate');

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetAutoPageBreak(false, 0);

        return $pdf;
    }

    /**
     * Step 1 of signature setup — MUST be called before AddPage().
     *
     * Calls setSignature() which allocates a PDF object ID for the signature
     * dictionary. TCPDF requires this to happen before any page is added.
     *
     * TCPDF uses PKCS#7 (similar to CMS / S/MIME). The signature covers the
     * entire document byte-range EXCEPT the hex-encoded signature value itself,
     * which is placed into a reserved gap. Any modification after signing
     * changes those bytes → SHA-256 digest mismatch → signature INVALID.
     */
    private function configureSignature(TCPDF $pdf): void
    {
        $passphrase = $this->privateKeyPassword !== '' ? $this->privateKeyPassword : '';

        /**
         * setSignature($signing_cert, $private_key, $private_key_password,
         *              $extracerts, $cert_type, $info, $approval)
         *
         * Prefix keys with 'file://' so TCPDF reads them from disk; avoids
         * passing raw PEM content through the openssl_pkcs7_sign() pipe.
         *
         * $cert_type = 2 → certify document; no further changes allowed.
         *              3 → certify document; form fill allowed.
         */
        $pdf->setSignature(
            'file://' . str_replace('\\', '/', $this->certificatePath),
            'file://' . str_replace('\\', '/', $this->privateKeyPath),
            $passphrase,
            '',  // no extra certs chain
            2,   // CERT_TYPE_CERTIFY_NO_CHANGES_ALLOWED
            [
                'Name'        => $this->signerName,
                'Location'    => $this->location,
                'Reason'      => $this->reason,
                'ContactInfo' => $this->contactInfo,
            ]
        );
    }

    /**
     * Step 2 of signature setup — MUST be called AFTER AddPage().
     *
     * Calls setSignatureAppearance() which stores the current page number (1+)
     * into $this->signature_appearance['page']. If called before AddPage() the
     * page would be 0, and _enddoc() would crash with "Undefined array key 0"
     * when it tries page_obj_id[0].
     *
     * @param float $sf The same scale factor used for all design-pixel → pt
     *                  conversions, so the signature box stays at the correct
     *                  position after the design is rescaled to A4.
     */
    private function applySignatureAppearance(TCPDF $pdf, float $sf): void
    {
        if (! $this->signatureVisible) {
            return;
        }

        // Convert design-pixel coordinates to PDF points using the same sf
        // that was applied to all other content (fit-to-A4 scale factor).
        $x = $this->sigX * $sf;
        $y = $this->sigY * $sf;
        $w = $this->sigW * $sf;
        $h = $this->sigH * $sf;

        /**
         * setSignatureAppearance($x, $y, $w, $h, $page = -1)
         *
         * When $page is -1 (default) TCPDF uses the current page number, which
         * at this point is 1. PDF viewers render a visible box here showing
         * the signer name, timestamp and reason. The box turns red if invalid.
         */
        $pdf->setSignatureAppearance($x, $y, $w, $h);
    }

    /**
     * Ensure OPENSSL_CONF points to a readable config file.
     *
     * TCPDF internally calls openssl_pkcs7_sign(). On Windows, when the
     * default OPENSSL_CONF path (C:\Program Files\Common Files\SSL\openssl.cnf)
     * does not exist, the OpenSSL extension silently returns false. TCPDF then
     * tries to parse the empty result and crashes with "Undefined array key".
     *
     * We resolve the openssl.cnf that ships alongside PHP
     * ({PHP_BINARY_DIR}/extras/ssl/openssl.cnf) and inject it via putenv().
     */
    private function ensureOpensslConf(): void
    {
        $current = getenv('OPENSSL_CONF');
        if ($current && file_exists($current)) {
            return; // already set and valid
        }

        $phpDir  = dirname(PHP_BINARY);
        $guesses = [
            $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $phpDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/etc/openssl/openssl.cnf',
            '/usr/local/etc/openssl@3/openssl.cnf',
        ];

        foreach ($guesses as $path) {
            $real = realpath($path);
            if ($real && file_exists($real)) {
                putenv("OPENSSL_CONF={$real}");
                return;
            }
        }
        // If no openssl.cnf found, leave the environment unchanged and let
        // OpenSSL fall back to its compiled-in defaults.
    }

    /**
     * Render the certificate background image spanning the full page.
     */
    private function renderBackground(TCPDF $pdf, array $design, float $pageW, float $pageH): void
    {
        if (empty($design['background'])) {
            return;
        }

        $possiblePaths = [
            public_path('storage/' . $design['background']),
            public_path($design['background']),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                // Image($file, $x, $y, $w, $h, $type, $link, $align, $resize, $dpi)
                $pdf->Image(
                    $path,
                    0, 0,
                    $pageW, $pageH,
                    '',     // auto-detect type
                    '',     // no link
                    '',     // no align (absolute)
                    false,  // do not resize
                    self::DESIGN_DPI,
                    '',
                    false,
                    false,
                    0,      // border
                    false,
                    false,
                    false
                );
                return;
            }
        }
    }

    /**
     * Render a text element using TCPDF's MultiCell (plain text) or
     * writeHTMLCell (when the value contains markdown-style formatting).
     *
     * Supported markdown syntax (mirrors the frontend parser):
     *   **bold**       → <b>bold</b>
     *   *italic*       → <i>italic</i>
     *   __underline__  → <u>underline</u>
     *   _italic_       → <i>italic</i>
     *
     * @param float $sf Scale factor (design px → pt)
     */
    private function renderTextElement(TCPDF $pdf, array $element, array $placeholders, float $sf): void
    {
        $text     = $element['value'] ?? '';
        $x        = (float) ($element['x']          ?? 0);
        $y        = (float) ($element['y']          ?? 0);
        $fontSize = (float) ($element['fontSize']   ?? 32);
        $fill     = $element['fill']                ?? '#000000';
        $align    = strtoupper(substr($element['align'] ?? 'L', 0, 1));
        $width    = (float) ($element['width']      ?? 0);   // 0 = auto

        // Resolve placeholders
        foreach ($placeholders as $key => $value) {
            $text = str_replace($key, $value, $text);
        }

        // Font style
        $fontStyle  = $element['fontStyle'] ?? 'normal';
        $tcpdfStyle = '';
        if (str_contains($fontStyle, 'bold'))   $tcpdfStyle .= 'B';
        if (str_contains($fontStyle, 'italic')) $tcpdfStyle .= 'I';
        if (($element['textDecoration'] ?? '') === 'underline') $tcpdfStyle .= 'U';

        // TCPDF font name: try to map common design font families
        $fontFamily = $this->mapFontFamily($element['fontFamily'] ?? 'helvetica');

        // Convert values to pt
        $xPt        = $x        * $sf;
        $yPt        = $y        * $sf;
        $fontSizePt = $fontSize * $sf;
        $widthPt    = $width > 0 ? $width * $sf : 0;

        // Color (hex → R,G,B)
        [$r, $g, $b] = $this->hexToRgb($fill);
        $pdf->SetTextColor($r, $g, $b);

        $pdf->SetFont($fontFamily, $tcpdfStyle, $fontSizePt);
        $pdf->SetXY($xPt, $yPt);

        $lineHeight = (float) ($element['lineHeight'] ?? 1.2);
        $cellH      = $fontSizePt * $lineHeight;

        if ($this->hasMarkdownFormatting($text)) {
            // --- Markdown path: convert to HTML and use writeHTMLCell ----------
            // htmlspecialchars first so that < > & in content are safe, then
            // convert markdown markers to HTML tags, then convert newlines.
            $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html     = $this->parseMarkdownToHtml($safeText);

            // Wrap in a span that carries the base color; font family/size/style
            // are already active via SetFont() above so TCPDF inherits them.
            $html = '<span style="color:' . $fill . ';">' . $html . '</span>';

            $pdf->writeHTMLCell(
                $widthPt,   // w: 0 = full page width
                0,          // h: 0 = auto height
                $xPt,       // x
                $yPt,       // y
                $html,
                0,          // border
                1,          // ln: new line after
                false,      // fill background
                true,       // reseth cursor
                $align,     // align
                true        // autopadding
            );
        } else {
            // --- Plain-text path: MultiCell (faster, exact line-height control) -
            $pdf->MultiCell(
                $widthPt,   // w: 0 = page width
                $cellH,     // h: line height
                $text,
                0,          // border
                $align,
                false,      // fill background
                1,          // ln: go to next line
                $xPt,
                $yPt,
                true,       // reseth
                0,          // stretch
                false,      // isutf8
                true,       // autopadding
                0,          // maxh
                'T',        // valign
                false       // fitcell
            );
        }
    }

    /**
     * Check whether a string contains any markdown formatting markers.
     * Used to decide between the fast MultiCell path and the HTML path.
     */
    private function hasMarkdownFormatting(string $text): bool
    {
        return (bool) preg_match('/\*\*|\*|__|_/', $text);
    }

    /**
     * Convert markdown-style inline formatting to HTML tags.
     *
     * Processing order (longest / greediest first avoids ambiguity):
     *   1. **bold**      → <b>…</b>
     *   2. __underline__ → <u>…</u>
     *   3. *italic*      → <i>…</i>  (single *, not adjacent to another *)
     *   4. _italic_      → <i>…</i>  (single _, not adjacent to another _)
     *
     * The /s flag lets . match newlines so multi-line spans work.
     * The input is expected to already be HTML-escaped, so < > & are safe.
     */
    private function parseMarkdownToHtml(string $text): string
    {
        // Newlines → <br/>
        $text = nl2br($text);

        // **bold**
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $text);

        // __underline__
        $text = preg_replace('/__(.+?)__/s', '<u>$1</u>', $text);

        // *italic* — single asterisk, not part of **
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<i>$1</i>', $text);

        // _italic_ — single underscore, not part of __
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<i>$1</i>', $text);

        return $text;
    }

    /**
     * Render an image element (regular image or QR code).
     *
     * @param float $sf Scale factor (design px → pt)
     */
    private function renderImageElement(
        TCPDF  $pdf,
        array  $element,
        string $qrCodePath,
        float  $sf
    ): void {
        $elementId = $element['id']   ?? '';
        $imagePath = $element['path'] ?? '';

        $x = (float) ($element['x']      ?? 0);
        $y = (float) ($element['y']      ?? 0);
        $w = (float) ($element['width']  ?? 0);
        $h = (float) ($element['height'] ?? 0);

        // Resolve image file path
        if ($elementId === 'qrcode') {
            $fullPath = file_exists($qrCodePath) ? $qrCodePath : null;
        } else {
            $candidates = [
                public_path($imagePath),
                public_path('storage/' . $imagePath),
            ];
            $fullPath = null;
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $fullPath = $candidate;
                    break;
                }
            }
        }

        if (! $fullPath) {
            return;
        }

        $xPt = $x * $sf;
        $yPt = $y * $sf;
        $wPt = $w > 0 ? $w * $sf : 0;
        $hPt = $h > 0 ? $h * $sf : 0;

        // resize=false: $wPt/$hPt are already in PDF points (correctly scaled).
        // Passing resize=true would tell TCPDF to resample the raw pixel data
        // at DESIGN_DPI before placing it, which distorts images when the
        // computed pixel count doesn't match the box size exactly.
        // PDF viewer handles visual scaling at render time — no resampling needed.
        $pdf->Image(
            $fullPath,
            $xPt, $yPt,
            $wPt, $hPt,
            '',     // auto-detect type
            '',     // no link
            '',     // no palign (placed at exact x,y)
            false,  // resize = false → no pixel resampling
            300,    // dpi hint (used only when w=0 or h=0 for auto-sizing)
            '',     // palign
            false,  // ismask
            false,  // imgmask
            0,      // border
            false,  // fitbox — not needed; explicit w/h already set
            false,  // hidden
            false   // fitonpage
        );
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Map a design font-family name to a built-in TCPDF font name.
     * Add entries here as needed for custom fonts.
     */
    private function mapFontFamily(string $family): string
    {
        $family = strtolower(trim($family));

        $map = [
            'arial'      => 'helvetica',
            'helvetica'  => 'helvetica',
            'times'      => 'times',
            'times new roman' => 'times',
            'courier'    => 'courier',
            'courier new'    => 'courier',
            'symbol'     => 'symbol',
            'zapfdingbats' => 'zapfdingbats',
        ];

        return $map[$family] ?? 'helvetica';
    }

    /**
     * Convert a CSS hex color (#RRGGBB or #RGB) to [R, G, B] int array.
     *
     * @return int[]
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Throw if the signing key files don't exist.
     *
     * @throws \RuntimeException
     */
    private function ensureSigningKeysExist(): void
    {
        if (! file_exists($this->privateKeyPath) || ! file_exists($this->certificatePath)) {
            throw new \RuntimeException(
                'Digital signing keys not found. ' .
                'Run `php artisan certificate:generate-keys` first. ' .
                "Expected:\n  Private key: {$this->privateKeyPath}\n  Certificate: {$this->certificatePath}"
            );
        }
    }
}
