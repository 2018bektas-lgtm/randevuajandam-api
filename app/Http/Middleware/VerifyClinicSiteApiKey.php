<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve clinic from X-Api-Key (+ X-Api-Secret) for clinic public site.
 */
class VerifyClinicSiteApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        if (! is_string($key) || $key === '') {
            return response()->json([
                'success' => false,
                'message' => 'API anahtarı gerekli. X-Api-Key başlığını gönderin.',
            ], 401);
        }

        $apiKey = ApiKey::query()
            ->where('api_key', $key)
            ->where('durum', true)
            ->whereNotNull('klinik_id')
            ->first();

        if (! $apiKey || ! $apiKey->klinik_id) {
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz veya pasif klinik API anahtarı.',
            ], 401);
        }

        $storedSecret = (string) ($apiKey->secret_key ?? '');
        if ($storedSecret === '') {
            return response()->json([
                'success' => false,
                'message' => 'API secret yapılandırılmamış. Ana sunucudan secret key oluşturun.',
            ], 401);
        }

        $provided = $request->header('X-Api-Secret')
            ?? $request->header('X-Secret-Key');

        if (! $apiKey->verifySecret(is_string($provided) ? $provided : null)) {
            return response()->json([
                'success' => false,
                'message' => 'API secret key hatalı veya eksik. X-Api-Secret başlığını gönderin.',
            ], 401);
        }

        $klinik = $apiKey->klinik;
        if (! $klinik || ! $klinik->aktif_mi) {
            return response()->json([
                'success' => false,
                'message' => 'Klinik hesabı aktif değil.',
            ], 403);
        }

        if (! $klinik->hasWebSitesiFeature()) {
            return response()->json([
                'success' => false,
                'message' => 'Klinik web sitesi bu pakette yer almıyor (Klinik Kurumsal gerekli).',
            ], 403);
        }

        $apiKey->touchUsage();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('klinik', $klinik);

        return $next($request);
    }
}
