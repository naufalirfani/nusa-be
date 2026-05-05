<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\KegiatanController;
use App\Http\Controllers\KegiatanPegawaiController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\CmbApiController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\AuthController;


// Admin Authentication Routes (no middleware)
Route::middleware(['log.api.requests', 'verify.api.token', 'whitelist.ip'])->post('admin/login', [AuthController::class, 'login']);
Route::middleware(['log.api.requests', 'verify.api.token', 'whitelist.ip'])->post('admin/logout', [AuthController::class, 'logout']);
Route::middleware(['log.api.requests', 'verify.api.token', 'whitelist.ip'])->post('admin/verify', [AuthController::class, 'verify']);

// Public certificate verification route (no auth required)
// Accepts both new UUID-based identifiers and legacy verification tokens.
Route::get('sertifikat/verify/{identifier}', [CertificateController::class, 'verify']);

// Apply API token, logging and IP whitelist middleware to all API routes
Route::middleware(['log.api.requests', 'verify.api.token', 'whitelist.ip'])->group(function () {
    // API routes will be added here
    
    // Kegiatan CRUD routes (explicit so update can be POST with multipart/form-data)
    Route::get('kegiatan', [KegiatanController::class, 'index']);
    Route::post('kegiatan', [KegiatanController::class, 'store']);
    Route::get('kegiatan/{id}', [KegiatanController::class, 'show']);
    Route::get('kegiatan/linktree/{linktree}', [KegiatanController::class, 'showByLinktree']);
    // Use POST for update to support FormData from frontend
    Route::post('kegiatan/{id}', [KegiatanController::class, 'update']);
    Route::delete('kegiatan/{id}', [KegiatanController::class, 'destroy']);
    Route::get('kegiatan/{id}/test-certificate', [KegiatanController::class, 'testCertificate']);

    // Kegiatan Pegawai CRUD routes
    Route::get('kegiatan-pegawai', [KegiatanPegawaiController::class, 'index']);
    Route::post('kegiatan-pegawai', [KegiatanPegawaiController::class, 'store']);
    Route::get('kegiatan-pegawai/{id}', [KegiatanPegawaiController::class, 'show']);
    Route::put('kegiatan-pegawai/{id}', [KegiatanPegawaiController::class, 'update']);
    Route::delete('kegiatan-pegawai/{id}', [KegiatanPegawaiController::class, 'destroy']);
    Route::post('kegiatan-pegawai/{id}/regenerate-certificate', [KegiatanPegawaiController::class, 'regenerateCertificate']);

    // Media CRUD routes
    Route::prefix('media')->group(function () {
        Route::get('/', [MediaController::class, 'index']);
        Route::post('/', [MediaController::class, 'store']);
        Route::post('/multiple', [MediaController::class, 'storeMultiple']);
        // Download route: supports nested paths (e.g. certificates/myfile.pdf)
        Route::get('/download/{path}', [MediaController::class, 'download'])->where('path', '.*');
        Route::get('/show', [MediaController::class, 'show']);
        Route::post('/update', [MediaController::class, 'update']);
        Route::delete('/', [MediaController::class, 'destroy']);
    });

    // CMB API forwarding routes (SSO, Pegawai, Calendar)
    Route::get('sso/generate/{identifier}', [CmbApiController::class, 'generateSsoToken']);
    Route::get('sso/verify/{token}', [CmbApiController::class, 'verifySsoToken']);
    Route::get('pegawai', [CmbApiController::class, 'getPegawai']);
    Route::get('pegawai/{nip}', [CmbApiController::class, 'getPegawaiByNip']);
    Route::get('calendar/fetch', [CmbApiController::class, 'fetchCalendar']);
});
