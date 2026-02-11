<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule peta jabatan sync every day at 17:00
Schedule::command('sync:peta-jabatan')->dailyAt('17:00');

// Schedule pegawai sync every day at 18:00
Schedule::command('sync:pegawai')->dailyAt('18:00');

// Schedule statistik update every day at 19:00
Schedule::command('update:statistik')->dailyAt('19:00');
