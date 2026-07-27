<?php

/**
 * Shared media / uploads between API and main site.
 * Must live in config (not bare env()) so config:cache works in production.
 */
return [

    /**
     * Absolute path to the main site public directory (where /uploads lives).
     * Production example: /home/u195737737/apps/randevuajandam-site/public
     */
    'shared_public_path' => env('SHARED_PUBLIC_PATH'),

    /**
     * Public base URL for media links (prefer site, not api.randevuajandam.com).
     */
    'url' => env('MEDIA_URL') ?: env('SITE_URL') ?: env('APP_URL', 'http://127.0.0.1:8000'),
];
