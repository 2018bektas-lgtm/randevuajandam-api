<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production: HTTPS zorlar ve temel guvenlik basliklarini ekler.
 *
 * randevuajandam-site ile ayni davranis; bu projelerde hic yoktu, dolayisiyla
 * hekim/klinik siteleri clickjacking ve MIME sniffing'e acikti.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->secure()) {
            // Reverse proxy arkasindaysa X-Forwarded-Proto'ya guven
            if ($request->header('X-Forwarded-Proto') === 'https') {
                $request->server->set('HTTPS', 'on');
            } else {
                return redirect()->secure($request->getRequestUri(), 301);
            }
        }

        /** @var Response $response */
        $response = $next($request);

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=()');
        }

        return $response;
    }
}
