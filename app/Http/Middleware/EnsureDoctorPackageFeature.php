<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doctor panel API: require active package feature (same codes as site paket.yetki).
 */
class EnsureDoctorPackageFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $doktor = $request->attributes->get('auth_doktor')
            ?? $request->attributes->get('doktor');

        if (! $doktor) {
            return response()->json([
                'success' => false,
                'message' => 'Hekim oturumu bulunamadı.',
            ], 401);
        }

        $paket = $doktor->aktifPaket();

        if (! $paket || ! $paket->hasFeature($feature)) {
            return response()->json([
                'success' => false,
                'message' => 'Bu özellik mevcut üyelik paketinizde yer almamaktadır. Lütfen paketinizi yükseltin.',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
