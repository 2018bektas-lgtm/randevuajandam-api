<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doktor;
use App\Models\DoktorApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class DoctorAuthApiController extends Controller
{
    /**
     * Doctor login — same credentials as main platform (e_posta + sifre).
     * Requires X-Api-Key of the doctor site; only that site's doctor can log in.
     */
    public function login(Request $request): JsonResponse
    {
        $siteDoktor = $request->attributes->get('doktor');
        if (! $siteDoktor) {
            return response()->json(['success' => false, 'message' => 'Site API anahtarı gerekli.'], 401);
        }

        return $this->attemptLogin($request, function (Doktor $doktor) use ($siteDoktor): ?string {
            if ((int) $doktor->id !== (int) $siteDoktor->id) {
                return 'Bu web sitesi yönetim paneline yalnızca site sahibi hekim giriş yapabilir.';
            }

            return null;
        }, 'doctor-site-panel');
    }

    /**
     * Clinic doctor login — clinic API key; any active doctor of that clinic may log in.
     */
    public function clinicLogin(Request $request): JsonResponse
    {
        $klinik = $request->attributes->get('klinik');
        if (! $klinik) {
            return response()->json(['success' => false, 'message' => 'Klinik API anahtarı gerekli.'], 401);
        }

        return $this->attemptLogin($request, function (Doktor $doktor) use ($klinik): ?string {
            if ((int) ($doktor->klinik_id ?? 0) !== (int) $klinik->id) {
                return 'Bu klinik web sitesi paneline yalnızca bu kliniğe bağlı hekimler giriş yapabilir.';
            }

            return null;
        }, 'clinic-site-panel');
    }

    /**
     * @param  callable(Doktor): (?string)  $authorize  return error message or null if allowed
     */
    protected function attemptLogin(Request $request, callable $authorize, string $tokenName): JsonResponse
    {
        $validated = $request->validate([
            'e_posta' => ['required', 'email'],
            'sifre' => ['required', 'string'],
        ], [
            'e_posta.required' => 'E-posta zorunludur.',
            'sifre.required' => 'Şifre zorunludur.',
        ]);

        $throttleKey = 'doctor-api-login:'.Str::lower($validated['e_posta']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            return response()->json([
                'success' => false,
                'message' => 'Çok fazla başarısız deneme. Lütfen daha sonra tekrar deneyin.',
            ], 429);
        }

        /** @var Doktor|null $doktor */
        $doktor = Doktor::where('e_posta', $validated['e_posta'])->first();

        if (! $doktor || ! Hash::check($validated['sifre'], $doktor->sifre)) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'success' => false,
                'message' => 'E-posta veya şifre hatalı.',
            ], 422);
        }

        if (! $doktor->aktif_mi) {
            return response()->json(['success' => false, 'message' => 'Hesabınız pasif durumdadır.'], 403);
        }

        $deny = $authorize($doktor);
        if (is_string($deny) && $deny !== '') {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'success' => false,
                'message' => $deny,
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $issued = DoktorApiToken::issue($doktor, $tokenName, $request->ip());
        /** @var DoktorApiToken $tokenModel */
        $tokenModel = $issued['model'];
        $plainToken = $issued['plain'];

        return response()->json([
            'success' => true,
            'message' => 'Giriş başarılı.',
            'data' => [
                'token' => $plainToken, // plain once; DB stores only SHA-256 hash
                'expires_at' => $tokenModel->expires_at?->toIso8601String(),
                'doktor' => $this->doctorPayload($doktor),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('auth_doktor');
        $doktor->load(['il', 'ilce', 'paket', 'randevuAyari', 'branslar']);

        return response()->json([
            'success' => true,
            'data' => $this->doctorPayload($doktor, true),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var DoktorApiToken|null $token */
        $token = $request->attributes->get('auth_token');
        $token?->delete();

        return response()->json(['success' => true, 'message' => 'Çıkış yapıldı.']);
    }

    protected function doctorPayload(Doktor $doktor, bool $detailed = false): array
    {
        $base = [
            'id' => $doktor->id,
            'ad_soyad' => $doktor->ad_soyad,
            'unvan' => $doktor->unvan,
            'e_posta' => $doktor->e_posta,
            'telefon' => $doktor->telefon,
            'uzmanlik_alani' => $doktor->uzmanlik_alani,
            'aktif_mi' => (bool) $doktor->aktif_mi,
            'randevuya_acik_mi' => (bool) $doktor->randevuya_acik_mi,
            'profil_resmi' => site_media_url($doktor->profil_resmi),
        ];

        if (! $detailed) {
            return $base;
        }

        return array_merge($base, [
            'biyografi' => $doktor->biyografi,
            'adres' => $doktor->adres,
            'klinik_adi' => $doktor->klinik_adi,
            'il' => $doktor->il?->ad,
            'ilce' => $doktor->ilce?->ad,
            'il_id' => $doktor->il_id,
            'ilce_id' => $doktor->ilce_id,
            'instagram' => $doktor->instagram,
            'facebook' => $doktor->facebook,
            'twitter' => $doktor->twitter,
            'linkedin' => $doktor->linkedin,
            'youtube' => $doktor->youtube,
            'web_sitesi' => $doktor->web_sitesi,
            'enlem' => $doktor->enlem,
            'boylam' => $doktor->boylam,
            'mezuniyet' => $doktor->mezuniyet,
            'branslar' => $doktor->branslar?->map(fn ($b) => ['id' => $b->id, 'ad' => $b->ad, 'slug' => $b->slug])->values() ?? [],
            'paket' => $doktor->paket?->only(['id', 'ad']),
            'randevu_ayari' => $doktor->randevuAyari,
        ]);
    }
}
