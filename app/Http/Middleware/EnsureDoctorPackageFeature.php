<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doctor panel API: require active package feature (same codes as site paket.yetki).
 * Multiple features = OR (comma-separated or multiple params).
 */
class EnsureDoctorPackageFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $codes = [];
        foreach ($features as $f) {
            foreach (explode(',', $f) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $codes[] = $part;
                }
            }
        }
        $codes = array_values(array_unique($codes));

        $doktor = $request->attributes->get('auth_doktor')
            ?? $request->attributes->get('doktor');

        if (! $doktor) {
            return response()->json([
                'success' => false,
                'message' => 'Hekim oturumu bulunamadı.',
            ], 401);
        }

        $paket = method_exists($doktor, 'aktifPaket') ? $doktor->aktifPaket() : null;

        $ok = $paket && (
            method_exists($paket, 'hasAnyFeature')
                ? $paket->hasAnyFeature($codes)
                : collect($codes)->contains(fn ($c) => $paket->hasFeature($c))
        );

        if (! $ok) {
            return response()->json([
                'success' => false,
                'message' => 'Bu özellik mevcut üyelik paketinizde yer almamaktadır. Lütfen paketinizi yükseltin.',
                'feature' => $codes[0] ?? 'paket',
                'features' => $codes,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
