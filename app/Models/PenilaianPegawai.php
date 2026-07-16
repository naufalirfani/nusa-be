<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PenilaianPegawai extends Model
{
    use SoftDeletes;

    protected $table = 'penilaian_pegawai';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'periode',
        'nip_pegawai',
        'nip_penilai',
        'role',
        'penilaian',
        'active',
        'is_manual',
    ];

    protected $casts = [
        'penilaian' => 'json',
        'active' => 'boolean',
        'is_manual' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
