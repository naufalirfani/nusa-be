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
        'isi_form',
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
