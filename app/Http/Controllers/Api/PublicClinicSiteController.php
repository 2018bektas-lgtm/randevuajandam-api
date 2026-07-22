<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doktor;
use App\Models\Klinik;
use App\Services\AppointmentBookingService;
use App\Services\RandevuOtpService;
use App\Services\SlotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Public API for multi-doctor clinic websites (Kurumsal paket).
 */
class PublicClinicSiteController extends Controller
{
    protected function klinik(Request $request): Klinik
    {
        /** @var Klinik $klinik */
        $klinik = $request->attributes->get('klinik');

        return $klinik;
    }

    public function profile(Request $request): JsonResponse
    {
        $klinik = $this->klinik($request)->load(['il', 'ilce', 'paket']);

        $saatler = $klinik->calisma_saatleri;
        if (! is_array($saatler)) {
            $saatler = [];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $klinik->id,
                'ad' => $klinik->ad,
                'slug' => $klinik->slug,
                'logo' => $klinik->logo ? site_media_url($klinik->logo) : null,
                'telefon' => $klinik->telefon,
                'e_posta' => $klinik->e_posta,
                'adres' => $klinik->adres,
                'il' => $klinik->il?->ad,
                'ilce' => $klinik->ilce?->ad,
                'enlem' => $klinik->enlem,
                'boylam' => $klinik->boylam,
                'aciklama' => $klinik->aciklama,
                'web_sitesi' => $klinik->web_sitesi,
                'calisma_saatleri' => $saatler,
                'sosyal' => is_array($klinik->sosyal_medya) ? $klinik->sosyal_medya : [],
                'meta_baslik' => $klinik->meta_baslik,
                'meta_aciklama' => $klinik->meta_aciklama,
                'hekim_sayisi' => $klinik->doktorlar()->where('aktif_mi', true)->count(),
                'max_doktor_sayisi' => $klinik->efektifDoktorLimiti(),
                'dahil_doktor_limiti' => $klinik->dahilDoktorLimiti(),
                'ek_doktor_koltuk_sayisi' => (int) $klinik->ek_doktor_koltuk_sayisi,
                'doktor_limiti_doldu_mu' => $klinik->doktorLimitiDolduMu(),
            ],
        ]);
    }

    public function doctors(Request $request): JsonResponse
    {
        $klinik = $this->klinik($request);

        $list = $klinik->doktorlar()
            ->where('aktif_mi', true)
            ->with(['branslar', 'il', 'ilce'])
            ->orderBy('ad_soyad')
            ->get()
            ->map(fn (Doktor $d) => $this->mapDoctor($d));

        return response()->json(['success' => true, 'data' => $list]);
    }

    public function doctorShow(Request $request, string $idOrSlug): JsonResponse
    {
        $klinik = $this->klinik($request);

        $doktor = $klinik->doktorlar()
            ->where('aktif_mi', true)
            ->where(function ($q) use ($idOrSlug) {
                $q->where('id', $idOrSlug)
                    ->orWhere('slug', $idOrSlug);
            })
            ->with(['branslar', 'il', 'ilce', 'calismaSaatleri', 'randevuAyari'])
            ->first();

        if (! $doktor) {
            return response()->json(['success' => false, 'message' => 'Hekim bulunamadı.'], 404);
        }

        $data = $this->mapDoctor($doktor, true);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function services(Request $request): JsonResponse
    {
        $klinik = $this->klinik($request);
        $doktorId = $request->query('doktor_id');

        $doktorIds = $klinik->doktorlar()->where('aktif_mi', true)->pluck('id');

        $query = \App\Models\Hizmet::query()
            ->whereIn('doktor_id', $doktorIds)
            ->where('aktif_mi', true)
            ->with('doktor:id,ad_soyad,unvan');

        if ($doktorId) {
            $query->where('doktor_id', (int) $doktorId);
        }

        $hizmetler = $query->orderBy('ad')->get()->map(fn ($h) => [
            'id' => $h->id,
            'ad' => $h->ad,
            'aciklama' => $h->aciklama,
            'sure' => $h->sure,
            'fiyat' => $h->fiyat,
            'slug' => $h->slug,
            'resim' => site_media_url($h->resim),
            'doktor_id' => $h->doktor_id,
            'doktor_adi' => trim(($h->doktor?->unvan ?? '').' '.($h->doktor?->ad_soyad ?? '')),
        ]);

        return response()->json(['success' => true, 'data' => $hizmetler]);
    }

    public function siteContent(Request $request): JsonResponse
    {
        $klinik = $this->klinik($request);
        $doktorIds = $klinik->doktorlar()->where('aktif_mi', true)->pluck('id');

        // Aggregate content from clinic doctors (MVP)
        $bloglar = \App\Models\Blog::query()
            ->whereIn('doktor_id', $doktorIds)
            ->where('aktif_mi', true)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'slug' => $b->slug ?? Str::slug($b->baslik).'-'.$b->id,
                'baslik' => $b->baslik,
                'ozet' => Str::limit(strip_tags((string) $b->icerik), 160),
                'icerik' => $b->icerik,
                'resim' => $b->resim ? site_media_url($b->resim) : null,
                'tarih' => optional($b->created_at)->format('d F Y'),
                'doktor_id' => $b->doktor_id,
            ]);

        $faqs = \App\Models\Faq::query()
            ->whereIn('doktor_id', $doktorIds)
            ->where('aktif', true)
            ->orderBy('sira')
            ->limit(50)
            ->get(['id', 'soru', 'cevap', 'sira', 'doktor_id']);

        $galeri = \App\Models\DoktorGaleri::query()
            ->whereIn('doktor_id', $doktorIds)
            ->orderBy('sira')
            ->limit(40)
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'baslik' => $g->baslik,
                'image' => site_media_url($g->resim_yolu),
                'sira' => $g->sira,
            ]);

        $yorumlar = \App\Models\Yorum::query()
            ->whereIn('doktor_id', $doktorIds)
            ->where('onay_durumu', 'onaylandi')
            ->with('hasta:id,ad,soyad')
            ->latest()
            ->limit(30)
            ->get()
            ->map(function ($y) {
                $ad = trim(($y->hasta?->ad ?? '').' '.($y->hasta?->soyad ?? ''));

                return [
                    'id' => $y->id,
                    'ad' => $ad !== '' ? $ad : 'Hasta',
                    'metin' => $y->yorum ?? '',
                    'puan' => (int) ($y->puan ?? 5),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'bloglar' => $bloglar,
                'faqs' => $faqs,
                'galeri' => $galeri,
                'yorumlar' => $yorumlar,
            ],
        ]);
    }

    public function slots(Request $request, SlotService $slotService): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'doktor_id' => ['required', 'integer'],
        ], [
            'doktor_id.required' => 'Hekim seçimi zorunludur.',
            'date.required' => 'Tarih zorunludur.',
        ]);

        $klinik = $this->klinik($request);
        $doktor = $this->resolveClinicDoctor($klinik, (int) $request->doktor_id);

        if (! $doktor->randevuya_acik_mi) {
            return response()->json([
                'success' => true,
                'data' => ['date' => $request->date, 'slots' => [], 'message' => 'Hekim online randevuya kapalı.'],
            ]);
        }

        $tarih = Carbon::parse($request->date)->startOfDay();
        $periyot = $slotService->getPeriyot($doktor);

        $randevular = $doktor->randevular()
            ->whereDate('tarih', $tarih->toDateString())
            ->whereIn('durum', ['beklemede', 'onaylandi', 'tamamlandi'])
            ->get();

        $izinler = $doktor->izinler()
            ->where('baslangic_zaman', '<=', $tarih->copy()->endOfDay())
            ->where('bitis_zaman', '>=', $tarih->copy()->startOfDay())
            ->get();

        $gunluk = $slotService->generateGunlukSlotlar($doktor, $tarih, $randevular, $izinler, $periyot);

        $bos = collect($gunluk)
            ->where('durum', 'bos')
            ->map(fn ($s) => [
                'saat' => $s['saat_string'],
                'saat_bitis' => $s['saat_bitis'],
            ])
            ->values();

        if ($tarih->isToday()) {
            $now = now()->format('H:i');
            $bos = $bos->filter(fn ($s) => $s['saat'] > $now)->values();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $tarih->toDateString(),
                'doktor_id' => $doktor->id,
                'periyot' => $periyot,
                'slots' => $bos,
            ],
        ]);
    }

    public function sendOtp(Request $request, RandevuOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'telefon' => ['required', 'string', 'max:30'],
            'doktor_id' => ['required', 'integer'],
        ]);

        $klinik = $this->klinik($request);
        $doktor = $this->resolveClinicDoctor($klinik, (int) $validated['doktor_id']);

        try {
            $otpService->send($validated['telefon'], $doktor->id, $request->ip());
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Doğrulama kodu telefonunuza gönderildi.',
            'data' => ['otp_required' => $otpService->isRequired(), 'expires_in' => 300],
        ]);
    }

    public function verifyOtp(Request $request, RandevuOtpService $otpService): JsonResponse
    {
        $validated = $request->validate([
            'telefon' => ['required', 'string', 'max:30'],
            'kod' => ['required', 'string', 'size:6'],
            'doktor_id' => ['required', 'integer'],
        ]);

        $klinik = $this->klinik($request);
        $doktor = $this->resolveClinicDoctor($klinik, (int) $validated['doktor_id']);

        try {
            $otpService->verify($validated['telefon'], $doktor->id, $validated['kod']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Telefon numaranız doğrulandı.']);
    }

    public function storeAppointment(
        Request $request,
        AppointmentBookingService $bookingService,
        RandevuOtpService $otpService,
    ): JsonResponse {
        $klinik = $this->klinik($request);

        $hp = config('randevu.honeypot_field', 'website_url');
        if ($request->filled($hp)) {
            return response()->json(['success' => false, 'message' => 'Geçersiz istek.'], 422);
        }

        $throttleKey = 'public-clinic-randevu:'.$klinik->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            $saniye = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'success' => false,
                'message' => "Çok fazla istek. Lütfen {$saniye} saniye sonra tekrar deneyin.",
            ], 429);
        }

        // reCAPTCHA: klinik public proxy doğrular; API anahtarı sunucu tarafı.

        $validated = $request->validate([
            'doktor_id' => ['required', 'integer'],
            'hizmet_id' => ['required', 'integer', 'exists:hizmetler,id'],
            'tarih' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'saat' => ['required', 'date_format:H:i'],
            'ad' => ['required', 'string', 'max:100'],
            'soyad' => ['required', 'string', 'max:100'],
            'telefon' => ['required', 'string', 'max:30'],
            'e_posta' => ['nullable', 'email', 'max:255'],
            'not' => ['nullable', 'string', 'max:1000'],
            'gorusme_tipi' => ['nullable', 'in:yuz_yuze,online'],
            'kvkk_onay' => ['accepted'],
            'otp_kod' => ['nullable', 'string', 'size:6'],
            'recaptcha_token' => ['nullable', 'string'],
        ], [
            'doktor_id.required' => 'Hekim seçimi zorunludur.',
            'kvkk_onay.accepted' => 'KVKK onayı zorunludur.',
        ]);

        try {
            $doktor = $this->resolveClinicDoctor($klinik, (int) $validated['doktor_id']);

            if (! empty($validated['otp_kod'])) {
                $otpService->verify($validated['telefon'], $doktor->id, $validated['otp_kod']);
            } else {
                $otpService->assertVerifiedIfRequired($validated['telefon'], $doktor->id);
            }

            $randevu = $bookingService->createFromGuest($doktor, [
                'hizmet_id' => (int) $validated['hizmet_id'],
                'tarih' => $validated['tarih'],
                'saat' => $validated['saat'],
                'ad' => $validated['ad'],
                'soyad' => $validated['soyad'],
                'telefon' => $validated['telefon'],
                'e_posta' => $validated['e_posta'] ?? null,
                'not' => $validated['not'] ?? null,
                'gorusme_tipi' => $validated['gorusme_tipi'] ?? 'yuz_yuze',
            ]);

            $otpService->clearVerified($validated['telefon'], $doktor->id);
        } catch (InvalidArgumentException $e) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Clinic public appointment error', [
                'klinik_id' => $klinik->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Randevu kaydı sırasında bir hata oluştu.',
            ], 500);
        }

        RateLimiter::hit($throttleKey, 300);

        $siteUrl = rtrim((string) env('SITE_URL', config('app.url')), '/');
        $platformJoinUrl = null;
        if (($randevu->gorusme_tipi ?? '') === 'online' && $randevu->meeting_join_token) {
            $platformJoinUrl = $siteUrl.'/gorusme/'.$randevu->meeting_join_token;
        }

        return response()->json([
            'success' => true,
            'message' => 'Randevu talebiniz alındı.',
            'data' => [
                'id' => $randevu->id,
                'tarih' => $validated['tarih'],
                'saat' => $validated['saat'],
                'durum' => $randevu->durum,
                'gorusme_tipi' => $randevu->gorusme_tipi ?? 'yuz_yuze',
                'doktor_id' => $doktor->id,
                'yonetim_url' => $siteUrl.'/randevu-yonet/'.$randevu->yonetim_token,
                'platform_join_url' => $platformJoinUrl,
            ],
        ], 201);
    }

    protected function resolveClinicDoctor(Klinik $klinik, int $doktorId): Doktor
    {
        $doktor = $klinik->doktorlar()
            ->where('id', $doktorId)
            ->where('aktif_mi', true)
            ->first();

        if (! $doktor) {
            throw new InvalidArgumentException('Seçilen hekim bu kliniğe ait değil veya aktif değil.');
        }

        return $doktor;
    }

    protected function mapDoctor(Doktor $d, bool $detail = false): array
    {
        $base = [
            'id' => $d->id,
            'slug' => $d->slug ?? Str::slug($d->ad_soyad).'-'.$d->id,
            'ad_soyad' => $d->ad_soyad,
            'unvan' => $d->unvan,
            'uzmanlik_alani' => $d->uzmanlik_alani,
            'profil_resmi' => site_media_url($d->profil_resmi),
            'branslar' => $d->relationLoaded('branslar')
                ? $d->branslar->pluck('ad')->values()
                : [],
            'randevuya_acik_mi' => (bool) $d->randevuya_acik_mi,
            'online_gorusme' => (bool) ($d->aktifPaket()?->hasFeature('online_gorusme')),
            'kisa_bio' => Str::limit(strip_tags((string) ($d->biyografi ?? '')), 180),
        ];

        if ($detail) {
            $base['biyografi'] = $d->biyografi;
            $base['telefon'] = $d->telefon;
            $base['e_posta'] = $d->e_posta;
            $base['mezuniyet'] = $d->mezuniyet ?? [];
        }

        return $base;
    }
}
