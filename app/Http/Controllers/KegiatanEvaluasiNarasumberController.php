<?php

namespace App\Http\Controllers;

use App\Models\KegiatanEvaluasiNarasumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KegiatanEvaluasiNarasumberController extends Controller
{
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
