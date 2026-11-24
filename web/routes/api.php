<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\Site\SiteApiController;
use App\Http\Middleware\ApiToken;
use App\Http\Middleware\SiteCollectorToken;
use Illuminate\Support\Facades\Route;

// API baca-saja (token Bearer dari menu Administrasi, Token API). Prefiks /api dari bootstrap/app.php.
Route::prefix('v1')->middleware([ApiToken::class, 'throttle:120,1'])->group(function () {
    Route::get('/', [ApiController::class, 'index']);
    Route::get('sites', [ApiController::class, 'sites']);
    Route::get('devices', [ApiController::class, 'devices']);
    Route::get('devices/{id}', [ApiController::class, 'device'])->whereNumber('id');
    Route::get('problems', [ApiController::class, 'problems']);
    Route::get('incidents', [ApiController::class, 'incidents']);
    Route::get('events', [ApiController::class, 'events']);
    Route::get('anomalies', [ApiController::class, 'anomalies']);
    Route::get('services', [ApiController::class, 'services']);
    Route::get('downtime', [ApiController::class, 'downtime']);
    Route::get('checks', [ApiController::class, 'checks']);
    Route::get('sensors', [ApiController::class, 'sensors']);
    Route::get('macs', [ApiController::class, 'macs']);
    // Metode lain dijawab 405 oleh ApiToken (API ini tidak pernah mengubah data)
    Route::any('{any}', fn () => response()->json(['error' => 'tidak ditemukan'], 404))->where('any', '.*');
});

// Collector site (token acs_ dari menu Collector site). Satu collector mengirim kira-kira sekali per detik saat mengejar antrean.
Route::prefix('site/v1')->middleware([SiteCollectorToken::class, 'throttle:600,1'])->group(function () {
    Route::get('config', [SiteApiController::class, 'config']);
    Route::post('results', [SiteApiController::class, 'results']);
    Route::any('{any}', fn () => response()->json(['error' => 'tidak ditemukan'], 404))->where('any', '.*');
});
