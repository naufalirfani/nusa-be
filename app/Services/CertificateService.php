<?php

namespace App\Services;

use App\Models\Kegiatan;
use App\Models\KegiatanPegawai;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf as PDF;

class CertificateService
{
    /**
     * Generate certificate for kegiatan pegawai
     */
    public function generateCertificate(KegiatanPegawai $kegiatanPegawai): string
    {
        $kegiatan = $kegiatanPegawai->kegiatan;
        $desainSertifikat = $kegiatan->desain_sertifikat;

        if (!$desainSertifikat) {
            throw new \Exception('Desain sertifikat tidak ditemukan');
        }

        // Generate nomor sertifikat
        $nomorSertifikat = $this->generateNomorSertifikat($kegiatan, $kegiatanPegawai);

        // Get nama from isi_form
        $nama = $kegiatanPegawai->isi_form['nama_lengkap'] ?? '';

        // Determine peran
        $peran = $this->determinePeran($kegiatan, $kegiatanPegawai->nip);

        // Prepare placeholder data
        $placeholders = [
            '{{nomor_sertifikat}}' => $nomorSertifikat,
            '{{nama}}' => $nama,
            '{{peran}}' => $peran,
            '{{nama_kegiatan}}' => $kegiatan->nama_kegiatan ?? '',
            '{{judul_kegiatan}}' => $kegiatan->judul_tema ?? '',
            '{{tanggal}}' => $this->formatTanggal($kegiatan->tanggal),
        ];

        // Generate the certificate image/PDF
        $certificatePath = $this->renderCertificate($desainSertifikat, $placeholders, $kegiatanPegawai->id);

        return $certificatePath;
    }

    /**
     * Generate nomor sertifikat with format: KP.04/1/1/DPDRI/II/2026
     */
    private function generateNomorSertifikat(Kegiatan $kegiatan, KegiatanPegawai $kegiatanPegawai): string
    {
        $kegiatanSequence = $kegiatan->sequence_number ?? 1;
        $pegawaiSequence = $kegiatanPegawai->sequence_number ?? 1;

        $tanggal = \Carbon\Carbon::parse($kegiatan->tanggal);
        $bulan = strtoupper($tanggal->translatedFormat('m')); // Roman numeral for month
        $tahun = $tanggal->format('Y');

        // Convert month number to Roman numeral
        $bulanRoman = $this->toRoman((int)$bulan);

        return "KP.04/{$kegiatanSequence}/{$pegawaiSequence}/DPDRI/{$bulanRoman}/{$tahun}";
    }

    /**
     * Convert number to Roman numeral
     */
    private function toRoman(int $number): string
    {
        $map = [
            12 => 'XII',
            11 => 'XI',
            10 => 'X',
            9 => 'IX',
            8 => 'VIII',
            7 => 'VII',
            6 => 'VI',
            5 => 'V',
            4 => 'IV',
            3 => 'III',
            2 => 'II',
            1 => 'I'
        ];
        return $map[$number] ?? 'I';
    }

    /**
     * Determine peran based on nip
     */
    private function determinePeran(Kegiatan $kegiatan, string $nip): string
    {
        if ($kegiatan->narasumber === $nip) {
            return 'Narasumber';
        }

        if ($kegiatan->moderator === $nip) {
            return 'Moderator';
        }

        return 'Peserta';
    }

    /**
     * Format tanggal to Indonesian format
     */
    private function formatTanggal($tanggal): string
    {
        $date = \Carbon\Carbon::parse($tanggal);
        $date->locale('id');
        return $date->translatedFormat('d F Y');
    }

    /**
     * Render certificate from design JSON using DomPDF
     */
    private function renderCertificate(array $design, array $placeholders, string $id): string
    {
        $width = $design['width'] ?? 3508;
        $height = $design['height'] ?? 2480;

        // Generate HTML from template
        $html = $this->generateHTML($design, $placeholders);

        // Generate PDF using DomPDF
        $filename = "certificates/{$id}.pdf";
        $fullPath = storage_path('app/public/' . $filename);

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        // Configure PDF options for DomPDF
        $pdf = PDF::loadHTML($html)
            ->setPaper([0, 0, $width * 0.75, $height * 0.75], 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);

        $pdf->save($fullPath);

        return $filename;
    }

    /**
     * Generate HTML from design template
     */
    private function generateHTML(array $design, array $placeholders): string
    {
        return view('certificates.template', compact('design', 'placeholders'))->render();
    }

    /**
     * Generate HTML for text element
     */
    private function generateTextElement(array $element, array $placeholders): string
    {
        $text = $element['value'] ?? '';

        // Replace placeholders
        foreach ($placeholders as $key => $value) {
            $text = str_replace($key, $value, $text);
        }

        $x = $element['x'] ?? 0;
        $y = $element['y'] ?? 0;
        $fontSize = $element['fontSize'] ?? 32;
        $fontFamily = $element['fontFamily'] ?? 'Arial';
        $fontStyle = $element['fontStyle'] ?? 'normal';
        $fill = $element['fill'] ?? '#000000';
        $align = $element['align'] ?? 'left';
        $width = $element['width'] ?? 'auto';
        $textDecoration = $element['textDecoration'] ?? 'none';
        $lineHeight = $element['lineHeight'] ?? 1.2;

        // Convert font style to CSS
        $fontWeight = 'normal';
        $fontStyleCss = 'normal';
        if (strpos($fontStyle, 'bold') !== false) {
            $fontWeight = 'bold';
        }
        if (strpos($fontStyle, 'italic') !== false) {
            $fontStyleCss = 'italic';
        }

        $style = "
            left: {$x}px;
            top: {$y}px;
            font-size: {$fontSize}px;
            font-family: '{$fontFamily}', Arial, sans-serif;
            font-weight: {$fontWeight};
            font-style: {$fontStyleCss};
            color: {$fill};
            text-align: {$align};
            text-decoration: {$textDecoration};
            line-height: {$lineHeight};
        ";

        if (is_numeric($width)) {
            $style .= "width: {$width}px;";
        }

        return '<div class="element text-element" style="' . $style . '">' . htmlspecialchars($text) . '</div>';
    }

    /**
     * Generate HTML for image element
     */
    private function generateImageElement(array $element): string
    {
        $imagePath = $element['path'] ?? '';

        // Try different paths
        $possiblePaths = [
            public_path($imagePath),
            public_path('storage/' . $imagePath),
        ];

        $fullPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $fullPath = $path;
                break;
            }
        }

        if (!$fullPath) {
            return '';
        }

        // Convert image to base64
        $imageData = file_get_contents($fullPath);
        $mimeType = mime_content_type($fullPath);
        $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

        $x = $element['x'] ?? 0;
        $y = $element['y'] ?? 0;
        $width = $element['width'] ?? 'auto';
        $height = $element['height'] ?? 'auto';
        $fitted = $element['fitted'] ?? false;

        $style = "
            left: {$x}px;
            top: {$y}px;
        ";

        if (is_numeric($width)) {
            $style .= "width: {$width}px;";
        }
        if (is_numeric($height)) {
            $style .= "height: {$height}px;";
        }

        if ($fitted) {
            $style .= "object-fit: contain;";
        }

        return '<img src="' . $base64Image . '" class="element" style="' . $style . '" alt="" />';
    }
}
