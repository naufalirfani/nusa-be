<?php

namespace App\Services;

use App\Models\Kegiatan;
use App\Models\KegiatanPegawai;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * CertificateService
 *
 * Orchestrates certificate generation:
 *   1. Resolves placeholder values (name, role, date, cert number, etc.).
 *   2. Generates a QR code PNG that points to /verify/{uuid}.
 *   3. Delegates PDF rendering + digital signing to the appropriate service:
 *        • template_sertifikat set → PptxCertificateService (PPTX-based)
 *        • desain_sertifikat set  → PdfSigningService (JSON design-based)
 *   4. Persists the output path and sign timestamp on the model.
 *
 * Verification flow
 * -----------------
 *   QR scan -> frontend /verify/{uuid}
 *           -> API GET /sertifikat/verify/{uuid}
 *           -> returns certificate metadata (no hash check needed)
 *
 * File tamper detection is handled by the embedded PDF digital signature.
 * PDF viewers display "Signature INVALID" automatically if the file is edited.
 */
class CertificateService
{
    public function __construct(
        private readonly PdfSigningService     $signingService,
        private readonly PptxCertificateService $pptxService
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate (or regenerate) a digitally-signed PDF certificate.
     *
     * @return string Relative storage path, e.g. "certificates/{uuid}.pdf"
     *
     * @throws \Exception when design is missing or signing fails
     */
    public function generateCertificate(KegiatanPegawai $kegiatanPegawai): string
    {
        $kegiatan           = $kegiatanPegawai->kegiatan;
        $templateSertifikat = $kegiatan->template_sertifikat;
        $desainSertifikat   = $kegiatan->desain_sertifikat;

        if (! $templateSertifikat && ! $desainSertifikat) {
            throw new \Exception('Desain sertifikat tidak ditemukan.');
        }

        // --- Resolve data -------------------------------------------------------
        $nomorSertifikat = $this->generateNomorSertifikat($kegiatan, $kegiatanPegawai);
        $nama            = $kegiatanPegawai->isi_form['nama_lengkap'] ?? '';
        $peran           = $this->determinePeran($kegiatan, $kegiatanPegawai->nip);

        $placeholders = [
            '{{nomor_sertifikat}}' => $nomorSertifikat,
            '{{nama}}'             => $nama,
            '{{peran}}'            => $peran,
            '{{nama_kegiatan}}'    => $kegiatan->nama_kegiatan ?? '',
            '{{judul_kegiatan}}'   => $kegiatan->judul_tema    ?? '',
            '{{tanggal}}'          => $this->formatTanggal($kegiatan->tanggal),
        ];

        // --- Generate QR code ---------------------------------------------------
        // The UUID (primary key) is the stable public identifier for this cert.
        $qrCodePath = $this->generateQRCode($kegiatanPegawai->id);

        // --- Render + sign PDF --------------------------------------------------
        $relativePath = "certificates/{$kegiatanPegawai->id}.pdf";
        $absolutePath = storage_path("app/public/{$relativePath}");

        if ($templateSertifikat) {
            // PPTX template path: placeholders replaced in slide XML, {{tte}} → QR code
            $this->pptxService->generate(
                $templateSertifikat,
                $placeholders,
                $qrCodePath,
                $absolutePath
            );
        } else {
            // JSON design-based generation (existing flow)
            $this->signingService->generate(
                $desainSertifikat,
                $placeholders,
                $qrCodePath,
                $absolutePath
            );
        }

        // --- Persist ------------------------------------------------------------
        $kegiatanPegawai->link_sertifikat = $relativePath;
        $kegiatanPegawai->signed_at       = now();
        $kegiatanPegawai->save();

        return $relativePath;
    }

    /**
     * Generate a test certificate for a Kegiatan without saving to storage.
     *
     * Uses dummy placeholder values so no real KegiatanPegawai record is needed.
     * The PDF is generated to a temp file, read into memory, and cleaned up.
     *
     * @return string Raw PDF bytes
     *
     * @throws \Exception when the kegiatan has no certificate design
     */
    public function generateForTest(\App\Models\Kegiatan $kegiatan): string
    {
        $templateSertifikat = $kegiatan->template_sertifikat;
        $desainSertifikat   = $kegiatan->desain_sertifikat;

        if (! $templateSertifikat && ! $desainSertifikat) {
            throw new \Exception('Kegiatan ini tidak memiliki desain sertifikat.');
        }

        $placeholders = [
            '{{nomor_sertifikat}}' => 'KP.04.00/1/1/DPDRI/III/2026',
            '{{nama}}'             => 'John Doe',
            '{{peran}}'            => 'Peserta',
            '{{nama_kegiatan}}'    => $kegiatan->nama_kegiatan ?? 'Pelatihan Pengembangan SDM',
            '{{judul_kegiatan}}'   => $kegiatan->judul_tema    ?? 'Tema Pelatihan',
            '{{tanggal}}'          => $this->formatTanggal($kegiatan->tanggal ?? now()),
        ];

        $tempId  = uniqid('test_cert_', true);
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempId;
        @mkdir($tempDir, 0755, true);

        $qrCodePath  = $tempDir . DIRECTORY_SEPARATOR . 'qr.png';
        $tempPdfPath = $tempDir . DIRECTORY_SEPARATOR . 'certificate.pdf';

        try {
            // Generate a placeholder QR code pointing to a dummy URL
            QrCode::format('png')->size(300)->margin(0)
                ->generate(config('app.frontend_url', config('app.url')) . '/verify/test-preview', $qrCodePath);

            if ($templateSertifikat) {
                $this->pptxService->generate(
                    $templateSertifikat,
                    $placeholders,
                    $qrCodePath,
                    $tempPdfPath
                );
            } else {
                $this->signingService->generate(
                    $desainSertifikat,
                    $placeholders,
                    $qrCodePath,
                    $tempPdfPath
                );
            }

            return file_get_contents($tempPdfPath);
        } finally {
            foreach ([$tempPdfPath, $qrCodePath] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Generate a QR code PNG that encodes the frontend verify-page URL.
     *
     * @return string Absolute file path to the generated PNG
     */
    private function generateQRCode(string $uuid): string
    {
        $frontendUrl     = config('app.frontend_url', config('app.url'));
        $verificationUrl = "{$frontendUrl}/verify/{$uuid}";

        $dir = storage_path('app/public/qrcodes');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path     = "{$dir}/{$uuid}.png";
        $logoPath = public_path('logo-dpd-bgwhite-60x60.png');

        $qr = QrCode::format('png')->size(300)->margin(0);

        if (file_exists($logoPath)) {
            $qr = $qr->merge($logoPath, 0.20, true);
        }

        $qr->generate($verificationUrl, $path);

        return $path;
    }

    /**
     * Format: KP.04.00/{kegiatanSeq}/{pegawaiSeq}/DPDRI/{bulanRoman}/{tahun}
     */
    private function generateNomorSertifikat(Kegiatan $kegiatan, KegiatanPegawai $kegiatanPegawai): string
    {
        $kegiatanSequence = $kegiatan->sequence_number        ?? 1;
        $pegawaiSequence  = $kegiatanPegawai->sequence_number ?? 1;

        $tanggal    = \Carbon\Carbon::parse($kegiatan->tanggal);
        $bulanRoman = $this->toRoman((int) $tanggal->format('m'));
        $tahun      = $tanggal->format('Y');

        return "KP.04.00/{$kegiatanSequence}/{$pegawaiSequence}/DPDRI/{$bulanRoman}/{$tahun}";
    }

    /** @return string Roman numeral 'I' � 'XII' */
    private function toRoman(int $n): string
    {
        return [
            1  => 'I',   2 => 'II',   3 => 'III', 4 => 'IV',
            5  => 'V',   6 => 'VI',   7 => 'VII', 8 => 'VIII',
            9  => 'IX', 10 => 'X',   11 => 'XI', 12 => 'XII',
        ][$n] ?? 'I';
    }

    private function determinePeran(Kegiatan $kegiatan, string $nip): string
    {
        if ($kegiatan->narasumber === $nip) return 'Narasumber';
        if ($kegiatan->moderator  === $nip) return 'Moderator';
        return 'Peserta';
    }

    private function formatTanggal($tanggal): string
    {
        $date = \Carbon\Carbon::parse($tanggal);
        $date->locale('id');
        return $date->translatedFormat('d F Y');
    }
}
