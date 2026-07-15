<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDoctorSiteApiKey
{
    /**
     * Resolve doctor from X-Api-Key (+ optional X-Api-Secret) headers only.
     * Requires bireysel package feature web_sitesi.
     */
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
            ->first();

        if (! $apiKey || ! $apiKey->doktor_id) {
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz veya pasif API anahtarı.',
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

        $doktor = $apiKey->doktor;
        if (! $doktor || ! $doktor->aktif_mi) {
            return response()->json([
                'success' => false,
                'message' => 'Hekim hesabı aktif değil.',
            ], 403);
        }

        $paket = $doktor->aktifPaket();
        if (! $paket || ! $paket->hasFeature('web_sitesi')) {
            return response()->json([
                'success' => false,
                'message' => 'Hekim web sitesi bu pakette yer almıyor (Özel Web Sitesi paketi gerekli).',
            ], 403);
        }

        $apiKey->touchUsage();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('doktor', $doktor);

        return $next($request);
    }
}
