<?php

use App\Http\Controllers\AnomalyController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CapacityController;
use App\Http\Controllers\CctvController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ConfigTransferController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceImportController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentReportController;
use App\Http\Controllers\IpamController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OncallController;
use App\Http\Controllers\NocController;
use App\Http\Controllers\PortController;
use App\Http\Controllers\ProblemController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportScheduleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SensorController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StatusLinkController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TopologyController;
use App\Http\Controllers\TrapController;
use App\Http\Controllers\TriggerController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('setup', [AuthController::class, 'setup'])->name('setup');
Route::post('setup', [AuthController::class, 'storeSetup'])->name('setup.store');

// Dipanggil nginx (auth_request) untuk tiap koneksi video CCTV; tidak memakai middleware auth agar menjawab 401, bukan redirect.
Route::get('cctv/auth', [CctvController::class, 'auth'])->name('cctv.auth');

// Halaman status hanya-baca lewat tautan rahasia (tanpa login); token diperiksa terhadap hash di database
Route::get('status/{token}', [StatusLinkController::class, 'show'])->name('status.public')->where('token', '[A-Za-z0-9]{1,64}')->middleware('throttle:60,1');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::put('ringkasan/susunan', [DashboardController::class, 'layout'])->name('dashboard.layout');

    Route::get('perangkat', [DeviceController::class, 'index'])->name('devices.index');
    Route::middleware('can:manage-devices')->group(function () {
        Route::get('perangkat/tambah', [DeviceController::class, 'create'])->name('devices.create');
        Route::get('perangkat/impor', [DeviceImportController::class, 'form'])->name('devices.import');
        Route::get('discovery', [DiscoveryController::class, 'index'])->name('discovery.index');
        Route::post('peta/posisi', [MapController::class, 'position'])->name('map.position')->middleware('throttle:120,1');
        Route::post('discovery', [DiscoveryController::class, 'store'])->name('discovery.store')->middleware('throttle:10,1');
        Route::get('discovery/{scan}', [DiscoveryController::class, 'show'])->name('discovery.show')->whereNumber('scan');
        Route::get('discovery/{scan}/status', [DiscoveryController::class, 'status'])->name('discovery.status')->whereNumber('scan');
        Route::post('discovery/{scan}/batal', [DiscoveryController::class, 'cancel'])->name('discovery.cancel')->whereNumber('scan');
        Route::post('discovery/{scan}/tambah', [DiscoveryController::class, 'add'])->name('discovery.add')->whereNumber('scan');
        Route::post('discovery/{scan}/abaikan', [DiscoveryController::class, 'ignore'])->name('discovery.ignore')->whereNumber('scan');
        Route::get('perangkat/impor/templat.csv', [DeviceImportController::class, 'template'])->name('devices.import.template');
        Route::post('perangkat/impor/pratinjau', [DeviceImportController::class, 'preview'])->name('devices.import.preview');
        Route::post('perangkat/impor', [DeviceImportController::class, 'store'])->name('devices.import.store');
        Route::post('perangkat', [DeviceController::class, 'store'])->name('devices.store');
        Route::post('perangkat/uji', [DeviceController::class, 'test'])->name('devices.test')->middleware('throttle:20,1');
        Route::get('perangkat/{device}/ubah', [DeviceController::class, 'edit'])->name('devices.edit');
        Route::put('perangkat/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::delete('perangkat/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('perangkat/{device}/periksa', [DeviceController::class, 'poll'])->name('devices.poll')->middleware('throttle:10,1');
        Route::get('perangkat/{device}/diagnosa', [DeviceController::class, 'diagnose'])->name('devices.diagnose')->middleware('throttle:6,1');
        Route::post('perangkat/{device}/alat', [DeviceController::class, 'tool'])->name('devices.tool')->middleware('throttle:12,1');

        Route::post('perangkat/{device}/port/{ifIndex}/pantau', [DeviceController::class, 'toggleMonitor'])->name('devices.monitor')->whereNumber('ifIndex');

        Route::get('trigger/tambah', [TriggerController::class, 'create'])->name('triggers.create');
        Route::post('trigger', [TriggerController::class, 'store'])->name('triggers.store');
        Route::get('trigger/{trigger}/ubah', [TriggerController::class, 'edit'])->name('triggers.edit')->whereNumber('trigger');
        Route::put('trigger/{trigger}', [TriggerController::class, 'update'])->name('triggers.update')->whereNumber('trigger');
        Route::post('trigger/{trigger}/aktif', [TriggerController::class, 'toggle'])->name('triggers.toggle')->whereNumber('trigger');
        Route::delete('trigger/{trigger}', [TriggerController::class, 'destroy'])->name('triggers.destroy')->whereNumber('trigger');
        Route::post('layanan', [ServiceController::class, 'store'])->name('services.store');
        Route::put('layanan/{service}', [ServiceController::class, 'update'])->name('services.update')->whereNumber('service');
        Route::delete('layanan/{service}', [ServiceController::class, 'destroy'])->name('services.destroy')->whereNumber('service');
        Route::post('layanan/{service}/komponen', [ServiceController::class, 'storeComponent'])->name('services.components.store')->whereNumber('service');
        Route::put('layanan/{service}/komponen/{component}', [ServiceController::class, 'updateComponent'])->name('services.components.update')->whereNumber(['service', 'component']);
        Route::delete('layanan/{service}/komponen/{component}', [ServiceController::class, 'destroyComponent'])->name('services.components.destroy')->whereNumber(['service', 'component']);
        Route::post('insiden', [IncidentController::class, 'store'])->name('incidents.store');
        Route::post('insiden/{incident}/tangani', [IncidentController::class, 'take'])->name('incidents.take')->whereNumber('incident');
        Route::post('insiden/{incident}/catatan', [IncidentController::class, 'note'])->name('incidents.note')->whereNumber('incident');
        Route::post('insiden/{incident}/analisis', [IncidentController::class, 'rca'])->name('incidents.rca')->whereNumber('incident');
        Route::post('insiden/{incident}/tutup', [IncidentController::class, 'close'])->name('incidents.close')->whereNumber('incident');
        Route::post('insiden/{incident}/buka', [IncidentController::class, 'reopen'])->name('incidents.reopen')->whereNumber('incident');
        Route::post('problem/{problem}/ack', [ProblemController::class, 'ack'])->name('problems.ack');
        Route::delete('problem/{problem}/ack', [ProblemController::class, 'unack'])->name('problems.unack');

        Route::post('maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
        Route::post('maintenance/{maintenance}/akhiri', [MaintenanceController::class, 'finish'])->name('maintenance.finish');
        Route::delete('maintenance/{maintenance}', [MaintenanceController::class, 'destroy'])->name('maintenance.destroy');
        Route::post('piket', [OncallController::class, 'store'])->name('oncall.store');
        Route::post('anomali/{anomaly}/wajar', [AnomalyController::class, 'dismiss'])->name('anomalies.dismiss')->whereNumber('anomaly');
        Route::delete('anomali/abaikan', [AnomalyController::class, 'unmute'])->name('anomalies.unmute');
        Route::post('piket/rotasi', [OncallController::class, 'rotate'])->name('oncall.rotate');
        Route::delete('piket/{slot}', [OncallController::class, 'destroy'])->name('oncall.destroy')->whereNumber('slot');

        Route::post('topologi/sambungan', [TopologyController::class, 'storeLink'])->name('topology.links.store');
        Route::delete('topologi/sambungan/{link}', [TopologyController::class, 'destroyLink'])->name('topology.links.destroy')->whereNumber('link');
        Route::post('topologi/posisi', [TopologyController::class, 'savePositions'])->name('topology.positions');
        Route::post('topologi/sinkron', [TopologyController::class, 'sync'])->name('topology.sync')->middleware('throttle:4,1');

        Route::get('perangkat/{device}/konfigurasi/{version}', [ConfigController::class, 'show'])->name('devices.config')->whereNumber('version');
        Route::get('perangkat/{device}/konfigurasi/{version}/unduh', [ConfigController::class, 'download'])->name('devices.config.download')->whereNumber('version');
        Route::post('perangkat/{device}/konfigurasi/backup', [ConfigController::class, 'backupNow'])->name('devices.config.backup')->middleware('throttle:10,1');
        Route::post('perangkat/{device}/konfigurasi/reset-hostkey', [ConfigController::class, 'resetHostKey'])->name('devices.config.hostkey');

        Route::get('cctv/kelola', [CctvController::class, 'manage'])->name('cctv.manage');
        Route::post('cctv/kamera', [CctvController::class, 'store'])->name('cctv.store');
        Route::put('cctv/kamera/{camera}', [CctvController::class, 'update'])->name('cctv.update');
        Route::delete('cctv/kamera/{camera}', [CctvController::class, 'destroy'])->name('cctv.destroy');

        Route::post('ipam/subnet', [IpamController::class, 'store'])->name('ipam.store');
        Route::put('ipam/subnet/{subnet}', [IpamController::class, 'update'])->name('ipam.update')->whereNumber('subnet');
        Route::delete('ipam/subnet/{subnet}', [IpamController::class, 'destroy'])->name('ipam.destroy')->whereNumber('subnet');

        Route::post('site', [SiteController::class, 'store'])->name('sites.store');
        Route::put('site/{site}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('site/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
    });
    Route::get('perangkat/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::get('perangkat/{device}/grafik', [DeviceController::class, 'metrics'])->name('devices.metrics');
    Route::get('perangkat/{device}/port/{ifIndex}', [PortController::class, 'show'])->name('ports.show')->whereNumber('ifIndex');
    Route::get('perangkat/{device}/port/{ifIndex}/data', [PortController::class, 'data'])->name('ports.data')->whereNumber('ifIndex');
    Route::post('perangkat/{device}/live', [DeviceController::class, 'live'])->name('devices.live')->middleware('throttle:150,1');

    Route::get('site', [SiteController::class, 'index'])->name('sites.index');
    Route::get('kejadian', [EventController::class, 'index'])->name('events.index');
    Route::get('log', [LogController::class, 'index'])->name('logs.index');
    Route::get('peta', [MapController::class, 'index'])->name('map.index');
    Route::view('panduan', 'help.index')->name('help');
    Route::get('peta/data', [MapController::class, 'data'])->name('map.data');
    Route::get('peta/denah/{plan}', [MapController::class, 'planImage'])->name('map.plan.image')->whereNumber('plan');
    Route::get('problem', [ProblemController::class, 'index'])->name('problems.index');
    Route::get('insiden', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('insiden.csv', [IncidentController::class, 'csv'])->name('incidents.csv');
    Route::get('insiden/{incident}', [IncidentController::class, 'show'])->name('incidents.show')->whereNumber('incident');
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::get('piket', [OncallController::class, 'index'])->name('oncall.index');
    Route::get('anomali', [AnomalyController::class, 'index'])->name('anomalies.index');
    Route::get('trap', [TrapController::class, 'index'])->name('traps.index');
    Route::get('sensor', [SensorController::class, 'index'])->name('sensors.index');
    Route::get('kualitas', [QualityController::class, 'index'])->name('quality.index');
    Route::get('cctv', [CctvController::class, 'index'])->name('cctv.index');
    Route::post('cctv/{camera}/live', [CctvController::class, 'live'])->name('cctv.live')->middleware('throttle:60,1');
    Route::get('ipam', [IpamController::class, 'index'])->name('ipam.index');
    Route::get('ipam/cari', [IpamController::class, 'search'])->name('ipam.search');
    Route::get('ipam/subnet/{subnet}', [IpamController::class, 'show'])->name('ipam.show')->whereNumber('subnet');
    Route::get('trigger', [TriggerController::class, 'index'])->name('triggers.index');
    Route::get('kapasitas', [CapacityController::class, 'index'])->name('capacity.index');
    Route::get('kapasitas.csv', [CapacityController::class, 'csv'])->name('capacity.csv');
    Route::get('layanan', [ServiceController::class, 'index'])->name('services.index');
    Route::get('layanan.csv', [ServiceController::class, 'csv'])->name('services.csv');
    Route::get('layanan/{service}', [ServiceController::class, 'show'])->name('services.show')->whereNumber('service');
    Route::get('laporan/insiden', [IncidentReportController::class, 'index'])->name('reports.incidents');
    Route::get('laporan/insiden.csv', [IncidentReportController::class, 'csv'])->name('reports.incidents.csv');
    Route::get('laporan/sla', [ReportController::class, 'sla'])->name('reports.sla');
    Route::get('laporan/sla.csv', [ReportController::class, 'csv'])->name('reports.sla.csv');
    Route::get('topologi', [TopologyController::class, 'index'])->name('topology.index');
    Route::get('topologi/data', [TopologyController::class, 'data'])->name('topology.data');
    Route::post('topologi/live', [TopologyController::class, 'live'])->name('topology.live')->middleware('throttle:40,1');
    Route::get('topologi/port/{device}', [TopologyController::class, 'ports'])->name('topology.ports');
    Route::get('noc', [NocController::class, 'index'])->name('noc');
    Route::get('noc/data', [NocController::class, 'data'])->name('noc.data');

    Route::get('profil', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('profil/kontak', [ProfileController::class, 'updateContact'])->name('profile.contact');

    Route::middleware('can:admin')->group(function () {
        Route::post('log/pengaturan', [LogController::class, 'settings'])->name('logs.settings');
        Route::post('trap/pengaturan', [TrapController::class, 'settings'])->name('traps.settings');
        Route::post('peta/pengaturan', [MapController::class, 'settings'])->name('map.settings');
        Route::get('tautan-status', [StatusLinkController::class, 'index'])->name('status.links');
        Route::get('sistem', [SystemController::class, 'index'])->name('system.index');
        Route::post('langkah-awal/sembunyikan', [SystemController::class, 'hideOnboarding'])->name('onboarding.hide');
        Route::post('langkah-awal/tampilkan', [SystemController::class, 'showOnboarding'])->name('onboarding.show');
        Route::get('ekspor-impor', [ConfigTransferController::class, 'index'])->name('transfer.index');
        Route::post('ekspor-impor/ekspor', [ConfigTransferController::class, 'export'])->name('transfer.export')->middleware('throttle:10,1');
        Route::post('ekspor-impor/pratinjau', [ConfigTransferController::class, 'preview'])->name('transfer.preview')->middleware('throttle:10,1');
        Route::post('ekspor-impor/terapkan', [ConfigTransferController::class, 'apply'])->name('transfer.apply')->middleware('throttle:10,1');
        Route::post('tautan-status', [StatusLinkController::class, 'store'])->name('status.links.store')->middleware('throttle:20,1');
        Route::post('tautan-status/{link}/cabut', [StatusLinkController::class, 'revoke'])->name('status.links.revoke')->whereNumber('link');
        Route::get('token-api', [ApiTokenController::class, 'index'])->name('apitokens.index');
        Route::post('token-api', [ApiTokenController::class, 'store'])->name('apitokens.store')->middleware('throttle:20,1');
        Route::post('token-api/{token}/cabut', [ApiTokenController::class, 'revoke'])->name('apitokens.revoke')->whereNumber('token');
        Route::post('peta/denah', [MapController::class, 'planStore'])->name('map.plan.store')->middleware('throttle:10,1');
        Route::delete('peta/denah/{plan}', [MapController::class, 'planDestroy'])->name('map.plan.destroy')->whereNumber('plan');
        Route::get('pengguna', [UserController::class, 'index'])->name('users.index');
        Route::get('pengguna/tambah', [UserController::class, 'create'])->name('users.create');
        Route::post('pengguna', [UserController::class, 'store'])->name('users.store');
        Route::get('pengguna/{user}/ubah', [UserController::class, 'edit'])->name('users.edit');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        Route::get('notifikasi', [ChannelController::class, 'index'])->name('channels.index');
        Route::put('anomali/setelan', [AnomalyController::class, 'settings'])->name('anomalies.settings');
        Route::get('laporan/terjadwal', [ReportScheduleController::class, 'index'])->name('reports.schedules');
        Route::post('laporan/terjadwal', [ReportScheduleController::class, 'store'])->name('reports.schedules.store');
        Route::put('laporan/terjadwal/{schedule}', [ReportScheduleController::class, 'update'])->name('reports.schedules.update')->whereNumber('schedule');
        Route::delete('laporan/terjadwal/{schedule}', [ReportScheduleController::class, 'destroy'])->name('reports.schedules.destroy')->whereNumber('schedule');
        Route::post('laporan/terjadwal/{schedule}/kirim', [ReportScheduleController::class, 'sendNow'])->name('reports.schedules.send')->whereNumber('schedule')->middleware('throttle:10,1');
        Route::get('laporan/terjadwal/{schedule}/pratinjau', [ReportScheduleController::class, 'preview'])->name('reports.schedules.preview')->whereNumber('schedule')->middleware('throttle:20,1');
        Route::get('notifikasi/tambah', [ChannelController::class, 'create'])->name('channels.create');
        Route::post('notifikasi', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('notifikasi/{channel}/ubah', [ChannelController::class, 'edit'])->name('channels.edit');
        Route::put('notifikasi/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('notifikasi/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('notifikasi/{channel}/uji', [ChannelController::class, 'test'])->name('channels.test')->middleware('throttle:10,1');
        Route::put('ambang', [ChannelController::class, 'thresholds'])->name('thresholds.update');
        Route::put('laporan/sla/target', [ReportController::class, 'target'])->name('reports.sla.target');
        Route::put('kapasitas/batas', [CapacityController::class, 'threshold'])->name('capacity.threshold');

        Route::get('backup', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backup', [BackupController::class, 'store'])->name('backups.store')->middleware('throttle:6,1');
        Route::put('backup/pengaturan', [BackupController::class, 'settings'])->name('backups.settings');
        Route::get('backup/{backup}/unduh', [BackupController::class, 'download'])->name('backups.download')->whereNumber('backup');
    });
});
