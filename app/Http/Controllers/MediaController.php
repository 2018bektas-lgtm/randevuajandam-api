<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve files from the main site public folder (SHARED_PUBLIC_PATH).
 * Doctor-site and API clients load uploads via: /media/uploads/...
 */
class MediaController extends Controller
{
    public function show(Request $request, string $path): BinaryFileResponse
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        // Path traversal guard
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        $root = (string) app('shared_public_path');
        $rootReal = realpath($root);
        if (! $rootReal || ! is_dir($rootReal)) {
            abort(404, 'Medya kökü yapılandırılmamış.');
        }

        $candidate = $rootReal.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $full = realpath($candidate);

        if (! $full || ! is_file($full) || ! str_starts_with($full, $rootReal)) {
            abort(404);
        }

        // Cache static uploads briefly
        return response()->file($full, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
