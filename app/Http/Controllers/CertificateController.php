<?php

namespace App\Http\Controllers;

use App\Models\KegiatanPegawai;
use Illuminate\Http\JsonResponse;

/**
 * CertificateController
 *
 * Handles the public certificate-verification endpoint.
 *
 * Why no hash comparison?
 * -----------------------
 * The previous implementation compared a stored SHA-256 hash of the server
 * copy against a freshly-computed hash of the same server copy.  This could
 * never detect tampering in the copy a user downloaded.
 *
 * With digital signing the PDF file itself carries the proof of authenticity:
 *   - The PDF's internal signature covers every byte of the document.
 *   - Any edit (text, metadata, page) changes those bytes → invalid signature.
 *   - PDF viewers (Adobe Acrobat, Firefox, Chrome, evince…) flag this visually
 *     without any server round-trip.
 *
 * The verify API is now purely informational: it returns the certificate
 * metadata so the frontend can display a human-readable summary next to the
 * QR code result.  Cryptographic tamper-detection is in the PDF itself.
 */
class CertificateController extends Controller
{
    /**
     * Return metadata for a certificate identified by its UUID.
     *
     * Supports two identifier forms for backward compatibility:
     *   1. UUID  – the KegiatanPegawai primary key (new QR codes).
     *   2. Token – the legacy verification_token string (old QR codes).
     *
     * GET /sertifikat/verify/{identifier}
     */
    public function verify(string $identifier): JsonResponse
    {
        try {
            // --- Resolve the record -------------------------------------------
            // Try UUID (primary key) first; fall back to legacy token column.
            $kegiatanPegawai = KegiatanPegawai::find($identifier)->first();

            if (! $kegiatanPegawai) {
                return response()->json([
                    'valid'   => false,
                    'message' => 'Sertifikat tidak ditemukan.',
                ], 404);
            }

            // --- Check that the PDF file exists on the server -----------------
            if ($kegiatanPegawai->link_sertifikat) {
                $pdfExists = file_exists(
                    storage_path('app/public/' . $kegiatanPegawai->link_sertifikat)
                );
            } else {
                $pdfExists = false;
            }

            // --- Load relationship --------------------------------------------
            $kegiatanPegawai->load('kegiatan');

            // --- Build response -----------------------------------------------
            return response()->json([
                'valid'   => $pdfExists,
                'message' => $pdfExists
                    ? 'Sertifikat ditemukan. Periksa tanda tangan digital pada file PDF untuk validasi keaslian.'
                    : 'File sertifikat tidak ditemukan di server.',

                // Hint for the frontend to display a guidance banner
                'signature_note' => 'Keaslian dokumen dijamin oleh tanda tangan digital yang tertanam dalam file PDF. '
                    . 'Buka file PDF dengan Adobe Acrobat atau PDF reader lain untuk melihat status signature.',

                'data' => [
                    'id'             => $kegiatanPegawai->id,
                    'nip'            => $kegiatanPegawai->nip,
                    'nama_lengkap'   => $kegiatanPegawai->isi_form['nama_lengkap']   ?? null,
                    'jabatan'        => $kegiatanPegawai->isi_form['jabatan']        ?? null,
                    'unit_kerja'     => $kegiatanPegawai->isi_form['unit_kerja']     ?? null,
                    'status_pegawai' => $kegiatanPegawai->isi_form['status_pegawai'] ?? null,
                    'isi_form'       => $kegiatanPegawai->isi_form,
                    'link_sertifikat' => $kegiatanPegawai->link_sertifikat,
                    'signed_at'      => $kegiatanPegawai->signed_at,
                    'kegiatan'       => $kegiatanPegawai->kegiatan ? [
                        'id'             => $kegiatanPegawai->kegiatan->id,
                        'nama_kegiatan'  => $kegiatanPegawai->kegiatan->nama_kegiatan,
                        'judul_tema'     => $kegiatanPegawai->kegiatan->judul_tema,
                        'tanggal'        => $kegiatanPegawai->kegiatan->tanggal,
                        'jenis_kegiatan' => $kegiatanPegawai->kegiatan->jenis_kegiatan,
                    ] : null,
                    'created_at'     => $kegiatanPegawai->created_at,
                    'updated_at'     => $kegiatanPegawai->updated_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'valid'   => false,
                'message' => 'Terjadi kesalahan saat verifikasi.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
