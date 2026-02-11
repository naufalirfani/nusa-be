<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Kegiatan extends Model
{
    protected $table = 'kegiatan';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'banner',
        'materi',
        'virtual_background',
        'linktree',
        'youtube',
        'jenis_kegiatan',
        'nama_kegiatan',
        'judul_tema',
        'deskripsi',
        'narasumber',
        'asal_narasumber',
        'moderator',
        'asal_moderator',
        'tempat',
        'tanggal',
        'jam_mulai',
        'jam_selesai',
        'desain_sertifikat',
        'form_evaluasi',
    ];

    protected $casts = [
        // cast tanggal to a formatted date string to avoid UTC offset in JSON
        'tanggal' => 'date:Y-m-d',
        'desain_sertifikat' => 'json',
        'form_evaluasi' => 'json',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            // assign sequence_number per jenis_kegiatan
            $jenis = $model->jenis_kegiatan ?? 'default';
            if (empty($model->sequence_number)) {
                $max = DB::table($model->getTable())
                    ->where('jenis_kegiatan', $jenis)
                    ->max('sequence_number');

                $model->sequence_number = ($max !== null) ? $max + 1 : 1;
            }
        });

        static::updating(function ($model) {
            // if jenis_kegiatan changed and sequence_number not set, assign new sequence
            if ($model->isDirty('jenis_kegiatan') && empty($model->sequence_number)) {
                $jenis = $model->jenis_kegiatan ?? 'default';
                $max = DB::table($model->getTable())
                    ->where('jenis_kegiatan', $jenis)
                    ->max('sequence_number');

                $model->sequence_number = ($max !== null) ? $max + 1 : 1;
            }
        });
    }
}

