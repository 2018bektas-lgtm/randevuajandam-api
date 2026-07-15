<?php

use App\Http\Middleware\AuthenticateDoctorApiToken;
use App\Http\Middleware\EnsureDoctorPackageFeature;
use App\Http\Middleware\VerifyClinicSiteApiKey;
use App\Http\Middleware\VerifyDoctorSiteApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'doctor.site.key' => VerifyDoctorSiteApiKey::class,
            'clinic.site.key' => VerifyClinicSiteApiKey::class,
            'doctor.api.token' => AuthenticateDoctorApiToken::class,
            'doctor.paket' => EnsureDoctorPackageFeature::class,
        ]);

        // API has no CSRF; pure token/key auth
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
