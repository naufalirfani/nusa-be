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
            'penilai.*.role' => 'nullable|string|max:20',
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

            PenilaianPegawai::where('periode', '=', $periode, 'and')
                ->where('nip_pegawai', '=', $nipPegawai, 'and')
                ->whereNotIn('nip_penilai', $incomingNips)
                ->delete();

            foreach ($mapping as $nipPenilai => $role) {
                PenilaianPegawai::updateOrCreate(
                    [
                        'periode' => $periode,
                        'nip_pegawai' => $nipPegawai,
                        'nip_penilai' => $nipPenilai,
                    ],
                    [
                        'role' => $role,
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Sinkronisasi penilaian pegawai berhasil',
            ]);
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
                'role' => 'sometimes|required|string|max:20',
                'penilaian' => 'sometimes|nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($request->has('periode')) {
                $periode = $this->normalizePeriode($request->get('periode'));
                if ($periode === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Format periode tidak valid. Gunakan YYYY-MM atau MM-YYYY.',
                    ], 422);
                }

                $data->periode = $periode;
            }

            if ($request->has('nip_pegawai')) {
                $data->nip_pegawai = $request->get('nip_pegawai');
            }
            if ($request->has('nip_penilai')) {
                $data->nip_penilai = $request->get('nip_penilai');
            }
            if ($request->has('role')) {
                $data->role = $request->get('role');
            }
            if ($request->has('penilaian')) {
                $data->penilaian = $request->get('penilaian');
            }

            $data->save();

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
            $data->delete();

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
}
