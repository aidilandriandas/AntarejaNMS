<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\NocController;
use App\Http\Controllers\ProblemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SensorController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TopologyController;
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

        Route::post('perangkat/{device}/port/{ifIndex}/pantau', [DeviceController::class, 'toggleMonitor'])->name('devices.monitor')->whereNumber('ifIndex');

        Route::post('problem/{problem}/ack', [ProblemController::class, 'ack'])->name('problems.ack');
        Route::delete('problem/{problem}/ack', [ProblemController::class, 'unack'])->name('problems.unack');

        Route::post('maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
        Route::post('maintenance/{maintenance}/akhiri', [MaintenanceController::class, 'finish'])->name('maintenance.finish');
        Route::delete('maintenance/{maintenance}', [MaintenanceController::class, 'destroy'])->name('maintenance.destroy');

        Route::post('topologi/sambungan', [TopologyController::class, 'storeLink'])->name('topology.links.store');
        Route::delete('topologi/sambungan/{link}', [TopologyController::class, 'destroyLink'])->name('topology.links.destroy')->whereNumber('link');
        Route::post('topologi/posisi', [TopologyController::class, 'savePositions'])->name('topology.positions');

        Route::post('site', [SiteController::class, 'store'])->name('sites.store');
        Route::put('site/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('site/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
    });
    Route::get('perangkat/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::get('perangkat/{device}/grafik', [DeviceController::class, 'metrics'])->name('devices.metrics');

    Route::get('site', [SiteController::class, 'index'])->name('sites.index');
    Route::get('kejadian', [EventController::class, 'index'])->name('events.index');
    Route::get('problem', [ProblemController::class, 'index'])->name('problems.index');
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::get('sensor', [SensorController::class, 'index'])->name('sensors.index');
    Route::get('topologi', [TopologyController::class, 'index'])->name('topology.index');
    Route::get('topologi/data', [TopologyController::class, 'data'])->name('topology.data');
    Route::post('topologi/live', [TopologyController::class, 'live'])->name('topology.live')->middleware('throttle:40,1');
    Route::get('topologi/port/{device}', [TopologyController::class, 'ports'])->name('topology.ports');
    Route::get('noc', [NocController::class, 'index'])->name('noc');
    Route::get('noc/data', [NocController::class, 'data'])->name('noc.data');

    Route::get('profil', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::middleware('can:admin')->group(function () {
        Route::get('pengguna', [UserController::class, 'index'])->name('users.index');
        Route::get('pengguna/tambah', [UserController::class, 'create'])->name('users.create');
        Route::post('pengguna', [UserController::class, 'store'])->name('users.store');
        Route::get('pengguna/{user}/ubah', [UserController::class, 'edit'])->name('users.edit');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        Route::get('notifikasi', [ChannelController::class, 'index'])->name('channels.index');
        Route::get('notifikasi/tambah', [ChannelController::class, 'create'])->name('channels.create');
        Route::post('notifikasi', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('notifikasi/{channel}/ubah', [ChannelController::class, 'edit'])->name('channels.edit');
        Route::put('notifikasi/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('notifikasi/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('notifikasi/{channel}/uji', [ChannelController::class, 'test'])->name('channels.test')->middleware('throttle:10,1');
        Route::put('ambang', [ChannelController::class, 'thresholds'])->name('thresholds.update');
    });
});
