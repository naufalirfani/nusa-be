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
    ];

    protected $casts = [
        'isi_form' => 'json',
    ];

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
