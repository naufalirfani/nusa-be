<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KegiatanPegawai extends Model
{
    protected $table = 'kegiatan_pegawai';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kegiatan_id',
        'nip',
        'isi_form',
        'link_sertifikat',
        'nomor_sertifikat',
        'verification_token',
        'pdf_hash',
        'signed_at',
    ];

    protected $hidden = [
        'verification_token',
        'pdf_hash',
    ];

    protected $casts = [
        'isi_form'  => 'json',
        'signed_at' => 'datetime',
    ];

    protected $appends = [
        'jenis_survei',
    ];

    /**
     * Determine whether isi_form represents Survei Kegiatan, Evaluasi Narasumber, or both.
     */
    public function getJenisSurveiAttribute(): string
    {
        $isiForm = $this->isi_form;
        if (is_string($isiForm)) {
            $decoded = json_decode($isiForm, true);
            $isiForm = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($isiForm) || empty($isiForm)) {
            return 'survei_kegiatan';
        }

        $identityKeys = [
            'nama_lengkap',
            'nip_no_absen',
            'jabatan',
            'unit_kerja',
            'status_pegawai',
            'nomor_sertifikat',
            'nip',
        ];

        $hasEvaluasiNarasumber = false;
        $hasSurveiKegiatan = false;

        foreach (array_keys($isiForm) as $key) {
            $keyStr = (string) $key;
            if (preg_match('/^ns_\d+_/', $keyStr)) {
                $hasEvaluasiNarasumber = true;
            } elseif (!in_array($keyStr, $identityKeys, true)) {
                $hasSurveiKegiatan = true;
            }
        }

        if ($hasEvaluasiNarasumber && $hasSurveiKegiatan) {
            return 'gabungan';
        }

        if ($hasEvaluasiNarasumber) {
            return 'evaluasi_narasumber';
        }

        return 'survei_kegiatan';
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            // Auto-generate sequence_number per kegiatan
            if (empty($model->sequence_number)) {
                $max = DB::table($model->getTable())
                    ->where('kegiatan_id', $model->kegiatan_id)
                    ->max('sequence_number');

                $model->sequence_number = ($max !== null) ? $max + 1 : 1;
            }
        });
    }

    /**
     * Get the kegiatan that owns the kegiatan pegawai.
     */
    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(Kegiatan::class, 'kegiatan_id');
    }
}
