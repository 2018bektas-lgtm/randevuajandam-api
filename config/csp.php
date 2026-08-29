<?php

/**
 * Content-Security-Policy.
 *
 * Varsayilan RAPOR MODU: tarayici hicbir seyi engellemez, yalnizca ihlalleri
 * bildirir. Satir ici <script>/<style> temizlendikce (P2-13) CSP_ENFORCE=true
 * ile zorunlu moda gecilir.
 */
return [
    'enabled' => filter_var(env('CSP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'enforce' => filter_var(env('CSP_ENFORCE', false), FILTER_VALIDATE_BOOLEAN),
    'report_uri' => env('CSP_REPORT_URI', ''),
];
