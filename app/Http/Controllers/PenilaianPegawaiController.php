<?php

namespace App\Http\Controllers;

use App\Models\PenilaianPegawai;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PenilaianPegawaiController extends Controller
{
    /**
     * List data penilaian pegawai dengan search dan filter.
     */
    public function index(Request $request)
    {
        $query = PenilaianPegawai::query();
        $withPagination = $request->boolean('with_pagination', true);
        $onlyLatestPeriode = $request->boolean('only_latest_periode', false);

        if ($onlyLatestPeriode) {
            $latestPeriode = PenilaianPegawai::select('periode')
                ->orderBy('periode', 'desc')
                ->first();

            if ($latestPeriode) {
                $query->where('periode', $latestPeriode->periode);
            }
        }

        if ($request->filled('periode')) {
            $query->where('periode', $this->normalizePeriode($request->get('periode')));
        }

        if ($request->filled('nip_pegawai')) {
            $query->where('nip_pegawai', $request->get('nip_pegawai'));
        }

        if ($request->filled('nip_penilai')) {
            $query->where('nip_penilai', $request->get('nip_penilai'));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->get('role'));
        }

        if ($request->filled('active')) {
            $query->where('active', $request->get('active'));
        }

        if ($request->filled('status_penilaian')) {
            $status = $request->get('status_penilaian');
            $questions = $this->getTemplateQuestions();
            $all = $questions['all'];
            $required = $questions['required'];

            $driver = DB::connection()->getDriverName();

            if ($status === 'belum') { // Belum dinilai (empty)
                $query->where(function ($q) use ($all, $driver) {
                    if ($driver === 'pgsql') {
                        $q->whereNull('penilaian')
                            ->orWhereRaw("CAST(penilaian AS text) = ''")
                            ->orWhereRaw("CAST(penilaian AS text) = '[]'")
                            ->orWhereRaw("CAST(penilaian AS text) = '{}'");
                    } else {
                        $q->whereNull('penilaian')
                            ->orWhere('penilaian', '')
                            ->orWhere('penilaian', '[]')
                            ->orWhere('penilaian', '{}');
                    }

                    if (!empty($all)) {
                        $q->where(function ($sub) use ($all) {
                            foreach ($all as $field) {
                                $sub->whereNull("penilaian->{$field}");
                            }
                        });
                    }
                });
            } elseif ($status === 'selesai') { // Selesai (complete)
                if ($driver === 'pgsql') {
                    $query->whereNotNull('penilaian')
                        ->whereRaw("CAST(penilaian AS text) != ''")
                        ->whereRaw("CAST(penilaian AS text) != '[]'")
                        ->whereRaw("CAST(penilaian AS text) != '{}'");
                } else {
                    $query->whereNotNull('penilaian')
                        ->where('penilaian', '!=', '')
                        ->where('penilaian', '!=', '[]')
                        ->where('penilaian', '!=', '{}');
                }
                foreach ($required as $field) {
                    $query->whereNotNull("penilaian->{$field}");
                }
            } elseif ($status === 'partial' || $status === 'belum_selesai') { // Belum selesai (partial)
                if ($driver === 'pgsql') {
                    $query->whereNotNull('penilaian')
                        ->whereRaw("CAST(penilaian AS text) != ''")
                        ->whereRaw("CAST(penilaian AS text) != '[]'")
                        ->whereRaw("CAST(penilaian AS text) != '{}'");
                } else {
                    $query->whereNotNull('penilaian')
                        ->where('penilaian', '!=', '')
                        ->where('penilaian', '!=', '[]')
                        ->where('penilaian', '!=', '{}');
                }

                if (!empty($all)) {
                    $query->where(function ($q) use ($all) {
                        foreach ($all as $field) {
                            $q->orWhereNotNull("penilaian->{$field}");
                        }
                    });
                }

                if (!empty($required)) {
                    $query->where(function ($q) use ($required) {
                        foreach ($required as $field) {
                            $q->orWhereNull("penilaian->{$field}");
                        }
                    });
                }
            }
        }

        if ($request->filled('search')) {
            $search = strtolower($request->get('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(periode) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(nip_pegawai) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(nip_penilai) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(role) LIKE ?', ["%{$search}%"]);
            });
        }

        $query->orderBy('created_at', 'desc');

        $data = $withPagination
            ? $query->paginate($request->integer('per_page', 25))
            : $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data penilaian pegawai berhasil diambil',
            'data' => $data,
        ]);
    }

    /**
     * Sinkronisasi list nip_penilai untuk periode + nip_pegawai.
     * Row yang tidak ada di payload akan dihapus, row baru akan ditambahkan.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'periode' => 'required|string',
            'nip_pegawai' => 'required|string|max:20',
            'role' => 'nullable|string|max:20',
            'nip_penilai' => 'nullable|array|min:1',
            'nip_penilai.*' => 'required_with:nip_penilai|string|max:20',
            'role_mapping' => 'nullable|array',
            'penilai' => 'nullable|array|min:1',
            'penilai.*.nip_penilai' => 'required_with:penilai|string|max:20',
            'penilai.*.role' => 'nullable|string|max:25',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $periode = $this->normalizePeriode($request->get('periode'));
        if ($periode === null) {
            return response()->json([
                'success' => false,
                'message' => 'Format periode tidak valid. Gunakan YYYY-MM atau MM-YYYY.',
            ], 422);
        }

        $this->checkAndDeactivateOldPeriode($periode);

        $mapping = $this->buildRoleMapping($request);
        if (count($mapping) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Daftar nip_penilai wajib diisi.',
            ], 422);
        }

        $nipPegawai = $request->get('nip_pegawai');

        DB::transaction(function () use ($periode, $nipPegawai, $mapping) {
            $incomingNips = array_keys($mapping);

            // Bulk select existing records to minimize queries
            $existingRecords = PenilaianPegawai::withTrashed()
                ->where('periode', $periode)
                ->where('nip_pegawai', $nipPegawai)
                ->get()
                ->keyBy('nip_penilai');

            // Bulk delete non-existing records
            PenilaianPegawai::where('periode', $periode)
                ->where('nip_pegawai', $nipPegawai)
                ->whereNotIn('nip_penilai', $incomingNips)
                ->delete();

            foreach ($mapping as $nipPenilai => $role) {
                $existing = $existingRecords->get($nipPenilai);
                if ($existing) {
                    $wasTrashed = $existing->trashed();
                    if ($wasTrashed) {
                        $existing->restore();
                    }
                    $newActive = $existing->active ? true : false;
                    $updateData = [
                        'role' => $role,
                        'active' => $newActive,
                    ];
                    if ($existing->is_manual !== false) {
                        $updateData['is_manual'] = true;
                    }
                    if ($wasTrashed || $existing->role !== $role || $existing->active !== $newActive || ($existing->is_manual !== false && !$existing->is_manual)) {
                        $existing->update($updateData);
                    }
                } else {
                    PenilaianPegawai::create([
                        'periode' => $periode,
                        'nip_pegawai' => $nipPegawai,
                        'nip_penilai' => $nipPenilai,
                        'role' => $role,
                        'active' => false,
                        'is_manual' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Sinkronisasi penilaian pegawai berhasil',
        ], 201);
    }

    /**
     * Tampilkan detail 1 row.
     */
    public function show(string $id)
    {
        try {
            $data = PenilaianPegawai::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Data penilaian pegawai berhasil diambil',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data penilaian pegawai tidak ditemukan',
            ], 404);
        }
    }

    /**
     * Update 1 row penilaian_pegawai (CRUD standar).
     */
    public function update(Request $request, string $id)
    {
        try {
            $data = PenilaianPegawai::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'periode' => 'sometimes|required|string',
                'nip_pegawai' => 'sometimes|required|string|max:20',
                'nip_penilai' => 'sometimes|required|string|max:20',
                'role' => 'sometimes|required|string|max:25',
                'penilaian' => 'sometimes|nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $oldNipPenilai = $data->nip_penilai;
            $oldNipPegawai = $data->nip_pegawai;
            $oldPeriode = $data->periode;
            $oldIsManual = $data->is_manual;
            $oldRole = $data->role;

            $keyChanged = false;
            $periodeChanged = false;
            if ($request->has('periode')) {
                $periode = $this->normalizePeriode($request->get('periode'));
                if ($periode === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Format periode tidak valid. Gunakan YYYY-MM atau MM-YYYY.',
                    ], 422);
                }

                if ($periode !== $data->periode) {
                    $periodeChanged = true;
                    $keyChanged = true;
                    $this->checkAndDeactivateOldPeriode($periode);
                }
                $data->periode = $periode;
            }

            if ($request->has('nip_pegawai')) {
                if ($request->get('nip_pegawai') !== $oldNipPegawai) {
                    $keyChanged = true;
                }
                $data->nip_pegawai = $request->get('nip_pegawai');
            }
            if ($request->has('nip_penilai')) {
                if ($request->get('nip_penilai') !== $oldNipPenilai) {
                    $keyChanged = true;
                }
                $data->nip_penilai = $request->get('nip_penilai');
            }
            if ($request->has('role')) {
                $data->role = $request->get('role');
            }
            if ($request->has('penilaian')) {
                $data->penilaian = $request->get('penilaian');
            }

            if ($periodeChanged) {
                $data->active = false;
            } else {
                $data->active = $data->active ? true : false;
            }

            if ($data->is_manual !== false) {
                $data->is_manual = true;
            }

            $data->save();

            if ($keyChanged && $oldIsManual === false) {
                // Create a soft-deleted marker for the old key combination
                $marker = new PenilaianPegawai([
                    'periode' => $oldPeriode,
                    'nip_pegawai' => $oldNipPegawai,
                    'nip_penilai' => $oldNipPenilai,
                    'role' => $oldRole,
                    'active' => false,
                    'is_manual' => false,
                ]);
                $marker->save();
                $marker->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Data penilaian pegawai berhasil diupdate',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data penilaian pegawai tidak ditemukan',
            ], 404);
        }
    }

    /**
     * Hapus 1 row.
     */
    public function destroy(string $id)
    {
        try {
            $data = PenilaianPegawai::findOrFail($id);
            $data->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Data penilaian pegawai berhasil dihapus',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data penilaian pegawai tidak ditemukan',
            ], 404);
        }
    }

    /**
     * API khusus input penilaian.
     */
    public function inputPenilaian(Request $request, string $id)
    {
        try {
            $data = PenilaianPegawai::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'penilaian' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data->penilaian = $request->get('penilaian');
            $data->save();

            return response()->json([
                'success' => true,
                'message' => 'Input penilaian berhasil disimpan',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data penilaian pegawai tidak ditemukan',
            ], 404);
        }
    }

    /**
     * Generate PenilaianPegawai untuk semua pegawai berdasarkan params.
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'periode' => 'required|string',
            'q' => 'nullable|string',
            'unit_organisasi_id' => 'nullable|integer',
            'jabatan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $periode = $this->normalizePeriode($request->get('periode'));
        if ($periode === null) {
            return response()->json([
                'success' => false,
                'message' => 'Format periode tidak valid. Gunakan YYYY-MM atau MM-YYYY.',
            ], 422);
        }

        // Check and deactivate old periods if this period is different
        $this->checkAndDeactivateOldPeriode($periode);

        // Fetch pegawai from CmbApiController
        try {
            $cmbController = app(CmbApiController::class);
            $cmbRequest = new Request([
                'include_json' => 'false',
                'with_pagination' => 'false',
                'with_pegawai_360' => 'true',
                'q' => $request->get('q'),
                'unit_organisasi_id' => $request->get('unit_organisasi_id'),
                'jabatan' => $request->get('jabatan'),
            ]);

            $cmbResponse = $cmbController->getPegawai($cmbRequest);

            if ($cmbResponse->getStatusCode() !== 200) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengambil data pegawai dari CMB API',
                    'error' => json_decode($cmbResponse->getContent(), true),
                ], $cmbResponse->getStatusCode());
            }

            $pegawaiData = json_decode($cmbResponse->getContent(), true);
            $pegawais = $pegawaiData['data'] ?? [];
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memanggil CMB API',
                'error' => $e->getMessage(),
            ], 500);
        }

        $desired = [];
        $allNipPegawais = [];

        foreach ($pegawais as $pegawai) {
            if (empty($pegawai['nip'])) {
                continue;
            }
            $nipPegawai = $pegawai['nip'];
            $allNipPegawais[] = $nipPegawai;

            $mapping = [];

            // Atasan -> Atasan Langsung
            if (!empty($pegawai['atasan']) && is_array($pegawai['atasan'])) {
                foreach ($pegawai['atasan'] as $item) {
                    if (!empty($item['nip'])) {
                        $mapping[$item['nip']] = 'Atasan Langsung';
                    }
                }
            }

            // Rekan Kerja -> Rekan Kerja
            if (!empty($pegawai['rekan_kerja']) && is_array($pegawai['rekan_kerja'])) {
                foreach ($pegawai['rekan_kerja'] as $item) {
                    if (!empty($item['nip'])) {
                        $mapping[$item['nip']] = 'Rekan Kerja';
                    }
                }
            }

            // Bawahan -> Bawahan
            if (!empty($pegawai['bawahan']) && is_array($pegawai['bawahan'])) {
                foreach ($pegawai['bawahan'] as $item) {
                    if (!empty($item['nip'])) {
                        $mapping[$item['nip']] = 'Bawahan';
                    }
                }
            }

            // Diri Sendiri -> Diri Sendiri
            if (!empty($pegawai['diri_sendiri'])) {
                $ds = $pegawai['diri_sendiri'];
                if (is_array($ds) && !empty($ds['nip'])) {
                    $mapping[$ds['nip']] = 'Diri Sendiri';
                }
            }

            // Penerima Manfaat -> Penerima Manfaat Kerja
            if (!empty($pegawai['penerima_manfaat'])) {
                if (is_array($pegawai['penerima_manfaat'])) {
                    if (isset($pegawai['penerima_manfaat']['nip'])) {
                        $mapping[$pegawai['penerima_manfaat']['nip']] = 'Penerima Manfaat Kerja';
                    } else {
                        foreach ($pegawai['penerima_manfaat'] as $item) {
                            if (is_array($item) && !empty($item['nip'])) {
                                $mapping[$item['nip']] = 'Penerima Manfaat Kerja';
                            }
                        }
                    }
                }
            }

            $desired[$nipPegawai] = $mapping;
        }

        $count = count($allNipPegawais);

        DB::transaction(function () use ($periode, $desired, $allNipPegawais) {
            if (!empty($desired)) {
                $desiredChunks = array_chunk($desired, 100, true);
                foreach ($desiredChunks as $chunk) {
                    PenilaianPegawai::where('periode', $periode)
                        ->where(function ($q) use ($chunk) {
                            foreach ($chunk as $nipPegawai => $penilais) {
                                $incomingNips = array_keys($penilais);
                                $q->orWhere(function ($sub) use ($nipPegawai, $incomingNips) {
                                    $sub->where('nip_pegawai', $nipPegawai)
                                        ->where('is_manual', false);
                                    if (!empty($incomingNips)) {
                                        $sub->whereNotIn('nip_penilai', $incomingNips);
                                    }
                                });
                            }
                        })
                        ->forceDelete();
                }
            }

            // Bulk select existing records to minimize queries
            $existingRecords = PenilaianPegawai::withTrashed()
                ->where('periode', $periode)
                ->whereIn('nip_pegawai', $allNipPegawais)
                ->get()
                ->groupBy(function ($item) {
                    return $item->nip_pegawai . '_' . $item->nip_penilai;
                });

            $newRecords = [];
            $now = now();

            foreach ($desired as $nipPegawai => $penilais) {
                foreach ($penilais as $nipPenilai => $role) {
                    $key = $nipPegawai . '_' . $nipPenilai;
                    $existing = $existingRecords->get($key)?->first();

                    if ($existing) {
                        if ($existing->trashed()) {
                            continue;
                        }
                        $newActive = $existing->active ? true : false;
                        if ($existing->role !== $role || $existing->active !== $newActive) {
                            $existing->update([
                                'role' => $role,
                                'active' => $newActive,
                            ]);
                        }
                    } else {
                        $newRecords[] = [
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'periode' => $periode,
                            'nip_pegawai' => $nipPegawai,
                            'nip_penilai' => $nipPenilai,
                            'role' => $role,
                            'active' => false,
                            'is_manual' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            if (!empty($newRecords)) {
                foreach (array_chunk($newRecords, 500) as $chunk) {
                    PenilaianPegawai::insert($chunk);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Penilaian pegawai berhasil digenerate untuk {$count} pegawai.",
            'count' => $count,
        ]);
    }

    /**
     * Set active = true untuk semua PenilaianPegawai periode terbaru.
     */
    public function activateLatestPeriode()
    {
        $latestPeriode = PenilaianPegawai::max('periode');

        if (!$latestPeriode) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data penilaian pegawai di database.',
            ], 404);
        }

        DB::transaction(function () use ($latestPeriode) {
            // Deactivate all other periods
            PenilaianPegawai::where('periode', '!=', $latestPeriode)
                ->where('active', true)
                ->update(['active' => false]);

            // Activate latest period
            PenilaianPegawai::where('periode', $latestPeriode)
                ->update(['active' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => "Penilaian pegawai untuk periode terbaru ({$latestPeriode}) berhasil diaktifkan.",
            'periode' => $latestPeriode,
        ]);
    }

    private function checkAndDeactivateOldPeriode(string $periode): void
    {
        $latestPeriode = PenilaianPegawai::max('periode');
        if ($latestPeriode && $latestPeriode !== $periode) {
            PenilaianPegawai::where('active', true)->update(['active' => false]);
        }
    }

    private function normalizePeriode(?string $periode): ?string
    {
        if ($periode === null) {
            return null;
        }

        $periode = trim($periode);

        // YYYY-MM
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periode, $matches) === 1) {
            return $matches[1] . '-' . $matches[2];
        }

        // MM-YYYY
        if (preg_match('/^(0[1-9]|1[0-2])-(\d{4})$/', $periode, $matches) === 1) {
            return $matches[2] . '-' . $matches[1];
        }

        return null;
    }

    private function buildRoleMapping(Request $request): array
    {
        $result = [];

        if ($request->filled('penilai') && is_array($request->get('penilai'))) {
            foreach ($request->get('penilai') as $item) {
                if (!is_array($item) || empty($item['nip_penilai'])) {
                    continue;
                }

                $nip = (string) $item['nip_penilai'];
                $role = isset($item['role']) && $item['role'] !== ''
                    ? (string) $item['role']
                    : (string) $request->get('role', 'penilai');

                $result[$nip] = $role;
            }

            return $result;
        }

        $defaultRole = (string) $request->get('role', 'penilai');
        $roleMapping = $request->get('role_mapping', []);
        $nipPenilai = $request->get('nip_penilai', []);

        if (!is_array($nipPenilai)) {
            return [];
        }

        foreach ($nipPenilai as $nip) {
            $nip = (string) $nip;
            if ($nip === '') {
                continue;
            }

            $mappedRole = is_array($roleMapping) && isset($roleMapping[$nip]) && $roleMapping[$nip] !== ''
                ? (string) $roleMapping[$nip]
                : $defaultRole;

            $result[$nip] = $mappedRole;
        }

        return $result;
    }

    private function getTemplateQuestions(): array
    {
        $file = 'feedback-template.json';
        $template = null;
        if (\Illuminate\Support\Facades\Storage::exists($file)) {
            $template = json_decode(\Illuminate\Support\Facades\Storage::get($file), true);
        }

        if (!$template || !isset($template['pages']) || !is_array($template['pages'])) {
            return [
                'all' => ['kinerja_utama', 'komunikasi', 'kolaborasi', 'inisiatif', 'tanggung_jawab', 'catatan_tambahan'],
                'required' => ['kinerja_utama', 'komunikasi', 'kolaborasi', 'inisiatif', 'tanggung_jawab']
            ];
        }

        $all = [];
        $required = [];
        $skipTypes = ['html', 'image', 'panel', 'expression'];

        foreach ($template['pages'] as $page) {
            if (isset($page['elements']) && is_array($page['elements'])) {
                foreach ($page['elements'] as $element) {
                    if (empty($element['name']) || (!empty($element['readOnly']) && $element['readOnly'])) {
                        continue;
                    }
                    if (in_array($element['type'] ?? '', $skipTypes)) {
                        continue;
                    }
                    $name = $element['name'];
                    $all[] = $name;
                    if (!empty($element['isRequired'])) {
                        $required[] = $name;
                    }
                }
            }
        }

        return [
            'all' => $all,
            'required' => $required
        ];
    }
}
