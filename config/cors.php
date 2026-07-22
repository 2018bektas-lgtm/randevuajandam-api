<?php

$originsEnv = (string) env('CORS_ALLOWED_ORIGINS', '');
$origins = array_values(array_filter(array_map('trim', explode(',', $originsEnv))));

// local / boş: geliştirme kolaylığı; production: mutlaka .env ile domain listesi
if ($origins === []) {
    $env = (string) env('APP_ENV', 'production');
    if (in_array($env, ['local', 'testing'], true)) {
        $origins = ['*'];
    } else {
        // Production fallback: sadece SITE_URL ve bilinen localhost portları yok — boş = tarayıcı CORS engeli
        $site = rtrim((string) env('SITE_URL', env('APP_URL', '')), '/');
        $origins = array_values(array_filter([$site]));
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Production: CORS_ALLOWED_ORIGINS=https://site.com,https://doktor.site.com
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Api-Key', 'X-Api-Secret', 'X-Secret-Key', 'X-Doktor-Token', 'X-Personel-Token', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
