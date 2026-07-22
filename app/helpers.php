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

        // Clean any storage/uploads to uploads/
        if (str_contains($path, 'storage/uploads/')) {
            $path = str_replace('storage/uploads/', 'uploads/', $path);
        }

        // Already absolute — normalize host / path if needed
        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'data:')) {
            // Fix absolute URLs containing /storage/uploads/
            if (preg_match('#^https?://[^/]+(?::\d+)?/storage/uploads/(.+)$#i', $path, $m)) {
                return rtrim(media_public_base(), '/').'/uploads/'.$m[1];
            }
            if (preg_match('#^https?://[^/]+(?::\d+)?/uploads/(.+)$#i', $path, $m)) {
                return rtrim(media_public_base(), '/').'/uploads/'.$m[1];
            }

            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        return rtrim(media_public_base(), '/').'/'.$path;
    }
}

if (! function_exists('media_public_base')) {
    function media_public_base(): string
    {
        $explicit = env('MEDIA_URL') ?: env('SITE_URL');
        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/');
        }

        // Default: site / API base
        return rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/');
    }
}
