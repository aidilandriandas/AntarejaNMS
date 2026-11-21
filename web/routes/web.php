<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('setup', [AuthController::class, 'setup'])->name('setup');
Route::post('setup', [AuthController::class, 'storeSetup'])->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('perangkat', [DeviceController::class, 'index'])->name('devices.index');
    Route::middleware('can:manage-devices')->group(function () {
        Route::get('perangkat/tambah', [DeviceController::class, 'create'])->name('devices.create');
        Route::post('perangkat', [DeviceController::class, 'store'])->name('devices.store');
        Route::post('perangkat/uji', [DeviceController::class, 'test'])->name('devices.test')->middleware('throttle:20,1');
        Route::get('perangkat/{device}/ubah', [DeviceController::class, 'edit'])->name('devices.edit');
        Route::put('perangkat/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::delete('perangkat/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('perangkat/{device}/periksa', [DeviceController::class, 'poll'])->name('devices.poll')->middleware('throttle:10,1');

        Route::post('site', [SiteController::class, 'store'])->name('sites.store');
        Route::put('site/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('site/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
    });
    Route::get('perangkat/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::get('perangkat/{device}/grafik', [DeviceController::class, 'metrics'])->name('devices.metrics');

    Route::get('site', [SiteController::class, 'index'])->name('sites.index');
    Route::get('kejadian', [EventController::class, 'index'])->name('events.index');

    Route::get('profil', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::middleware('can:admin')->group(function () {
        Route::get('pengguna', [UserController::class, 'index'])->name('users.index');
        Route::get('pengguna/tambah', [UserController::class, 'create'])->name('users.create');
        Route::post('pengguna', [UserController::class, 'store'])->name('users.store');
        Route::get('pengguna/{user}/ubah', [UserController::class, 'edit'])->name('users.edit');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    });
});
