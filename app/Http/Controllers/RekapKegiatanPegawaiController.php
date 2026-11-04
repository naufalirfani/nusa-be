<?php

namespace App\Http\Controllers;

use App\Models\Kegiatan;
use App\Models\KegiatanPegawai;
use App\Models\KegiatanEvaluasiNarasumber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RekapKegiatanPegawaiController extends Controller
{
    /**
     * Helper to extract NIP from a model record or its isi_form json.
     */
    private function extractNip($record): ?string
    {
        $nip = trim((string) ($record->nip ?? ''));
        if (!empty($nip) && $nip !== '-' && strtolower($nip) !== 'null' && strtolower($nip) !== 'umum') {
            return $nip;
        }

        $isiForm = $record->isi_form;
        if (is_string($isiForm)) {
            $isiForm = json_decode($isiForm, true) ?? [];
        }

        if (is_array($isiForm)) {
            $fromForm = trim((string) ($isiForm['nip_no_absen'] ?? ($isiForm['nip'] ?? '')));
            if (!empty($fromForm) && $fromForm !== '-' && strtolower($fromForm) !== 'null' && strtolower($fromForm) !== 'umum') {
                return $fromForm;
            }
        }

        return null;
    }

    /**
     * Helper to extract employee name from record or its isi_form json.
     */
    private function extractNama($record): ?string
    {
        $isiForm = $record->isi_form;
        if (is_string($isiForm)) {
            $isiForm = json_decode($isiForm, true) ?? [];
        }

        if (is_array($isiForm)) {
            return $isiForm['nama_lengkap'] ?? ($isiForm['nama'] ?? ($isiForm['name'] ?? null));
        }

        return null;
    }

    /**
     * Display a recap of activities attended by each employee.
     * Merges kegiatan_pegawai and kegiatan_evaluasi_narasumber, deduplicating by kegiatan_id.
     * Only counts internal employees (filters kategori_responden = internal).
     *
     * GET /api/rekap-kegiatan-pegawai
     * Query params:
     * - tahun: integer (filter activities by year)
     * - kegiatan_id: string (filter by specific activity)
     * - nip: string (filter by specific employee NIP)
     */
    public function index(Request $request)
    {
        try {
            $tahun = $request->filled('tahun') ? (int) $request->get('tahun') : null;
            $kegiatanId = $request->get('kegiatan_id');
            $filterNip = $request->filled('nip') ? trim((string) $request->get('nip')) : null;

            // Ambil daftar seluruh NIP pegawai internal dari CMB API untuk verifikasi & filtering
            $internalNips = CmbApiController::getAllPegawaiNips();

            // 1. Build KegiatanPegawai query (hanya kategori responden = internal)
            $kpQuery = KegiatanPegawai::with(['kegiatan']);

            // Filter kategori responden = internal
            $kpQuery->where(function ($q) use ($internalNips) {
                $q->where('kategori_responden', 'internal')
                  ->orWhereRaw("(isi_form->>'kategori_responden') ILIKE 'internal'")
                  ->orWhere(function ($sub) use ($internalNips) {
                      $sub->whereNull('kategori_responden')
                          ->whereRaw("((isi_form->>'kategori_responden') IS NULL OR (isi_form->>'kategori_responden') = '')");
                      if (!empty($internalNips)) {
                          $sub->where(function ($nipSub) use ($internalNips) {
                              $nipSub->whereIn('nip', $internalNips)
                                     ->orWhereIn(DB::raw("isi_form->>'nip_no_absen'"), $internalNips);
                          });
                      } else {
                          $sub->whereRaw("nip ~ '^[0-9]{18}$'");
                      }
                  });
            });

            if ($tahun) {
                $kpQuery->whereHas('kegiatan', function ($q) use ($tahun) {
                    $q->whereYear('tanggal', $tahun);
                });
            }

            if ($kegiatanId) {
                $kpQuery->where('kegiatan_id', $kegiatanId);
            }

            if ($filterNip) {
                $kpQuery->where(function ($q) use ($filterNip) {
                    $q->where('nip', $filterNip)
                      ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$filterNip])
                      ->orWhereRaw("(isi_form->>'nip') = ?", [$filterNip]);
                });
            }

            $kegiatanPegawai = $kpQuery->get();

            // 2. Build KegiatanEvaluasiNarasumber query (hanya kategori responden = internal)
            $kenQuery = KegiatanEvaluasiNarasumber::with(['kegiatan']);

            // Filter kategori responden = internal
            $kenQuery->where(function ($q) use ($internalNips) {
                $q->where('kategori_responden', 'internal')
                  ->orWhereRaw("(isi_form->>'kategori_responden') ILIKE 'internal'")
                  ->orWhere(function ($sub) use ($internalNips) {
                      $sub->whereNull('kategori_responden')
                          ->whereRaw("((isi_form->>'kategori_responden') IS NULL OR (isi_form->>'kategori_responden') = '')");
                      if (!empty($internalNips)) {
                          $sub->where(function ($nipSub) use ($internalNips) {
                              $nipSub->whereIn('nip', $internalNips)
                                     ->orWhereIn(DB::raw("isi_form->>'nip_no_absen'"), $internalNips);
                          });
                      } else {
                          $sub->whereRaw("nip ~ '^[0-9]{18}$'");
                      }
                  });
            });

            if ($tahun) {
                $kenQuery->whereHas('kegiatan', function ($q) use ($tahun) {
                    $q->whereYear('tanggal', $tahun);
                });
            }

            if ($kegiatanId) {
                $kenQuery->where('kegiatan_id', $kegiatanId);
            }

            if ($filterNip) {
                $kenQuery->where(function ($q) use ($filterNip) {
                    $q->where('nip', $filterNip)
                      ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$filterNip])
                      ->orWhereRaw("(isi_form->>'nip') = ?", [$filterNip]);
                });
            }

            $kegiatanEvaluasi = $kenQuery->get();

            // 3. Merge & Deduplicate per Pegawai (by NIP) and per Kegiatan (by kegiatan_id)
            $byNip = [];

            // Process KegiatanPegawai
            foreach ($kegiatanPegawai as $kp) {
                $nip = $this->extractNip($kp);
                if (!$nip) {
                    continue;
                }

                // Jika NIP tidak terdaftar di master pegawai, tidak usah dihitung
                if (!empty($internalNips) && !in_array($nip, $internalNips, true)) {
                    continue;
                }

                $k = $kp->kegiatan;
                if (!$k) {
                    continue;
                }

                $kId = (string) $k->id;

                if (!isset($byNip[$nip])) {
                    $byNip[$nip] = [
                        'nip' => $nip,
                        'nama' => $this->extractNama($kp),
                        'kegiatan_map' => [],
                    ];
                } elseif (empty($byNip[$nip]['nama'])) {
                    $byNip[$nip]['nama'] = $this->extractNama($kp);
                }

                if (!isset($byNip[$nip]['kegiatan_map'][$kId])) {
                    $byNip[$nip]['kegiatan_map'][$kId] = [
                        'id' => $kp->id,
                        'kegiatan_pegawai_id' => $kp->id,
                        'kegiatan_id' => $kId,
                        'nama_kegiatan' => $k->nama_kegiatan,
                        'judul_tema' => $k->judul_tema,
                        'tanggal' => $k->tanggal ? Carbon::parse($k->tanggal)->format('Y-m-d') : null,
                        'jam_mulai' => $k->jam_mulai,
                        'jam_selesai' => $k->jam_selesai,
                        'tempat' => $k->tempat,
                        'jumlah_jp' => (int) ($k->jumlah_jp ?? 2),
                        'sumber' => 'Presensi Kegiatan',
                        'sources' => ['kegiatan_pegawai'],
                        'nomor_sertifikat' => $kp->nomor_sertifikat,
                        'link_sertifikat' => $kp->link_sertifikat,
                        'ada_sertifikat' => !empty($kp->link_sertifikat) || !empty($kp->nomor_sertifikat),
                        'created_at' => $kp->created_at ? $kp->created_at->toISOString() : null,
                    ];
                } else {
                    if (empty($byNip[$nip]['kegiatan_map'][$kId]['id']) && !empty($kp->id)) {
                        $byNip[$nip]['kegiatan_map'][$kId]['id'] = $kp->id;
                        $byNip[$nip]['kegiatan_map'][$kId]['kegiatan_pegawai_id'] = $kp->id;
                    }
                    // Update certificate if not present
                    if (empty($byNip[$nip]['kegiatan_map'][$kId]['nomor_sertifikat']) && !empty($kp->nomor_sertifikat)) {
                        $byNip[$nip]['kegiatan_map'][$kId]['nomor_sertifikat'] = $kp->nomor_sertifikat;
                    }
                    if (empty($byNip[$nip]['kegiatan_map'][$kId]['link_sertifikat']) && !empty($kp->link_sertifikat)) {
                        $byNip[$nip]['kegiatan_map'][$kId]['link_sertifikat'] = $kp->link_sertifikat;
                    }
                    $byNip[$nip]['kegiatan_map'][$kId]['ada_sertifikat'] =
                        !empty($byNip[$nip]['kegiatan_map'][$kId]['link_sertifikat']) ||
                        !empty($byNip[$nip]['kegiatan_map'][$kId]['nomor_sertifikat']);
                }
            }

            // Process KegiatanEvaluasiNarasumber
            foreach ($kegiatanEvaluasi as $ken) {
                $nip = $this->extractNip($ken);
                if (!$nip) {
                    continue;
                }

                // Jika NIP tidak terdaftar di master pegawai, tidak usah dihitung
                if (!empty($internalNips) && !in_array($nip, $internalNips, true)) {
                    continue;
                }

                $k = $ken->kegiatan;
                if (!$k) {
                    continue;
                }

                $kId = (string) $k->id;

                if (!isset($byNip[$nip])) {
                    $byNip[$nip] = [
                        'nip' => $nip,
                        'nama' => $this->extractNama($ken),
                        'kegiatan_map' => [],
                    ];
                } elseif (empty($byNip[$nip]['nama'])) {
                    $byNip[$nip]['nama'] = $this->extractNama($ken);
                }

                if (!isset($byNip[$nip]['kegiatan_map'][$kId])) {
                    // Cek apakah ada record KegiatanPegawai terkait untuk mendapatkan ID & sertifikat jika sudah terbit
                    $matchedKp = KegiatanPegawai::where('kegiatan_id', $kId)
                        ->where(function ($q) use ($nip) {
                            $q->where('nip', $nip)
                              ->orWhereRaw("(isi_form->>'nip_no_absen') = ?", [$nip])
                              ->orWhereRaw("(isi_form->>'nip') = ?", [$nip]);
                        })->first();

                    $byNip[$nip]['kegiatan_map'][$kId] = [
                        'id' => $matchedKp ? $matchedKp->id : null,
                        'kegiatan_pegawai_id' => $matchedKp ? $matchedKp->id : null,
                        'kegiatan_id' => $kId,
                        'nama_kegiatan' => $k->nama_kegiatan,
                        'judul_tema' => $k->judul_tema,
                        'tanggal' => $k->tanggal ? Carbon::parse($k->tanggal)->format('Y-m-d') : null,
                        'jam_mulai' => $k->jam_mulai,
                        'jam_selesai' => $k->jam_selesai,
                        'tempat' => $k->tempat,
                        'jumlah_jp' => (int) ($k->jumlah_jp ?? 2),
                        'sumber' => 'Evaluasi Narasumber',
                        'sources' => ['kegiatan_evaluasi_narasumber'],
                        'nomor_sertifikat' => $matchedKp ? $matchedKp->nomor_sertifikat : null,
                        'link_sertifikat' => $matchedKp ? $matchedKp->link_sertifikat : null,
                        'ada_sertifikat' => $matchedKp ? (!empty($matchedKp->link_sertifikat) || !empty($matchedKp->nomor_sertifikat)) : false,
                        'created_at' => $ken->created_at ? $ken->created_at->toISOString() : null,
                    ];
                } else {
                    // Pegawai ada di kegiatan_pegawai dan evaluasi_narasumber (double dihitung 1)
                    $entry = &$byNip[$nip]['kegiatan_map'][$kId];
                    if (!in_array('kegiatan_evaluasi_narasumber', $entry['sources'], true)) {
                        $entry['sources'][] = 'kegiatan_evaluasi_narasumber';
                        $entry['sumber'] = 'Presensi & Evaluasi';
                    }
                }
            }

            // 4. Finalize & compute totals
            $finalByNip = [];
            $allUniqueKegiatanIds = [];
            $totalUniqueParticipation = 0;

            foreach ($byNip as $nip => $data) {
                $kegiatans = array_values($data['kegiatan_map']);
                usort($kegiatans, function ($a, $b) {
                    return strcmp($b['tanggal'] ?? '', $a['tanggal'] ?? '');
                });

                $empTotalKegiatan = count($kegiatans);
                $empTotalJp = 0;
                foreach ($kegiatans as $kg) {
                    $empTotalJp += (int) ($kg['jumlah_jp'] ?? 2);
                    $allUniqueKegiatanIds[$kg['kegiatan_id']] = true;
                    $totalUniqueParticipation++;
                }

                $finalByNip[$nip] = [
                    'nip' => $nip,
                    'nama' => $data['nama'],
                    'total_kegiatan' => $empTotalKegiatan,
                    'total_jp' => $empTotalJp,
                    'kegiatan' => $kegiatans,
                ];
            }

            // Available years for filtering
            $availableYears = DB::table('kegiatan')
                ->whereNotNull('tanggal')
                ->selectRaw('DISTINCT EXTRACT(YEAR FROM tanggal)::int as year')
                ->orderBy('year', 'desc')
                ->pluck('year')
                ->toArray();

            // Total kegiatan count in DB (with filter if tahun set)
            $kegiatanCountQuery = Kegiatan::query();
            if ($tahun) {
                $kegiatanCountQuery->whereYear('tanggal', $tahun);
            }
            $totalKegiatanInDb = $kegiatanCountQuery->count();
            $totalJpInDb = (int) $kegiatanCountQuery->sum('jumlah_jp');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'by_nip' => $finalByNip,
                    'available_years' => $availableYears,
                    'statistics' => [
                        'total_kegiatan' => $totalKegiatanInDb,
                        'total_jp_tersedia' => $totalJpInDb,
                        'total_pegawai_mengikuti' => count($finalByNip),
                        'total_partisipasi_tercatat' => $totalUniqueParticipation,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in RekapKegiatanPegawaiController@index: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data rekap kegiatan pegawai',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detail of activities attended by a specific employee.
     *
     * GET /api/rekap-kegiatan-pegawai/{nip}
     */
    public function show(Request $request, string $nip)
    {
        $request->merge(['nip' => $nip]);
        $response = $this->index($request);
        $content = json_decode($response->getContent(), true);

        if (($content['status'] ?? '') === 'success') {
            $byNip = $content['data']['by_nip'] ?? [];
            $employeeData = $byNip[$nip] ?? [
                'nip' => $nip,
                'nama' => null,
                'total_kegiatan' => 0,
                'total_jp' => 0,
                'kegiatan' => [],
            ];

            return response()->json([
                'status' => 'success',
                'data' => $employeeData,
            ]);
        }

        return $response;
    }
}
