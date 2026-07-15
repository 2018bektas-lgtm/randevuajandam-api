<?php

namespace App\Http\Middleware;

use App\Models\DoktorApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token auth for doctor panel API.
 * Optionally enforces that token doktor matches X-Api-Key site binding.
 */
class AuthenticateDoctorApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['success' => false, 'message' => 'Oturum gerekli.'], 401);
        }

        $token = DoktorApiToken::findByPlainToken($bearer);
        if ($token) {
            $token->loadMissing('doktor');
        }

        if (! $token || ! $token->isValid() || ! $token->doktor) {
            return response()->json(['success' => false, 'message' => 'Geçersiz veya süresi dolmuş oturum.'], 401);
        }

        $doktor = $token->doktor;
        if (! $doktor->aktif_mi) {
            return response()->json(['success' => false, 'message' => 'Hesap pasif.'], 403);
        }

        // Doctor-site key: only the owner doctor may use the panel
        $siteDoktor = $request->attributes->get('doktor');
        if ($siteDoktor && (int) $siteDoktor->id !== (int) $doktor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bu site için yetkiniz yok.',
            ], 403);
        }

        // Clinic-site key: doctor must belong to this clinic
        $klinik = $request->attributes->get('klinik');
        if ($klinik && (int) ($doktor->klinik_id ?? 0) !== (int) $klinik->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bu klinik sitesi için yetkiniz yok.',
            ], 403);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('auth_doktor', $doktor);
        $request->attributes->set('auth_token', $token);

        // Clinic panel: expose authenticated doctor as `doktor` for package/feature checks
        if ($klinik && ! $siteDoktor) {
            $request->attributes->set('doktor', $doktor);
        }

        return $next($request);
    }
}
