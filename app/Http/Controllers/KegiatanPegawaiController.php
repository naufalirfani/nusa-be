<?php

namespace App\Http\Controllers;

use App\Models\KegiatanPegawai;
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
            // Create kegiatan pegawai
            $kegiatanPegawai = KegiatanPegawai::create([
                'kegiatan_id' => $request->kegiatan_id,
                'nip' => $request->nip,
                'isi_form' => $request->isi_form,
            ]);

            // Generate certificate
            try {
                $this->certificateService->generateCertificate($kegiatanPegawai);
            } catch (\Exception $e) {
                // Log error but don't fail the creation
                Log::error('Certificate generation failed: ' . $e->getMessage());
            }

            // Load relationship
            $kegiatanPegawai->load('kegiatan');

            return response()->json([
                'success' => true,
                'message' => 'Data kegiatan pegawai berhasil ditambahkan',
                'data' => $kegiatanPegawai,
            ], 201);
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
}
