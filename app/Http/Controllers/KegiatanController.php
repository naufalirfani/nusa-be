<?php

namespace App\Http\Controllers;

use App\Models\Kegiatan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class KegiatanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Kegiatan::query();

        // Search across multiple columns (nama_kegiatan, judul_tema, deskripsi, tempat) - case-insensitive
        if ($request->filled('search')) {
            $s = mb_strtolower($request->get('search'));
            $query->where(function ($q) use ($s) {
                $q->whereRaw('LOWER(nama_kegiatan) LIKE ?', ["%{$s}%"])
                  ->orWhereRaw('LOWER(judul_tema) LIKE ?', ["%{$s}%"])
                  ->orWhereRaw('LOWER(deskripsi) LIKE ?', ["%{$s}%"])
                  ->orWhereRaw('LOWER(tempat) LIKE ?', ["%{$s}%"]);
            });
        }

        // Filters
        if ($request->filled('jenis_kegiatan')) {
            $query->where('jenis_kegiatan', $request->get('jenis_kegiatan'));
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal', $request->get('tanggal'));
        } else {
            if ($request->filled('tanggal_from')) {
                $query->whereDate('tanggal', '>=', $request->get('tanggal_from'));
            }
            if ($request->filled('tanggal_to')) {
                $query->whereDate('tanggal', '<=', $request->get('tanggal_to'));
            }
        }

        if ($request->filled('jam_mulai')) {
            $query->where('jam_mulai', $request->get('jam_mulai'));
        } else {
            if ($request->filled('jam_mulai_from')) {
                $query->where('jam_mulai', '>=', $request->get('jam_mulai_from'));
            }
            if ($request->filled('jam_mulai_to')) {
                $query->where('jam_mulai', '<=', $request->get('jam_mulai_to'));
            }
        }

        if ($request->filled('jam_selesai')) {
            $query->where('jam_selesai', $request->get('jam_selesai'));
        } else {
            if ($request->filled('jam_selesai_from')) {
                $query->where('jam_selesai', '>=', $request->get('jam_selesai_from'));
            }
            if ($request->filled('jam_selesai_to')) {
                $query->where('jam_selesai', '<=', $request->get('jam_selesai_to'));
            }
        }

        // Filter whether desain_sertifikat exists (butuh_sertifikat=true) or is null
        if ($request->has('butuh_sertifikat')) {
            $val = $request->get('butuh_sertifikat');
            if ($val === '1' || $val === 'true' || $val === 1 || $val === true) {
                $query->whereNotNull('desain_sertifikat');
            } elseif ($val === '0' || $val === 'false' || $val === 0 || $val === false) {
                $query->whereNull('desain_sertifikat');
            }
        }

        // Sorting berdasarkan parameter
        $sort = $request->get('sort', 'newest');

        if ($sort === 'ongoing') {
            // Untuk PostgreSQL: gabungkan date+time menjadi timestamp dan gunakan EXTRACT(EPOCH FROM ...) untuk selisih detik
            $query->selectRaw('*, (tanggal + jam_mulai) as datetime_mulai, (tanggal + jam_selesai) as datetime_selesai')
                ->orderByRaw(
                    "CASE
                        WHEN (tanggal + jam_selesai) >= now() THEN
                            ABS(EXTRACT(EPOCH FROM (tanggal + jam_mulai - now())))
                        ELSE
                            ABS(EXTRACT(EPOCH FROM (tanggal + jam_selesai - now()))) + 999999999
                    END"
                );
        } else {
            // Default: urutkan berdasarkan created_at terbaru
            $query->orderBy('created_at', 'desc');
        }

        $kegiatan = $query->get();

        // Tambahkan URL lengkap untuk banner
        $kegiatan->transform(function ($item) {
            if ($item->banner) {
                $item->banner_url = url('storage/' . $item->banner);
            }
            if ($item->materi) {
                $item->materi_url = url('storage/' . $item->materi);
            }
            if ($item->virtual_background) {
                $item->virtual_background_url = url('storage/' . $item->virtual_background);
            }
            if ($item->template_sertifikat) {
                $item->template_sertifikat_url = url('storage/' . $item->template_sertifikat);
            }
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $kegiatan
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $useExistingTemplate = $request->boolean('useExistingTemplate');

        $validator = Validator::make($request->all(), [
            'banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'materi' => 'nullable',
            'virtual_background' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'template_sertifikat' => $useExistingTemplate ? 'nullable|string|max:500' : 'nullable|file|max:5120',
            'jenis_kegiatan' => 'required|string|max:100',
            'nama_kegiatan' => 'required|string|max:255',
            'judul_tema' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'narasumber' => 'required|string|max:255',
            'asal_narasumber' => 'required|in:Internal,Eksternal',
            'moderator' => 'nullable|string|max:255',
            'asal_moderator' => 'nullable|in:Internal,Eksternal',
            'tempat' => 'required|string|max:255',
            'tanggal' => 'required|date',
            'jam_mulai' => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i|after:jam_mulai',
            'linktree' => 'nullable|string|max:15|unique:kegiatan,linktree',
            'youtube' => 'nullable|url|max:255',
            'desain_sertifikat' => 'nullable|string',
            'form_evaluasi' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['banner', 'materi', 'virtual_background', 'template_sertifikat']);

        // Handle desain_sertifikat JSON dari form-data
        if ($request->has('desain_sertifikat')) {
            $desainSertifikat = $request->input('desain_sertifikat');
            if (is_string($desainSertifikat)) {
                $decoded = json_decode($desainSertifikat, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['desain_sertifikat'] = $decoded;
                } else {
                    return response()->json([
                        'success' => false,
                        'errors' => ['desain_sertifikat' => ['Format JSON tidak valid']]
                    ], 422);
                }
            }
        }

        // Handle form_evaluasi JSON dari form-data
        if ($request->has('form_evaluasi')) {
            $formEvaluasi = $request->input('form_evaluasi');
            if (is_string($formEvaluasi)) {
                $decoded = json_decode($formEvaluasi, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['form_evaluasi'] = $decoded;
                } else {
                    return response()->json([
                        'success' => false,
                        'errors' => ['form_evaluasi' => ['Format JSON tidak valid']]
                    ], 422);
                }
            }
        }

        // Handle template_sertifikat
        if ($request->hasFile('template_sertifikat')) {
            // Upload file baru
            $file = $request->file('template_sertifikat');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/template_sertifikat', $filename, 'public');
            $data['template_sertifikat'] = $path;
        } elseif ($useExistingTemplate && $request->filled('template_sertifikat')) {
            // Gunakan path yang sudah ada (template default atau template yang pernah diupload)
            // Path dikirim tanpa prefix /storage/, e.g. "kegiatan/template_sertifikat/file.pptx"
            $templatePath = $request->input('template_sertifikat');
            // Cegah path traversal
            if (str_contains($templatePath, '..')) {
                return response()->json(['success' => false, 'message' => 'Path template tidak valid'], 422);
            }
            $data['template_sertifikat'] = $templatePath;
        }

        // Upload banner jika ada
        if ($request->hasFile('banner')) {
            $file = $request->file('banner');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/banners', $filename, 'public');
            $data['banner'] = $path;
        }

        // Upload materi jika ada
        if ($request->hasFile('materi')) {
            $file = $request->file('materi');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/materi', $filename, 'public');
            $data['materi'] = $path;
        }

        // Upload virtual_background jika ada
        if ($request->hasFile('virtual_background')) {
            $file = $request->file('virtual_background');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/virtual_backgrounds', $filename, 'public');
            $data['virtual_background'] = $path;
        }

        $kegiatan = Kegiatan::create($data);

        // Tambahkan URL banner
        if ($kegiatan->banner) {
            $kegiatan->banner_url = url('storage/' . $kegiatan->banner);
        }
        if ($kegiatan->materi) {
            $kegiatan->materi_url = url('storage/' . $kegiatan->materi);
        }
        if ($kegiatan->virtual_background) {
            $kegiatan->virtual_background_url = url('storage/' . $kegiatan->virtual_background);
        }
        if ($kegiatan->template_sertifikat) {
            $kegiatan->template_sertifikat_url = url('storage/' . $kegiatan->template_sertifikat);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kegiatan berhasil ditambahkan',
            'data' => $kegiatan
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $kegiatan = Kegiatan::find($id);

        if (!$kegiatan) {
            return response()->json([
                'success' => false,
                'message' => 'Kegiatan tidak ditemukan'
            ], 404);
        }

        // Tambahkan URL banner
        if ($kegiatan->banner) {
            $kegiatan->banner_url = url('storage/' . $kegiatan->banner);
        }
        if ($kegiatan->materi) {
            $kegiatan->materi_url = url('storage/' . $kegiatan->materi);
        }
        if ($kegiatan->virtual_background) {
            $kegiatan->virtual_background_url = url('storage/' . $kegiatan->virtual_background);
        }
        if ($kegiatan->template_sertifikat) {
            $kegiatan->template_sertifikat_url = url('storage/' . $kegiatan->template_sertifikat);
        }

        return response()->json([
            'success' => true,
            'data' => $kegiatan
        ]);
    }

        public function showByLinktree(string $linktree)
    {
        $kegiatan = Kegiatan::where('linktree', $linktree)->first();

        if (!$kegiatan) {
            return response()->json([
                'success' => false,
                'message' => 'Kegiatan tidak ditemukan'
            ], 404);
        }

        // Tambahkan URL banner
        if ($kegiatan->banner) {
            $kegiatan->banner_url = url('storage/' . $kegiatan->banner);
        }
        if ($kegiatan->materi) {
            $kegiatan->materi_url = url('storage/' . $kegiatan->materi);
        }
        if ($kegiatan->virtual_background) {
            $kegiatan->virtual_background_url = url('storage/' . $kegiatan->virtual_background);
        }
        if ($kegiatan->template_sertifikat) {
            $kegiatan->template_sertifikat_url = url('storage/' . $kegiatan->template_sertifikat);
        }

        return response()->json([
            'success' => true,
            'data' => $kegiatan
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $kegiatan = Kegiatan::find($id);

        if (!$kegiatan) {
            return response()->json([
                'success' => false,
                'message' => 'Kegiatan tidak ditemukan'
            ], 404);
        }

        $useExistingTemplate = $request->boolean('useExistingTemplate');

        $validator = Validator::make($request->all(), [
            'banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'materi' => 'sometimes|nullable',
            'virtual_background' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'template_sertifikat' => $useExistingTemplate ? 'sometimes|nullable|string|max:500' : 'sometimes|nullable|file|max:5120',
            'jenis_kegiatan' => 'sometimes|required|string|max:100',
            'nama_kegiatan' => 'sometimes|required|string|max:255',
            'judul_tema' => 'sometimes|required|string|max:255',
            'deskripsi' => 'sometimes|nullable|string',
            'narasumber' => 'sometimes|required|string|max:255',
            'asal_narasumber' => 'sometimes|required|in:Internal,Eksternal',
            'moderator' => 'sometimes|nullable|string|max:255',
            'asal_moderator' => 'sometimes|nullable|in:Internal,Eksternal',
            'tempat' => 'sometimes|required|string|max:255',
            'tanggal' => 'sometimes|required|date',
            'jam_mulai' => 'sometimes|required|date_format:H:i',
            'jam_selesai' => 'sometimes|required|date_format:H:i|after:jam_mulai',
            'linktree' => 'sometimes|nullable|string|max:15|unique:kegiatan,linktree,'.$id.',id',
            'youtube' => 'sometimes|nullable|url|max:255',
            'desain_sertifikat' => 'sometimes|nullable|string',
            'form_evaluasi' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['banner', 'materi', 'virtual_background', 'template_sertifikat']);

        // Handle desain_sertifikat JSON dari form-data
        if ($request->has('desain_sertifikat')) {
            $desainSertifikat = $request->input('desain_sertifikat');
            if (is_string($desainSertifikat)) {
                $decoded = json_decode($desainSertifikat, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['desain_sertifikat'] = $decoded;
                } else {
                    return response()->json([
                        'success' => false,
                        'errors' => ['desain_sertifikat' => ['Format JSON tidak valid']]
                    ], 422);
                }
            }
        }

        // Handle form_evaluasi JSON dari form-data
        if ($request->has('form_evaluasi')) {
            $formEvaluasi = $request->input('form_evaluasi');
            if (is_string($formEvaluasi)) {
                $decoded = json_decode($formEvaluasi, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['form_evaluasi'] = $decoded;
                } else {
                    return response()->json([
                        'success' => false,
                        'errors' => ['form_evaluasi' => ['Format JSON tidak valid']]
                    ], 422);
                }
            }
        }

        // Handle template_sertifikat
        if ($request->hasFile('template_sertifikat')) {
            // Upload file baru — hapus yang lama dulu
            if ($kegiatan->template_sertifikat && Storage::disk('public')->exists($kegiatan->template_sertifikat)) {
                Storage::disk('public')->delete($kegiatan->template_sertifikat);
            }
            $file = $request->file('template_sertifikat');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/template_sertifikat', $filename, 'public');
            $data['template_sertifikat'] = $path;
        } elseif ($useExistingTemplate && $request->filled('template_sertifikat')) {
            // Gunakan path yang sudah ada (template default atau template yang pernah diupload)
            // Tidak hapus file lama — path berbeda / shared
            $templatePath = $request->input('template_sertifikat');
            // Cegah path traversal
            if (str_contains($templatePath, '..')) {
                return response()->json(['success' => false, 'message' => 'Path template tidak valid'], 422);
            }
            $data['template_sertifikat'] = $templatePath;
        }

        // Upload banner baru jika ada
        if ($request->hasFile('banner')) {
            // Hapus banner lama jika ada
            if ($kegiatan->banner && Storage::disk('public')->exists($kegiatan->banner)) {
                Storage::disk('public')->delete($kegiatan->banner);
            }

            $file = $request->file('banner');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/banners', $filename, 'public');
            $data['banner'] = $path;
        }

        // Upload materi baru jika ada
        if ($request->hasFile('materi')) {
            if ($kegiatan->materi && Storage::disk('public')->exists($kegiatan->materi)) {
                Storage::disk('public')->delete($kegiatan->materi);
            }
            $file = $request->file('materi');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/materi', $filename, 'public');
            $data['materi'] = $path;
        }

        // Upload virtual_background baru jika ada
        if ($request->hasFile('virtual_background')) {
            if ($kegiatan->virtual_background && Storage::disk('public')->exists($kegiatan->virtual_background)) {
                Storage::disk('public')->delete($kegiatan->virtual_background);
            }
            $file = $request->file('virtual_background');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('kegiatan/virtual_backgrounds', $filename, 'public');
            $data['virtual_background'] = $path;
        }

        $kegiatan->update($data);

        // Tambahkan URL banner
        if ($kegiatan->banner) {
            $kegiatan->banner_url = url('storage/' . $kegiatan->banner);
        }
        if ($kegiatan->materi) {
            $kegiatan->materi_url = url('storage/' . $kegiatan->materi);
        }
        if ($kegiatan->virtual_background) {
            $kegiatan->virtual_background_url = url('storage/' . $kegiatan->virtual_background);
        }
        if ($kegiatan->template_sertifikat) {
            $kegiatan->template_sertifikat_url = url('storage/' . $kegiatan->template_sertifikat);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kegiatan berhasil diupdate',
            'data' => $kegiatan
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $kegiatan = Kegiatan::find($id);

        if (!$kegiatan) {
            return response()->json([
                'success' => false,
                'message' => 'Kegiatan tidak ditemukan'
            ], 404);
        }

        // Hapus banner jika ada
        if ($kegiatan->banner && Storage::disk('public')->exists($kegiatan->banner)) {
            Storage::disk('public')->delete($kegiatan->banner);
        }

        // Hapus materi jika ada
        if ($kegiatan->materi && Storage::disk('public')->exists($kegiatan->materi)) {
            Storage::disk('public')->delete($kegiatan->materi);
        }

        // Hapus virtual_background jika ada
        if ($kegiatan->virtual_background && Storage::disk('public')->exists($kegiatan->virtual_background)) {
            Storage::disk('public')->delete($kegiatan->virtual_background);
        }

        // Hapus template_sertifikat jika ada
        if ($kegiatan->template_sertifikat && Storage::disk('public')->exists($kegiatan->template_sertifikat)) {
            Storage::disk('public')->delete($kegiatan->template_sertifikat);
        }

        $kegiatan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kegiatan berhasil dihapus'
        ]);
    }
}
