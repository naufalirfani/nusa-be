<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KegiatanEvaluasiNarasumber extends Model
{
    protected $table = 'kegiatan_evaluasi_narasumber';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kegiatan_id',
        'nip',
        'kategori_responden',
        'isi_form',
    ];

    protected $casts = [
        'isi_form' => 'json',
    ];

    protected $appends = [
        'kategori_responden',
    ];

    /**
     * Get the respondent category (internal vs umum).
     * Falls back to checking whether NIP exists in CMB API pegawai data if null.
     */
    public function getKategoriRespondenAttribute($value): string
    {
        if (!empty($value)) {
            return strtolower(trim($value));
        }

        $isiForm = $this->isi_form;
        if (is_string($isiForm)) {
            $decoded = json_decode($isiForm, true);
            $isiForm = is_array($decoded) ? $decoded : [];
        }
        if (is_array($isiForm) && !empty($isiForm['kategori_responden'])) {
            return strtolower(trim($isiForm['kategori_responden']));
        }

        $nip = trim((string) ($this->nip ?? ($isiForm['nip_no_absen'] ?? '')));
        return \App\Http\Controllers\CmbApiController::isInternalNip($nip) ? 'internal' : 'umum';
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the kegiatan associated with the evaluation.
     */
    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(Kegiatan::class, 'kegiatan_id');
    }
}
