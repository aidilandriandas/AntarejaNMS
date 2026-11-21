<?php

namespace App\Providers;

use App\Models\User;
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
        Gate::define('manage-devices', fn (User $u) => $u->canManageDevices());

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
