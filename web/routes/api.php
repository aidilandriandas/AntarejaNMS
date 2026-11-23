<?php

use App\Http\Controllers\ApiController;
use App\Http\Middleware\ApiToken;
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
    // Metode lain dijawab 405 oleh ApiToken (API ini tidak pernah mengubah data)
    Route::any('{any}', fn () => response()->json(['error' => 'tidak ditemukan'], 404))->where('any', '.*');
});
