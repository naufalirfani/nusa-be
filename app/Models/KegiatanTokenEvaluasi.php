<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KegiatanTokenEvaluasi extends Model
{
    protected $table = 'kegiatan_token_evaluasi';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kegiatan_id',
        'token',
        'jenis',
        'is_used',
        'used_at',
        'used_by',
    ];

    protected $casts = [
        'is_used' => 'boolean',
        'used_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            if (empty($model->token)) {
                $model->token = Str::random(40);
            }
        });
    }

    /**
     * Get the kegiatan that owns the evaluation token.
     */
    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(Kegiatan::class, 'kegiatan_id');
    }
}

