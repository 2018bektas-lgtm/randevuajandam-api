<?php

if (! function_exists('site_media_url')) {
    /**
     * Absolute media URL served by this API from SHARED_PUBLIC_PATH.
     * Prefer MEDIA_URL / SITE_URL; fall back to APP_URL/media.
     */
    function site_media_url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // Already absolute — rewrite old :8000 host to API media if needed
        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'data:')) {
            // If client stored full site URL with /uploads, leave as-is when reachable;
            // normalize known local site ports to API media base.
            if (preg_match('#^https?://[^/]+(?::\d+)?/(uploads|storage)/(.+)$#i', $path, $m)) {
                return rtrim(media_public_base(), '/').'/'.$m[1].'/'.$m[2];
            }

            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        return rtrim(media_public_base(), '/').'/'.$path;
    }
}

if (! function_exists('media_public_base')) {
    function media_public_base(): string
    {
        $explicit = env('MEDIA_URL');
        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/');
        }

        // Default: this API host + /media
        return rtrim((string) env('APP_URL', 'http://127.0.0.1:8001'), '/').'/media';
    }
}
