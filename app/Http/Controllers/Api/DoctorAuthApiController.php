<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\TotpHelper;
use App\Models\Doktor;
use App\Models\DoktorApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class DoctorAuthApiController extends Controller
{
    /**
     * Doctor login — same credentials as main platform (e_posta + sifre).
     * Requires X-Api-Key of the doctor site; only that site's doctor can log in.
     * If 2FA is enabled returns requires_two_factor + challenge_token (no bearer yet).
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
     * Complete login after password step when 2FA is required.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        $challenge = Cache::get($this->challengeCacheKey($data['challenge_token']));
        if (! is_array($challenge)) {
            return response()->json([
                'success' => false,
                'message' => 'Doğrulama oturumu sona erdi. Lütfen tekrar giriş yapın.',
            ], 422);
        }

        $throttleKey = 'doctor-api-2fa:'.hash('sha256', $data['challenge_token']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            return response()->json(['success' => false, 'message' => 'Çok fazla deneme. Lütfen bekleyin.'], 429);
        }

        $doktor = Doktor::find($challenge['doktor_id'] ?? null);
        if (! $doktor || ! $doktor->aktif_mi || ! $this->hasTwoFactor($doktor)) {
            Cache::forget($this->challengeCacheKey($data['challenge_token']));

            return response()->json(['success' => false, 'message' => 'Doğrulama oturumu geçersiz.'], 422);
        }

        if (! $this->verifyUserCode($doktor, $data['code'])) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json(['success' => false, 'message' => 'Doğrulama kodu hatalı.'], 422);
        }

        RateLimiter::clear($throttleKey);
        Cache::forget($this->challengeCacheKey($data['challenge_token']));

        $tokenName = (string) ($challenge['token_name'] ?? 'doctor-site-panel');

        return $this->issueTokenResponse($doktor, $tokenName, $request->ip());
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

        if ($this->hasTwoFactor($doktor)) {
            $challenge = Str::random(64);
            Cache::put(
                $this->challengeCacheKey($challenge),
                [
                    'doktor_id' => $doktor->id,
                    'token_name' => $tokenName,
                ],
                now()->addMinutes(5)
            );

            return response()->json([
                'success' => true,
                'message' => 'İki adımlı doğrulama gerekli.',
                'data' => [
                    'requires_two_factor' => true,
                    'challenge_token' => $challenge,
                ],
            ]);
        }

        return $this->issueTokenResponse($doktor, $tokenName, $request->ip());
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

    // ── Two-factor setup (authenticated) ─────────────────────────

    public function twoFactorStatus(Request $request): JsonResponse
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('auth_doktor');
        $enabled = $this->hasTwoFactor($doktor);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $enabled,
                'confirmed_at' => $doktor->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_count' => is_array($doktor->two_factor_recovery_codes)
                    ? count($doktor->two_factor_recovery_codes)
                    : 0,
            ],
        ]);
    }

    public function twoFactorBeginSetup(Request $request): JsonResponse
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('auth_doktor');
        if ($this->hasTwoFactor($doktor)) {
            return response()->json([
                'success' => false,
                'message' => 'İki adımlı doğrulama zaten açık.',
            ], 422);
        }

        $secret = TotpHelper::generateSecret();
        Cache::put($this->setupCacheKey($doktor->id), $secret, now()->addMinutes(15));

        $company = config('app.name', 'Randevu Ajandam');
        $email = (string) $doktor->e_posta;
        $otpauth = TotpHelper::otpauthUrl($company, $email, $secret);
        $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.urlencode($otpauth);

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $secret,
                'otpauth_url' => $otpauth,
                'qr_image_url' => $qrImageUrl,
            ],
        ]);
    }

    public function twoFactorConfirmSetup(Request $request): JsonResponse
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('auth_doktor');
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:12'],
        ]);

        $secret = (string) Cache::get($this->setupCacheKey($doktor->id), '');
        if ($secret === '' || ! TotpHelper::verify($secret, $data['code'])) {
            return response()->json([
                'success' => false,
                'message' => 'Kod doğrulanamadı. Authenticator uygulamasındaki 6 haneli kodu girin.',
            ], 422);
        }

        $recovery = TotpHelper::recoveryCodes();
        $doktor->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery,
            'two_factor_confirmed_at' => now(),
        ])->save();
        Cache::forget($this->setupCacheKey($doktor->id));

        return response()->json([
            'success' => true,
            'message' => 'İki adımlı doğrulama açıldı. Yedek kodları güvenli bir yere kaydedin.',
            'data' => ['recovery_codes' => $recovery],
        ]);
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('auth_doktor');
        $data = $request->validate([
            'sifre' => ['required', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        if (! Hash::check($data['sifre'], $doktor->sifre)) {
            return response()->json(['success' => false, 'message' => 'Şifre hatalı.'], 422);
        }
        if (! $this->verifyUserCode($doktor, $data['code'])) {
            return response()->json(['success' => false, 'message' => 'Doğrulama kodu hatalı.'], 422);
        }

        $doktor->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['success' => true, 'message' => 'İki adımlı doğrulama kapatıldı.']);
    }

    public function twoFactorRegenerateRecovery(Request $request): JsonResponse
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('auth_doktor');
        if (! $this->hasTwoFactor($doktor)) {
            return response()->json(['success' => false, 'message' => '2FA kapalı.'], 422);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        $secret = (string) $doktor->two_factor_secret;
        if (! TotpHelper::verify($secret, $data['code'])) {
            return response()->json(['success' => false, 'message' => 'Authenticator kodu hatalı.'], 422);
        }

        $codes = TotpHelper::recoveryCodes();
        $doktor->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json([
            'success' => true,
            'message' => 'Yeni yedek kodlar oluşturuldu.',
            'data' => ['recovery_codes' => $codes],
        ]);
    }

    protected function issueTokenResponse(Doktor $doktor, string $tokenName, ?string $ip): JsonResponse
    {
        $issued = DoktorApiToken::issue($doktor, $tokenName, $ip);
        /** @var DoktorApiToken $tokenModel */
        $tokenModel = $issued['model'];
        $plainToken = $issued['plain'];

        return response()->json([
            'success' => true,
            'message' => 'Giriş başarılı.',
            'data' => [
                'token' => $plainToken,
                'expires_at' => $tokenModel->expires_at?->toIso8601String(),
                'doktor' => $this->doctorPayload($doktor),
            ],
        ]);
    }

    protected function hasTwoFactor(Doktor $doktor): bool
    {
        if (method_exists($doktor, 'hasTwoFactorEnabled')) {
            return (bool) $doktor->hasTwoFactorEnabled();
        }

        return ! empty($doktor->two_factor_secret) && $doktor->two_factor_confirmed_at !== null;
    }

    protected function verifyUserCode(Doktor $doktor, string $code): bool
    {
        $secret = (string) ($doktor->two_factor_secret ?? '');
        if ($secret !== '' && TotpHelper::verify($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($doktor, $code);
    }

    protected function consumeRecoveryCode(Doktor $doktor, string $code): bool
    {
        $normalized = strtoupper(trim($code));
        $normalizedCompact = str_replace([' ', '-'], '', $normalized);
        $codes = is_array($doktor->two_factor_recovery_codes) ? $doktor->two_factor_recovery_codes : [];
        $idx = false;

        foreach ($codes as $i => $stored) {
            $s = strtoupper(trim((string) $stored));
            $sCompact = str_replace([' ', '-'], '', $s);
            if ($s === $normalized || $sCompact === $normalizedCompact) {
                $idx = $i;
                break;
            }
        }

        if ($idx === false) {
            return false;
        }

        unset($codes[$idx]);
        $doktor->forceFill([
            'two_factor_recovery_codes' => array_values($codes),
        ])->save();

        return true;
    }

    protected function challengeCacheKey(string $token): string
    {
        return 'doctor-api-2fa-challenge:'.hash('sha256', $token);
    }

    protected function setupCacheKey(int $doktorId): string
    {
        return 'doctor-api-2fa-setup:'.$doktorId;
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
            'two_factor_enabled' => $this->hasTwoFactor($doktor),
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
