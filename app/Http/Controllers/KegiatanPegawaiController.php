<?php

namespace App\Http\Controllers;

use App\Models\Kegiatan;
use App\Models\KegiatanPegawai;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class KegiatanPegawaiController extends Controller
{
    protected $certificateService;

    public function __construct(CertificateService $certificateService)
    {
        $this->certificateService = $certificateService;
    }

    /**
     * Display a listing of kegiatan pegawai.
     * Supports filtering by kegiatan_id and nip.
     */
    public function index(Request $request)
    {
        $query = KegiatanPegawai::with('kegiatan');
        $withPagination = $request->boolean('with_pagination', true);
        $withIsiForm = $request->boolean('with_isi_form', true);

        if (!$withIsiForm) {
            $columns = Schema::getColumnListing('kegiatan_pegawai');
            $query->select(array_diff($columns, ['isi_form']));
        }

        // Filter by kegiatan_id (accept single id, array, or comma-separated list)
        if ($request->has('kegiatan_id')) {
            $ids = $request->kegiatan_id;
            if (is_string($ids)) {
                $ids = array_filter(array_map('trim', explode(',', $ids)));
            }
            if (is_array($ids)) {
                $ids = array_values($ids);
                if (count($ids) === 1) {
                    $query->where('kegiatan_id', $ids[0]);
                } else {
                    $query->whereIn('kegiatan_id', $ids);
                }
            } else {
                $query->where('kegiatan_id', $ids);
            }
        }

        // Filter by nip
        if ($request->has('nip')) {
            $query->where('nip', $request->nip);
        }

        // Search across isi_form fields when `q` provided (PostgreSQL only)
        if ($request->has('q') && strlen($request->get('q')) > 0) {
            $search = $request->get('q');
            $like = "%{$search}%";
            $fields = ['nama_lengkap', 'nip_no_absen', 'jabatan', 'unit_kerja', 'status_pegawai'];

            $query->where(function ($sub) use ($like, $fields) {
                foreach ($fields as $idx => $field) {
                    $expr = "(isi_form->>'{$field}') ILIKE ?";
                    if ($idx === 0) {
                        $sub->whereRaw($expr, [$like]);
                    } else {
                        $sub->orWhereRaw($expr, [$like]);
                    }
                }

                // Also search related `kegiatan` fields: `nama_kegiatan` and `judul_tema`
                $sub->orWhereHas('kegiatan', function ($q) use ($like) {
                    $q->where('nama_kegiatan', 'ILIKE', $like)
                        ->orWhere('judul_tema', 'ILIKE', $like);
                });
            });
        }

        // Sorting: allowed isi_form fields (PostgreSQL only)
        $allowed = ['nama_lengkap', 'nip_no_absen', 'jabatan', 'unit_kerja', 'status_pegawai'];
        if ($request->has('sort')) {
            $sortParam = $request->get('sort');
            $key = preg_replace('/^isi_form\./', '', $sortParam);
            $direction = strtolower($request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';
            if (in_array($key, $allowed, true)) {
                $expr = "(isi_form->>'{$key}')";
                $query->orderByRaw("{$expr} {$direction}");
            }
        }

        // Pagination (fallback ordering if no sort applied)
        if ($withPagination) {
            if (empty($request->get('sort'))) {
                $query->orderBy('created_at', 'desc');
            }

            $perPage = $request->get('per_page', 25);
            $kegiatanPegawai = $query->paginate($perPage);
        } else {
            if (empty($request->get('sort'))) {
                $query->orderBy('created_at', 'desc');
            }
            $kegiatanPegawai = $query->get();
        }

        return response()->json([
            'success' => true,
            'message' => 'Data kegiatan pegawai berhasil diambil',
            'data' => $kegiatanPegawai,
        ]);
    }

    /**
     * Store a newly created kegiatan pegawai.
     * Auto-generates certificate upon creation.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kegiatan_id' => 'required|uuid|exists:kegiatan,id',
            'nip' => 'required|string',
            'isi_form' => 'required|array',
            'isi_form.nama_lengkap' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $nip = trim((string) $request->nip);
            $isValidNip = $nip !== '' && $nip !== '-' && strtolower($nip) !== 'umum' && strtolower($nip) !== 'null';

            $existing = null;
            if ($isValidNip) {
                $existing = KegiatanPegawai::where('kegiatan_id', $request->kegiatan_id)
                    ->where(function ($q) use ($nip) {
                        $q->where('nip', $nip)
                          ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$nip]);
                    })
                    ->orderBy('created_at', 'asc')
                    ->first();
            }

            if ($existing) {
                $existingIsiForm = is_array($existing->isi_form) ? $existing->isi_form : [];
                $existing->nip = $nip;
                $existing->isi_form = array_merge($existingIsiForm, $request->isi_form);
                $existing->save();
                $kegiatanPegawai = $existing;
                $isUpdated = true;
            } else {
                // Create kegiatan pegawai
                $kegiatanPegawai = KegiatanPegawai::create([
                    'kegiatan_id' => $request->kegiatan_id,
                    'nip' => $nip !== '' ? $nip : $request->nip,
                    'isi_form' => $request->isi_form,
                ]);
                $isUpdated = false;
            }

            // Load relationship
            $kegiatanPegawai->load('kegiatan');

            // Generate / regenerate certificate
            if (! $kegiatanPegawai->kegiatan || $kegiatanPegawai->kegiatan->butuh_sertifikat) {
                try {
                    $this->certificateService->generateCertificate($kegiatanPegawai);
                } catch (\Exception $e) {
                    // Log error but don't fail the creation
                    Log::error('Certificate generation failed: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => $isUpdated
                    ? 'Data kegiatan pegawai berhasil diperbarui dan sertifikat digenerate ulang'
                    : 'Data kegiatan pegawai berhasil ditambahkan',
                'data' => $kegiatanPegawai,
            ], $isUpdated ? 200 : 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan data kegiatan pegawai',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified kegiatan pegawai.
     */
    public function show($id)
    {
        try {
            $kegiatanPegawai = KegiatanPegawai::with('kegiatan')->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Data kegiatan pegawai berhasil diambil',
                'data' => $kegiatanPegawai,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan pegawai tidak ditemukan',
            ], 404);
        }
    }

    /**
     * Update the specified kegiatan pegawai.
     */
    public function update(Request $request, $id)
    {
        try {
            $kegiatanPegawai = KegiatanPegawai::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'kegiatan_id' => 'sometimes|required|uuid|exists:kegiatan,id',
                'nip' => 'sometimes|required|string',
                'isi_form' => 'sometimes|required|array',
                'isi_form.nama_lengkap' => 'required_with:isi_form|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Update fields
            if ($request->has('kegiatan_id')) {
                $kegiatanPegawai->kegiatan_id = $request->kegiatan_id;
            }
            if ($request->has('nip')) {
                $kegiatanPegawai->nip = $request->nip;
            }
            if ($request->has('isi_form')) {
                $kegiatanPegawai->isi_form = $request->isi_form;
            }

            $kegiatanPegawai->save();

            // Regenerate certificate if significant data changed
            if ($request->has('kegiatan_id') || $request->has('nip') || $request->has('isi_form')) {
                try {
                    $this->certificateService->generateCertificate($kegiatanPegawai);
                } catch (\Exception $e) {
                    Log::error('Certificate generation failed: ' . $e->getMessage());
                }
            }

            // Load relationship
            $kegiatanPegawai->load('kegiatan');

            return response()->json([
                'success' => true,
                'message' => 'Data kegiatan pegawai berhasil diupdate',
                'data' => $kegiatanPegawai,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan pegawai tidak ditemukan',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate data kegiatan pegawai',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified kegiatan pegawai.
     */
    public function destroy($id)
    {
        try {
            $kegiatanPegawai = KegiatanPegawai::findOrFail($id);

            // Delete certificate file if exists
            if ($kegiatanPegawai->link_sertifikat) {
                $fullPath = storage_path('app/public/' . $kegiatanPegawai->link_sertifikat);
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }

            $kegiatanPegawai->delete();

            return response()->json([
                'success' => true,
                'message' => 'Data kegiatan pegawai berhasil dihapus',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan pegawai tidak ditemukan',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data kegiatan pegawai',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate certificate for a specific kegiatan pegawai.
     */
    public function regenerateCertificate($id)
    {
        try {
            $kegiatanPegawai = KegiatanPegawai::with('kegiatan')->findOrFail($id);
            $this->certificateService->generateCertificate($kegiatanPegawai);

            return response()->json([
                'success' => true,
                'message' => 'Sertifikat berhasil digenerate ulang',
                'data' => $kegiatanPegawai,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan pegawai tidak ditemukan',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Certificate regeneration failed: ', ['error' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate sertifikat',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload certificate(s) for one or multiple employees for a specific kegiatan.
     * Automatically creates a new kegiatan_pegawai record if not exists,
     * or updates the existing record with the new certificate file.
     */
    public function uploadCertificates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kegiatan_id' => 'required|uuid|exists:kegiatan,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $kegiatan = Kegiatan::findOrFail($request->kegiatan_id);

            // Parse employee list
            $rawPegawai = $request->input('pegawai');
            if (is_string($rawPegawai)) {
                $rawPegawai = json_decode($rawPegawai, true);
            }
            if (!is_array($rawPegawai) || empty($rawPegawai)) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Daftar pegawai yang dipilih tidak boleh kosong.',
                ], 422);
            }

            // Parse fallback profiles
            $rawProfiles = $request->input('pegawai_profiles');
            if (is_string($rawProfiles)) {
                $rawProfiles = json_decode($rawProfiles, true);
            }
            $frontendProfiles = is_array($rawProfiles) ? $rawProfiles : [];

            // Master file
            $masterFile = $request->file('file') ?: $request->file('sertifikat');
            $masterContent = null;
            $masterExt = 'pdf';
            if ($masterFile && $masterFile->isValid()) {
                $masterContent = file_get_contents($masterFile->getRealPath());
                $masterExt = strtolower($masterFile->getClientOriginalExtension() ?: 'pdf');
            }

            // Individual files
            $individualFiles = $request->file('files');

            if (!$masterContent && empty($individualFiles)) {
                return response()->json([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'File sertifikat wajib diunggah.',
                ], 422);
            }

            $createdCount = 0;
            $updatedCount = 0;
            $failedCount  = 0;
            $errors       = [];
            $results      = [];

            foreach ($rawPegawai as $idx => $item) {
                $nip = is_array($item) ? trim((string)($item['nip'] ?? '')) : trim((string)$item);

                if ($nip === '' || $nip === '-' || strtolower($nip) === 'umum' || strtolower($nip) === 'null') {
                    continue;
                }

                try {
                    // Check file for this employee
                    $fileContent = null;
                    $ext = 'pdf';

                    $empFile = null;
                    if (is_array($individualFiles)) {
                        if (isset($individualFiles[$nip])) {
                            $empFile = $individualFiles[$nip];
                        } elseif (isset($individualFiles[$idx])) {
                            $empFile = $individualFiles[$idx];
                        }
                    }
                    if (!$empFile && $request->hasFile("file_{$nip}")) {
                        $empFile = $request->file("file_{$nip}");
                    }

                    if ($empFile && $empFile->isValid()) {
                        $fileContent = file_get_contents($empFile->getRealPath());
                        $ext = strtolower($empFile->getClientOriginalExtension() ?: 'pdf');
                    } elseif ($masterContent !== null) {
                        $fileContent = $masterContent;
                        $ext = $masterExt;
                    }

                    if ($fileContent === null) {
                        throw new \Exception("File sertifikat untuk NIP {$nip} tidak valid atau tidak ditemukan.");
                    }

                    // Resolve employee profile
                    $fallbackProfile = is_array($item) ? $item : ($frontendProfiles[$nip] ?? null);
                    $profile = $this->resolvePegawaiProfile($nip, $fallbackProfile);

                    // Nomor sertifikat
                    $nomorSertifikat = null;
                    if (is_array($item) && !empty($item['nomor_sertifikat'])) {
                        $nomorSertifikat = trim((string)$item['nomor_sertifikat']);
                    } elseif ($request->filled('nomor_sertifikat')) {
                        $nomorSertifikat = trim((string)$request->nomor_sertifikat);
                    }

                    // Check existing record
                    $existingRecords = KegiatanPegawai::where('kegiatan_id', $kegiatan->id)
                        ->where(function ($q) use ($nip) {
                            $q->where('nip', $nip)
                              ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$nip]);
                        })
                        ->orderBy('created_at', 'asc')
                        ->get();

                    if ($existingRecords->isNotEmpty()) {
                        $kegiatanPegawai = $existingRecords->first();

                        // Clean duplicate rows if any
                        if ($existingRecords->count() > 1) {
                            foreach ($existingRecords->slice(1) as $dup) {
                                if ($dup->link_sertifikat && $dup->link_sertifikat !== $kegiatanPegawai->link_sertifikat) {
                                    if (Storage::disk('public')->exists($dup->link_sertifikat)) {
                                        Storage::disk('public')->delete($dup->link_sertifikat);
                                    }
                                }
                                $dup->delete();
                            }
                        }

                        $isUpdate = true;
                    } else {
                        $kegiatanPegawai = new KegiatanPegawai();
                        $kegiatanPegawai->id = (string) Str::uuid();
                        $kegiatanPegawai->kegiatan_id = $kegiatan->id;
                        $kegiatanPegawai->nip = $nip;
                        $isUpdate = false;
                    }

                    // Relative path for storage
                    $filename = "{$kegiatanPegawai->id}.{$ext}";
                    $relativePath = "certificates/{$filename}";

                    // If old file exists with different path, remove it
                    if ($isUpdate && !empty($kegiatanPegawai->link_sertifikat) && $kegiatanPegawai->link_sertifikat !== $relativePath) {
                        if (Storage::disk('public')->exists($kegiatanPegawai->link_sertifikat)) {
                            Storage::disk('public')->delete($kegiatanPegawai->link_sertifikat);
                        }
                    }

                    // Save file to storage
                    Storage::disk('public')->put($relativePath, $fileContent);
                    $kegiatanPegawai->link_sertifikat = $relativePath;
                    $kegiatanPegawai->setRelation('kegiatan', $kegiatan);

                    // Auto-assign sequence_number on new record if needed
                    if (empty($kegiatanPegawai->sequence_number)) {
                        $maxSeq = \Illuminate\Support\Facades\DB::table('kegiatan_pegawai')
                            ->where('kegiatan_id', $kegiatan->id)
                            ->max('sequence_number');
                        $kegiatanPegawai->sequence_number = ($maxSeq !== null) ? $maxSeq + 1 : 1;
                    }

                    // Nomor sertifikat
                    if (!empty($nomorSertifikat)) {
                        $kegiatanPegawai->nomor_sertifikat = $nomorSertifikat;
                    } elseif (empty($kegiatanPegawai->nomor_sertifikat)) {
                        $kegiatanPegawai->nomor_sertifikat = $this->certificateService->generateNomorSertifikat($kegiatan, $kegiatanPegawai);
                    }

                    // Update isi_form
                    if ($isUpdate) {
                        $existingIsiForm = is_array($kegiatanPegawai->isi_form) ? $kegiatanPegawai->isi_form : [];
                        $mergedIsiForm = array_merge($existingIsiForm, [
                            'nama_lengkap'   => (!empty($existingIsiForm['nama_lengkap']) && $existingIsiForm['nama_lengkap'] !== '-')
                                ? $existingIsiForm['nama_lengkap']
                                : ($profile['nama_lengkap'] ?: $nip),
                            'nip_no_absen'   => (!empty($existingIsiForm['nip_no_absen']) && $existingIsiForm['nip_no_absen'] !== '-')
                                ? $existingIsiForm['nip_no_absen']
                                : $nip,
                            'jabatan'        => (!empty($existingIsiForm['jabatan']) && $existingIsiForm['jabatan'] !== '-')
                                ? $existingIsiForm['jabatan']
                                : ($profile['jabatan'] ?: '-'),
                            'unit_kerja'     => (!empty($existingIsiForm['unit_kerja']) && $existingIsiForm['unit_kerja'] !== '-')
                                ? $existingIsiForm['unit_kerja']
                                : ($profile['unit_kerja'] ?: '-'),
                            'status_pegawai' => (!empty($existingIsiForm['status_pegawai']) && $existingIsiForm['status_pegawai'] !== '-')
                                ? $existingIsiForm['status_pegawai']
                                : ($profile['status_pegawai'] ?: '-'),
                        ]);
                        if (!empty($kegiatanPegawai->nomor_sertifikat)) {
                            $mergedIsiForm['nomor_sertifikat'] = $kegiatanPegawai->nomor_sertifikat;
                        }
                        $kegiatanPegawai->isi_form = $mergedIsiForm;
                    } else {
                        $newIsiForm = [
                            'nama_lengkap'     => $profile['nama_lengkap'] ?: $nip,
                            'nip_no_absen'     => $nip,
                            'jabatan'          => $profile['jabatan'] ?: '-',
                            'unit_kerja'       => $profile['unit_kerja'] ?: '-',
                            'status_pegawai'   => $profile['status_pegawai'] ?: '-',
                            'nomor_sertifikat' => $kegiatanPegawai->nomor_sertifikat ?: '',
                        ];
                        $kegiatanPegawai->isi_form = $newIsiForm;
                    }

                    $kegiatanPegawai->save();

                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $createdCount++;
                    }

                    $results[] = [
                        'id'               => $kegiatanPegawai->id,
                        'nip'              => $nip,
                        'nama_lengkap'     => $kegiatanPegawai->isi_form['nama_lengkap'] ?? $nip,
                        'link_sertifikat'  => $kegiatanPegawai->link_sertifikat,
                        'nomor_sertifikat' => $kegiatanPegawai->nomor_sertifikat,
                        'is_update'        => $isUpdate,
                    ];
                } catch (\Exception $e) {
                    $failedCount++;
                    $errors[] = [
                        'nip'     => $nip,
                        'message' => $e->getMessage(),
                    ];
                    Log::error("Gagal upload sertifikat untuk NIP {$nip}: " . $e->getMessage());
                }
            }

            $totalProcessed = $createdCount + $updatedCount;

            return response()->json([
                'success'         => $totalProcessed > 0,
                'status'          => $totalProcessed > 0 ? 'success' : 'error',
                'message'         => $totalProcessed > 0
                    ? "Berhasil mengunggah sertifikat untuk {$totalProcessed} pegawai ({$createdCount} data baru ditambahkan, {$updatedCount} sertifikat diperbarui)."
                    : 'Gagal memproses upload sertifikat.',
                'created_count'   => $createdCount,
                'updated_count'   => $updatedCount,
                'failed_count'    => $failedCount,
                'total_processed' => $totalProcessed,
                'errors'          => $errors,
                'data'            => $results,
            ], $totalProcessed > 0 ? 200 : 500);

        } catch (\Exception $e) {
            Log::error('Upload certificates failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
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
            $cmbController = app(\App\Http\Controllers\CmbApiController::class);
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

}
