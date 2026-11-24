<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceSensor;
use App\Models\Problem;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Gate::define('admin', fn (User $u) => $u->isAdmin());
        // Engineer yang dibatasi site hanya bisa melihat dan ACK problem; perubahan perangkat lewat akun tanpa batas.
        Gate::define('manage-devices', fn (User $u) => $u->canManageDevices() && SiteScope::for($u) === null);
        Gate::define('ack-problems', fn (User $u) => $u->canManageDevices());

        $siteIn = fn () => SiteScope::current();
        Device::addGlobalScope('site', function (Builder $q) use ($siteIn) {
            if (($ids = $siteIn()) !== null) {
                $q->whereIn($q->getModel()->qualifyColumn('site_id'), $ids);
            }
        });
        Site::addGlobalScope('site', function (Builder $q) use ($siteIn) {
            if (($ids = $siteIn()) !== null) {
                $q->whereIn($q->getModel()->qualifyColumn('id'), $ids);
            }
        });
        foreach ([Problem::class, DeviceEvent::class, DeviceSensor::class] as $m) {
            $m::addGlobalScope('site', function (Builder $q) use ($siteIn) {
                if (($ids = $siteIn()) !== null) {
                    $q->whereIn($q->getModel()->qualifyColumn('device_id'), fn ($s) => $s->select('id')->from('devices')->whereIn('site_id', $ids));
                }
            });
        }

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
