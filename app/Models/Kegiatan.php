<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
        'template_sertifikat',
        'form_evaluasi',
        'form_evaluasi_narasumber',
        'butuh_sertifikat',
        'aksesibilitas',
        'aksesibilitas_narasumber',
    ];

    protected $appends = ['narasumber_list'];

    protected $casts = [
        // cast tanggal to a formatted date string to avoid UTC offset in JSON
        'tanggal' => 'date:Y-m-d',
        'desain_sertifikat' => 'json',
        'form_evaluasi' => 'json',
        'form_evaluasi_narasumber' => 'json',
        'butuh_sertifikat' => 'boolean',
    ];

    public function getNarasumberListAttribute(): array
    {
        $raw = $this->attributes['narasumber'] ?? null;
        if (empty($raw)) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            [
                'narasumber' => $raw,
                'asal_narasumber' => $this->attributes['asal_narasumber'] ?? 'Internal',
            ]
        ];
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            if (empty($model->sequence_number)) {

                // Tentukan tanggal yang dipakai
                $date = $model->created_at ?? now();

                $max = DB::table($model->getTable())
                    ->whereYear('created_at', Carbon::parse($date)->year)
                    ->whereMonth('created_at', Carbon::parse($date)->month)
                    ->max('sequence_number');

                $model->sequence_number = $max ? $max + 1 : 1;
            }
        });
    }
}
