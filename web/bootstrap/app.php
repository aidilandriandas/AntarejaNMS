<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [\App\Http\Middleware\ForceHttps::class]);
        $middleware->web(prepend: [\App\Http\Middleware\ForceHttps::class], append: [\App\Http\Middleware\EnsureSetup::class, \Illuminate\Session\Middleware\AuthenticateSession::class, \App\Http\Middleware\EnsureActiveUser::class, \App\Http\Middleware\IdleTimeout::class, \App\Http\Middleware\RequireTwoFactor::class, \App\Http\Middleware\RestrictToSites::class, \App\Http\Middleware\SecurityHeaders::class]);
        // Di belakang reverse proxy internal (nginx di container yang sama / proxy kantor)
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'));
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
