<?php

namespace App\Http\Controllers;

use App\Models\Kegiatan;
use App\Models\KegiatanTokenEvaluasi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class KegiatanTokenEvaluasiController extends Controller
{
    /**
     * Get list of evaluation tokens for a specific activity.
     */
    public function index(Request $request, $kegiatan_id)
    {
        $kegiatan = Kegiatan::findOrFail($kegiatan_id);

        $query = KegiatanTokenEvaluasi::where('kegiatan_id', $kegiatan->id);

        if ($request->has('jenis') && in_array($request->jenis, ['kegiatan', 'narasumber'], true)) {
            $query->where('jenis', $request->jenis);
        }

        if ($request->has('is_used')) {
            $query->where('is_used', $request->boolean('is_used'));
        }

        $tokens = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar token evaluasi berhasil diambil.',
            'data'    => $tokens,
        ]);
    }

    /**
     * Create a new one-time evaluation token for a specific activity.
     */
    public function store(Request $request, $kegiatan_id)
    {
        $kegiatan = Kegiatan::findOrFail($kegiatan_id);

        $input = $request->all();
        if (empty($input) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        $jenis = $input['jenis'] ?? $request->input('jenis');
        if (empty($jenis)) {
            $jenis = 'kegiatan';
        }

        $validator = Validator::make(['jenis' => $jenis], [
            'jenis' => 'required|in:kegiatan,narasumber',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $tokenRecord = KegiatanTokenEvaluasi::create([
            'kegiatan_id' => $kegiatan->id,
            'token'       => Str::random(40),
            'jenis'       => $jenis,
            'is_used'     => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Link evaluasi khusus satu kali pakai berhasil dibuat.',
            'data'    => $tokenRecord,
        ], 201);
    }

    /**
     * Verify token validity (accessible anytime, ignores time boundary).
     */
    public function verify($token)
    {
        $tokenRecord = KegiatanTokenEvaluasi::with('kegiatan')
            ->where('token', $token)
            ->first();

        if (!$tokenRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Link evaluasi tidak ditemukan atau tidak valid.',
            ], 404);
        }

        if ($tokenRecord->is_used) {
            return response()->json([
                'success' => false,
                'is_used' => true,
                'used_at' => $tokenRecord->used_at,
                'used_by' => $tokenRecord->used_by,
                'message' => 'Link evaluasi khusus ini sudah digunakan dan tidak dapat dipakai lagi.',
            ], 410);
        }

        return response()->json([
            'success' => true,
            'is_used' => false,
            'data'    => $tokenRecord,
            'kegiatan'=> $tokenRecord->kegiatan,
        ]);
    }

    /**
     * Delete an evaluation token.
     */
    public function destroy($id)
    {
        $tokenRecord = KegiatanTokenEvaluasi::findOrFail($id);
        $tokenRecord->delete();

        return response()->json([
            'success' => true,
            'message' => 'Token evaluasi berhasil dihapus.',
        ]);
    }
}

