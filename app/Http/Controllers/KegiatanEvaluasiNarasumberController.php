<?php

namespace App\Http\Controllers;

use App\Models\Kegiatan;
use App\Models\KegiatanEvaluasiNarasumber;
use App\Models\KegiatanPegawai;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class KegiatanEvaluasiNarasumberController extends Controller
{
    protected CertificateService $certificateService;

    public function __construct(CertificateService $certificateService)
    {
        $this->certificateService = $certificateService;
    }

    /**
     * Display a listing of speaker evaluation responses.
     */
    public function index(Request $request)
    {
        $query = KegiatanEvaluasiNarasumber::query();

        if ($request->has('kegiatan_id')) {
            $query->where('kegiatan_id', $request->kegiatan_id);
        }

        if ($request->has('nip')) {
            $query->where('nip', $request->nip);
        }

        $query->orderBy('created_at', 'desc');

        $data = $query->get();

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * Store a new speaker evaluation response.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kegiatan_id' => 'required|string',
            'nip'         => 'nullable|string',
            'isi_form'    => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $isiForm = $request->isi_form;
        if (is_string($isiForm)) {
            $decoded = json_decode($isiForm, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $isiForm = $decoded;
            }
        }

        $record = KegiatanEvaluasiNarasumber::create([
            'kegiatan_id' => $request->kegiatan_id,
            'nip'         => $request->nip ?: null,
            'isi_form'    => $isiForm,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Evaluasi narasumber berhasil disimpan.',
            'data'    => $record,
        ], 201);
    }

    /**
     * Generate or regenerate certificates from speaker evaluation respondents who have a NIP.
     * Creates a new row in kegiatan_pegawai if not exists for (kegiatan_id, nip),
     * or updates and replaces the existing certificate if already exists (1 pegawai = 1 kegiatan_pegawai).
     */
    public function generateCertificates(Request $request)
    {
        set_time_limit(0);

        $validator = Validator::make($request->all(), [
            'kegiatan_id'      => 'required|uuid|exists:kegiatan,id',
            'nip'              => 'nullable|string',
            'pegawai_profiles' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $kegiatan = Kegiatan::find($request->kegiatan_id);
        if (! $kegiatan) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Kegiatan tidak ditemukan.',
            ], 404);
        }

        if (! $kegiatan->butuh_sertifikat) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Kegiatan ini tidak membutuhkan sertifikat.',
            ], 422);
        }

        if (! $kegiatan->template_sertifikat && ! $kegiatan->desain_sertifikat) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Kegiatan ini belum memiliki template atau desain sertifikat.',
            ], 422);
        }

        $query = KegiatanEvaluasiNarasumber::where('kegiatan_id', $kegiatan->id)
            ->orderBy('created_at', 'asc');

        if ($request->filled('nip')) {
            $targetNip = trim((string) $request->nip);
            $query->where(function ($q) use ($targetNip) {
                $q->where('nip', $targetNip)
                  ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$targetNip]);
            });
        }

        $evalRecords = $query->get();

        // Deduplicate by valid NIP (1 pegawai = 1 kegiatan_pegawai)
        $groupedByNip = [];
        foreach ($evalRecords as $eval) {
            $isi = is_array($eval->isi_form) ? $eval->isi_form : [];
            $nip = trim((string) ($eval->nip ?: ($isi['nip'] ?? $isi['nip_no_absen'] ?? '')));

            if ($nip === '' || $nip === '-' || strtolower($nip) === 'umum' || strtolower($nip) === 'null') {
                continue;
            }

            if (! isset($groupedByNip[$nip])) {
                $groupedByNip[$nip] = [
                    'nip'      => $nip,
                    'isi_form' => $isi,
                ];
            } else {
                $groupedByNip[$nip]['isi_form'] = array_merge($groupedByNip[$nip]['isi_form'], $isi);
            }
        }

        if (empty($groupedByNip)) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Tidak ada data responden evaluasi narasumber yang memiliki NIP.',
            ], 422);
        }

        $frontendProfiles = is_array($request->pegawai_profiles) ? $request->pegawai_profiles : [];
        $createdCount = 0;
        $updatedCount = 0;
        $failedCount  = 0;
        $errors       = [];
        $results      = [];

        foreach ($groupedByNip as $nip => $entry) {
            try {
                $evalIsiForm = $entry['isi_form'];
                $fallbackProfile = isset($frontendProfiles[$nip]) && is_array($frontendProfiles[$nip])
                    ? $frontendProfiles[$nip]
                    : null;

                $profile = $this->resolvePegawaiProfile($nip, $fallbackProfile);

                $existingRecords = KegiatanPegawai::where('kegiatan_id', $kegiatan->id)
                    ->where(function ($q) use ($nip) {
                        $q->where('nip', $nip)
                          ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$nip]);
                    })
                    ->orderBy('created_at', 'asc')
                    ->get();

                if ($existingRecords->isNotEmpty()) {
                    $kegiatanPegawai = $existingRecords->first();

                    // Hapus duplikat ekstra jika sebelumnya ada lebih dari 1 row untuk NIP yang sama
                    if ($existingRecords->count() > 1) {
                        foreach ($existingRecords->slice(1) as $dup) {
                            if ($dup->link_sertifikat && $dup->link_sertifikat !== $kegiatanPegawai->link_sertifikat) {
                                $dupPath = storage_path('app/public/' . $dup->link_sertifikat);
                                if (file_exists($dupPath)) {
                                    @unlink($dupPath);
                                }
                            }
                            $dup->delete();
                        }
                    }

                    $existingIsiForm = is_array($kegiatanPegawai->isi_form) ? $kegiatanPegawai->isi_form : [];
                    $mergedIsiForm   = array_merge($evalIsiForm, $existingIsiForm);

                    $mergedIsiForm['nama_lengkap'] = (! empty($existingIsiForm['nama_lengkap']) && $existingIsiForm['nama_lengkap'] !== '-')
                        ? $existingIsiForm['nama_lengkap']
                        : ($profile['nama_lengkap'] ?: ($evalIsiForm['nama_lengkap'] ?? $nip));
                    $mergedIsiForm['nip_no_absen'] = (! empty($existingIsiForm['nip_no_absen']) && $existingIsiForm['nip_no_absen'] !== '-')
                        ? $existingIsiForm['nip_no_absen']
                        : $nip;
                    $mergedIsiForm['jabatan'] = (! empty($existingIsiForm['jabatan']) && $existingIsiForm['jabatan'] !== '-')
                        ? $existingIsiForm['jabatan']
                        : ($profile['jabatan'] ?: ($evalIsiForm['jabatan'] ?? '-'));
                    $mergedIsiForm['unit_kerja'] = (! empty($existingIsiForm['unit_kerja']) && $existingIsiForm['unit_kerja'] !== '-')
                        ? $existingIsiForm['unit_kerja']
                        : ($profile['unit_kerja'] ?: ($evalIsiForm['unit_kerja'] ?? '-'));
                    $mergedIsiForm['status_pegawai'] = (! empty($existingIsiForm['status_pegawai']) && $existingIsiForm['status_pegawai'] !== '-')
                        ? $existingIsiForm['status_pegawai']
                        : ($profile['status_pegawai'] ?: ($evalIsiForm['status_pegawai'] ?? '-'));

                    $kegiatanPegawai->nip      = $nip;
                    $kegiatanPegawai->isi_form = $mergedIsiForm;
                    $kegiatanPegawai->save();
                    $isUpdate = true;
                } else {
                    $newIsiForm = $evalIsiForm;
                    $newIsiForm['nama_lengkap']   = (! empty($evalIsiForm['nama_lengkap']) && $evalIsiForm['nama_lengkap'] !== '-')
                        ? $evalIsiForm['nama_lengkap']
                        : ($profile['nama_lengkap'] ?: $nip);
                    $newIsiForm['nip_no_absen']   = $nip;
                    $newIsiForm['jabatan']        = (! empty($evalIsiForm['jabatan']) && $evalIsiForm['jabatan'] !== '-')
                        ? $evalIsiForm['jabatan']
                        : ($profile['jabatan'] ?: '-');
                    $newIsiForm['unit_kerja']     = (! empty($evalIsiForm['unit_kerja']) && $evalIsiForm['unit_kerja'] !== '-')
                        ? $evalIsiForm['unit_kerja']
                        : ($profile['unit_kerja'] ?: '-');
                    $newIsiForm['status_pegawai'] = (! empty($evalIsiForm['status_pegawai']) && $evalIsiForm['status_pegawai'] !== '-')
                        ? $evalIsiForm['status_pegawai']
                        : ($profile['status_pegawai'] ?: '-');

                    $kegiatanPegawai = KegiatanPegawai::create([
                        'kegiatan_id' => $kegiatan->id,
                        'nip'         => $nip,
                        'isi_form'    => $newIsiForm,
                    ]);
                    $isUpdate = false;
                }

                $kegiatanPegawai->setRelation('kegiatan', $kegiatan);

                // Generate atau regenerate (replace) sertifikat
                $this->certificateService->generateCertificate($kegiatanPegawai);

                if ($isUpdate) {
                    $updatedCount++;
                } else {
                    $createdCount++;
                }

                $results[] = $kegiatanPegawai;
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = [
                    'nip'     => $nip,
                    'message' => $e->getMessage(),
                ];
                Log::error("Gagal generate sertifikat evaluasi narasumber untuk NIP {$nip}: " . $e->getMessage());
            }
        }

        $totalProcessed = $createdCount + $updatedCount;

        return response()->json([
            'success'         => $totalProcessed > 0,
            'status'          => $totalProcessed > 0 ? 'success' : 'error',
            'message'         => $totalProcessed > 0
                ? "Berhasil memproses sertifikat untuk {$totalProcessed} pegawai ({$createdCount} data baru dibuat, {$updatedCount} data diperbarui/digenerate ulang)."
                : 'Gagal memproses sertifikat.',
            'created_count'   => $createdCount,
            'updated_count'   => $updatedCount,
            'failed_count'    => $failedCount,
            'total_processed' => $totalProcessed,
            'errors'          => $errors,
            'data'            => $results,
        ], $totalProcessed > 0 ? 200 : 500);
    }

    /**
     * Resolve employee profile from CMB API by NIP, with fallback to frontend profile data.
     */
    private function resolvePegawaiProfile(string $nip, ?array $fallback = null): array
    {
        $result = [
            'nama_lengkap'   => $fallback['nama_lengkap']   ?? '',
            'jabatan'        => $fallback['jabatan']        ?? '',
            'unit_kerja'     => $fallback['unit_kerja']     ?? '',
            'status_pegawai' => $fallback['status_pegawai'] ?? '',
        ];

        try {
            $cmbController = app(CmbApiController::class);
            $cmbRequest    = new Request(['with_unit_parent' => 'true']);
            $response      = $cmbController->getPegawaiByNip($cmbRequest, $nip);

            if ($response->getStatusCode() === 200) {
                $decoded = json_decode($response->getContent(), true);
                $p = $decoded['data']['data'] ?? $decoded['data'] ?? $decoded ?? null;

                if (is_array($p) && ! empty($p)) {
                    $json = isset($p['json']) && is_array($p['json']) ? $p['json'] : [];

                    $rawName = $p['nama']
                        ?? $p['name']
                        ?? $p['nama_lengkap']
                        ?? $p['full_name']
                        ?? $p['namaLengkap']
                        ?? $json['nama']
                        ?? '';

                    $gelarDepan    = trim((string) ($p['gelarDepan'] ?? $p['gelar_depan'] ?? $json['gelarDepan'] ?? ''));
                    $gelarBelakang = trim((string) ($p['gelarBelakang'] ?? $p['gelar_belakang'] ?? $json['gelarBelakang'] ?? ''));

                    if ($rawName !== '') {
                        $formattedName = $this->formatPersonName((string) $rawName);
                        if ($gelarDepan !== '' && ! str_starts_with(strtolower($formattedName), strtolower($gelarDepan))) {
                            $formattedName = "{$gelarDepan} {$formattedName}";
                        }
                        if ($gelarBelakang !== '' && ! str_ends_with(strtolower($formattedName), strtolower($gelarBelakang))) {
                            $formattedName = "{$formattedName}, {$gelarBelakang}";
                        }
                        $result['nama_lengkap'] = trim($formattedName);
                    }

                    $jabatan = $json['jabatanNama']
                        ?? $p['jabatan_name']
                        ?? $p['jabatan']
                        ?? $p['nama_jabatan']
                        ?? '';
                    if ($jabatan !== '') {
                        $result['jabatan'] = (string) $jabatan;
                    }

                    $unitKerja = $p['unit_organisasi_parent']
                        ?? $json['unorNama']
                        ?? $p['unit_organisasi_name']
                        ?? $p['unit_kerja']
                        ?? $p['unit']
                        ?? '';
                    if ($unitKerja !== '') {
                        $result['unit_kerja'] = (string) $unitKerja;
                    }

                    $statusPegawai = $json['statusPegawai']
                        ?? $p['statusPegawai']
                        ?? $p['status_pegawai']
                        ?? '';
                    if ($statusPegawai !== '') {
                        $result['status_pegawai'] = (string) $statusPegawai;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Gagal mengambil profil pegawai dari CMB API untuk NIP {$nip}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Format person name into Title Case while preserving suffixes/degrees after comma.
     */
    private function formatPersonName(string $name): string
    {
        if (trim($name) === '') {
            return '';
        }

        $parts  = explode(',', $name);
        $main   = trim($parts[0] ?? '');
        $suffix = trim(implode(',', array_slice($parts, 1)));

        $words = preg_split('/\s+/', $main, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $formattedWords = array_map(function ($w) {
            $lower = mb_strtolower($w);
            if (mb_strlen($lower) <= 2) {
                return mb_strtoupper($lower);
            }
            return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        }, $words);

        $formattedMain = implode(' ', $formattedWords);
        return $suffix !== '' ? "{$formattedMain}, {$suffix}" : $formattedMain;
    }

    /**
     * Remove a speaker evaluation response.
     */
    public function destroy($id)
    {
        $record = KegiatanEvaluasiNarasumber::find($id);
        if (!$record) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }

        $record->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Evaluasi narasumber berhasil dihapus.',
        ]);
    }
}
