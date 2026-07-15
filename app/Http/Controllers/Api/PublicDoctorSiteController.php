<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doktor;
use App\Models\Egitim;
use App\Models\Randevu;
use App\Services\AppointmentBookingService;
use App\Services\EgitimBasvuruService;
use App\Services\RandevuOtpService;
use App\Services\SlotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublicDoctorSiteController extends Controller
{
    protected function doktor(Request $request): Doktor
    {
        /** @var Doktor $doktor */
        $doktor = $request->attributes->get('doktor');

        return $doktor;
    }

    /**
     * Public doctor profile for doctor-site landing.
     */
    public function profile(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request)->load(['il', 'ilce', 'branslar', 'randevuAyari', 'calismaSaatleri']);

        $gunAdlari = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
        $calisma = [];
        foreach ($doktor->calismaSaatleri as $cs) {
            $gun = $gunAdlari[(int) $cs->gun] ?? ('Gün '.$cs->gun);
            if (! $cs->aktif_mi) {
                $calisma[$gun] = 'Kapalı';
            } else {
                $bas = substr((string) $cs->mesai_baslangic, 0, 5);
                $bit = substr((string) $cs->mesai_bitis, 0, 5);
                $calisma[$gun] = $bas.' – '.$bit;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $doktor->id,
                'ad_soyad' => $doktor->ad_soyad,
                'unvan' => $doktor->unvan,
                'uzmanlik_alani' => $doktor->uzmanlik_alani,
                'biyografi' => $doktor->biyografi,
                'mezuniyet' => $doktor->mezuniyet ?? [],
                'telefon' => $doktor->telefon,
                'e_posta' => $doktor->e_posta,
                'adres' => $doktor->adres,
                'klinik_adi' => $doktor->klinik_adi ?? null,
                'profil_resmi' => site_media_url($doktor->profil_resmi),
                'il' => $doktor->il?->ad,
                'ilce' => $doktor->ilce?->ad,
                'branslar' => $doktor->branslar->pluck('ad')->values(),
                'randevuya_acik_mi' => (bool) $doktor->randevuya_acik_mi,
                'online_gorusme' => (bool) ($doktor->aktifPaket()?->hasFeature('online_gorusme')),
                'randevu_periyodu' => $doktor->randevuAyari?->randevu_periyodu ?? 30,
                'enlem' => $doktor->enlem,
                'boylam' => $doktor->boylam,
                'sosyal' => [
                    'instagram' => $doktor->instagram,
                    'facebook' => $doktor->facebook,
                    'youtube' => $doktor->youtube,
                    'linkedin' => $doktor->linkedin,
                    'twitter' => $doktor->twitter,
                    'web_sitesi' => $doktor->web_sitesi,
                ],
                'calisma_saatleri' => $calisma,
                'ortalama_puan' => $doktor->ortalama_puan ?? null,
            ],
        ]);
    }

    /**
     * Full public site content bundle (blog, FAQ, gallery, reviews).
     */
    public function siteContent(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $bloglar = $doktor->bloglar()
            ->where('aktif_mi', true)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'slug' => $b->slug ?? \Illuminate\Support\Str::slug($b->baslik).'-'.$b->id,
                'baslik' => $b->baslik,
                'ozet' => \Illuminate\Support\Str::limit(strip_tags((string) $b->icerik), 160),
                'icerik' => $b->icerik,
                'resim' => $b->resim
                    ? site_media_url(str_starts_with($b->resim, 'storage/') || str_starts_with($b->resim, 'uploads/')
                        ? $b->resim
                        : 'storage/'.$b->resim)
                    : null,
                'tarih' => optional($b->created_at)->format('d F Y'),
                'meta_baslik' => $b->meta_baslik,
                'meta_aciklama' => $b->meta_aciklama,
            ]);

        $faqs = $doktor->faqs()
            ->where('aktif', true)
            ->orderBy('sira')
            ->orderBy('id')
            ->get(['id', 'soru', 'cevap', 'sira']);

        $galeri = $doktor->galeriler()
            ->orderBy('sira')
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'baslik' => $g->baslik,
                'image' => site_media_url($g->resim_yolu),
                'sira' => $g->sira,
            ]);

        $yorumlar = $doktor->yorumlar()
            ->where('onay_durumu', 'onaylandi')
            ->with('hasta:id,ad,soyad')
            ->latest()
            ->limit(20)
            ->get()
            ->map(function ($y) {
                $ad = trim(($y->hasta?->ad ?? '').' '.($y->hasta?->soyad ?? ''));

                return [
                    'id' => $y->id,
                    'ad' => $ad !== '' ? $ad : 'Hasta',
                    'metin' => $y->yorum ?? '',
                    'puan' => (int) ($y->puan ?? 5),
                    'doktor_yaniti' => $y->doktor_yaniti,
                ];
            });

        $egitimler = $this->mapEgitimler($doktor);

        return response()->json([
            'success' => true,
            'data' => [
                'bloglar' => $bloglar,
                'faqs' => $faqs,
                'galeri' => $galeri,
                'yorumlar' => $yorumlar,
                'egitimler' => $egitimler,
            ],
        ]);
    }

    /**
     * Active services of the doctor.
     */
    public function services(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $hizmetler = $doktor->hizmetler()
            ->where('aktif_mi', true)
            ->orderBy('ad')
            ->get(['id', 'ad', 'aciklama', 'sure', 'fiyat', 'slug', 'resim'])
            ->map(function ($h) {
                return [
                    'id' => $h->id,
                    'ad' => $h->ad,
                    'aciklama' => $h->aciklama,
                    'sure' => $h->sure,
                    'fiyat' => $h->fiyat,
                    'slug' => $h->slug,
                    'resim' => site_media_url($h->resim),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $hizmetler,
        ]);
    }

    /**
     * Published educations (courses / webinars).
     */
    public function educations(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        return response()->json([
            'success' => true,
            'data' => $this->mapEgitimler($doktor, true),
        ]);
    }

    /**
     * Single published education by slug or id.
     */
    public function educationShow(Request $request, string $slugOrId): JsonResponse
    {
        $doktor = $this->doktor($request);
        $query = $doktor->egitimler()->yayinda()
            ->with(['formAlanlari' => fn ($q) => $q->where('aktif_mi', true)->orderBy('sira')]);

        $egitim = is_numeric($slugOrId)
            ? $query->where('id', (int) $slugOrId)->first()
            : $query->where('slug', $slugOrId)->first();

        if (! $egitim) {
            return response()->json(['success' => false, 'message' => 'Eğitim bulunamadı.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->mapEgitimDetail($egitim),
        ]);
    }

    /**
     * Guest education application (no platform payment).
     */
    public function storeEducationApplication(Request $request, EgitimBasvuruService $service): JsonResponse
    {
        $doktor = $this->doktor($request);

        $hp = config('randevu.honeypot_field', 'website_url');
        if ($request->filled($hp)) {
            return response()->json(['success' => false, 'message' => 'Geçersiz istek.'], 422);
        }

        $throttleKey = 'public-egitim:'.$doktor->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            return response()->json([
                'success' => false,
                'message' => 'Çok fazla istek. Lütfen biraz sonra tekrar deneyin.',
            ], 429);
        }

        $validated = $request->validate([
            'egitim_id' => ['required', 'integer', 'exists:egitimler,id'],
            'ad' => ['required', 'string', 'max:100'],
            'soyad' => ['required', 'string', 'max:100'],
            'telefon' => ['required', 'string', 'max:40'],
            'e_posta' => ['nullable', 'email', 'max:255'],
            'kvkk_onay' => ['accepted'],
            'alan' => ['nullable', 'array'],
        ], [
            'kvkk_onay.accepted' => 'KVKK onayı zorunludur.',
        ]);

        $egitim = Egitim::with(['formAlanlari' => fn ($q) => $q->where('aktif_mi', true)])
            ->where('doktor_id', $doktor->id)
            ->where('id', $validated['egitim_id'])
            ->first();

        if (! $egitim || ! $egitim->basvuruAlinabilirMi()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu eğitime şu an başvuru alınmıyor.',
            ], 422);
        }

        $cevaplar = [];
        foreach ($egitim->formAlanlari as $alan) {
            $val = data_get($validated, 'alan.'.$alan->id, $request->input('alan.'.$alan->id));
            if ($alan->zorunlu_mu && ($val === null || $val === '')) {
                return response()->json([
                    'success' => false,
                    'message' => $alan->etiket.' alanı zorunludur.',
                ], 422);
            }
            if ($alan->tip === 'checkbox') {
                $val = $request->boolean('alan.'.$alan->id);
            }
            $cevaplar[(string) $alan->id] = $val;
        }

        try {
            $basvuru = $service->basvur($egitim, [
                'ad' => $validated['ad'],
                'soyad' => $validated['soyad'],
                'telefon' => $validated['telefon'],
                'e_posta' => $validated['e_posta'] ?? null,
                'cevaplar' => $cevaplar,
                'kvkk_onay' => true,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        } catch (InvalidArgumentException $e) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        RateLimiter::hit($throttleKey, 300);

        return response()->json([
            'success' => true,
            'message' => 'Başvurunuz alındı. Hekim sizinle iletişime geçecektir.',
            'data' => [
                'id' => $basvuru->id,
                'durum' => $basvuru->durum,
                'ucret_durumu' => $basvuru->ucret_durumu,
            ],
        ], 201);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function mapEgitimler(Doktor $doktor, bool $withDetail = false): array
    {
        $rows = $doktor->egitimler()
            ->yayinda()
            ->orderBy('sira')
            ->orderByDesc('baslangic_at')
            ->limit(50)
            ->get();

        return $rows->map(function (Egitim $e) use ($withDetail) {
            $item = [
                'id' => $e->id,
                'slug' => $e->slug,
                'baslik' => $e->baslik,
                'ozet' => $e->ozet,
                'tip' => $e->tip,
                'baslangic_at' => optional($e->baslangic_at)?->toIso8601String(),
                'baslangic_label' => optional($e->baslangic_at)?->format('d.m.Y H:i'),
                'bitis_at' => optional($e->bitis_at)?->toIso8601String(),
                'mekan' => $e->mekan,
                'fiyat' => $e->fiyat,
                'fiyat_label' => ($e->fiyat === null || (float) $e->fiyat <= 0)
                    ? null
                    : number_format((float) $e->fiyat, 0, ',', '.').' ₺',
                'odeme_notu' => $e->odeme_notu,
                'kontenjan' => $e->kontenjan,
                'basvuru_acik' => $e->basvuruAlinabilirMi(),
                'kapak' => $e->kapak
                    ? site_media_url(str_starts_with((string) $e->kapak, 'storage/') || str_starts_with((string) $e->kapak, 'uploads/')
                        ? $e->kapak
                        : 'storage/'.$e->kapak)
                    : null,
            ];
            if ($withDetail) {
                $item['icerik'] = $e->icerik;
            }

            return $item;
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapEgitimDetail(Egitim $e): array
    {
        return [
            'id' => $e->id,
            'slug' => $e->slug,
            'baslik' => $e->baslik,
            'ozet' => $e->ozet,
            'icerik' => $e->icerik,
            'tip' => $e->tip,
            'baslangic_at' => optional($e->baslangic_at)?->toIso8601String(),
            'baslangic_label' => optional($e->baslangic_at)?->format('d.m.Y H:i'),
            'bitis_at' => optional($e->bitis_at)?->toIso8601String(),
            'mekan' => $e->mekan,
            'fiyat' => $e->fiyat,
            'fiyat_label' => ($e->fiyat === null || (float) $e->fiyat <= 0)
                ? null
                : number_format((float) $e->fiyat, 0, ',', '.').' ₺',
            'odeme_notu' => $e->odeme_notu,
            'kontenjan' => $e->kontenjan,
            'basvuru_acik' => $e->basvuruAlinabilirMi(),
            'meta_baslik' => $e->meta_baslik,
            'meta_aciklama' => $e->meta_aciklama,
            'meta_anahtar_kelimeler' => $e->meta_anahtar_kelimeler,
            'kapak' => $e->kapak
                ? site_media_url(str_starts_with((string) $e->kapak, 'storage/') || str_starts_with((string) $e->kapak, 'uploads/')
                    ? $e->kapak
                    : 'storage/'.$e->kapak)
                : null,
            'form_alanlari' => $e->formAlanlari->map(fn ($a) => [
                'id' => $a->id,
                'etiket' => $a->etiket,
                'anahtar' => $a->anahtar,
                'tip' => $a->tip,
                'zorunlu_mu' => (bool) $a->zorunlu_mu,
                'secenekler' => $a->secenekler ?? [],
                'placeholder' => $a->placeholder,
            ])->values()->all(),
        ];
    }

    /**
     * Available slots for a date (YYYY-MM-DD).
     */
    public function slots(Request $request, SlotService $slotService): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [
            'date.required' => 'Tarih zorunludur.',
            'date.after_or_equal' => 'Geçmiş bir tarih seçilemez.',
        ]);

        $doktor = $this->doktor($request);

        if (! $doktor->randevuya_acik_mi) {
            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $request->date,
                    'slots' => [],
                    'message' => 'Hekim online randevuya kapalı.',
                ],
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

        // Filter past times for today
        if ($tarih->isToday()) {
            $now = now()->format('H:i');
            $bos = $bos->filter(fn ($s) => $s['saat'] > $now)->values();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $tarih->toDateString(),
                'periyot' => $periyot,
                'slots' => $bos,
            ],
        ]);
    }

    /**
     * Send OTP SMS for guest booking.
     */
    public function sendOtp(Request $request, RandevuOtpService $otpService, AppointmentBookingService $bookingService): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'telefon' => ['required', 'string', 'max:30'],
        ]);

        try {
            $otpService->send($validated['telefon'], $doktor->id, $request->ip());
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Doğrulama kodu telefonunuza gönderildi.',
            'data' => [
                'otp_required' => $otpService->isRequired(),
                'expires_in' => 300,
            ],
        ]);
    }

    /**
     * Verify OTP code.
     */
    public function verifyOtp(Request $request, RandevuOtpService $otpService): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'telefon' => ['required', 'string', 'max:30'],
            'kod' => ['required', 'string', 'size:6'],
        ]);

        try {
            $otpService->verify($validated['telefon'], $doktor->id, $validated['kod']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Telefon numaranız doğrulandı.',
        ]);
    }

    /**
     * Guest appointment create (no login).
     */
    public function storeAppointment(
        Request $request,
        AppointmentBookingService $bookingService,
        RandevuOtpService $otpService,
    ): JsonResponse {
        $doktor = $this->doktor($request);

        // Honeypot captcha
        $hp = config('randevu.honeypot_field', 'website_url');
        if ($request->filled($hp)) {
            return response()->json(['success' => false, 'message' => 'Geçersiz istek.'], 422);
        }

        $throttleKey = 'public-randevu:'.$doktor->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            $saniye = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'success' => false,
                'message' => "Çok fazla istek. Lütfen {$saniye} saniye sonra tekrar deneyin.",
            ], 429);
        }

        // reCAPTCHA tarayıcı isteklerinde public site proxy / ana sitede doğrulanır.
        // API anahtarı ile gelen sunucu isteklerinde tekrar secret doğrulaması yapılmaz
        // (hekim sitesi kendi reCAPTCHA secret'ını kullanabilir).

        $validated = $request->validate([
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
            'hizmet_id.required' => 'Hizmet seçimi zorunludur.',
            'tarih.required' => 'Tarih zorunludur.',
            'saat.required' => 'Saat zorunludur.',
            'ad.required' => 'Ad zorunludur.',
            'soyad.required' => 'Soyad zorunludur.',
            'telefon.required' => 'Telefon zorunludur.',
            'kvkk_onay.accepted' => 'Devam etmek için KVKK metnini onaylamalısınız.',
        ]);

        // Daily spam guard per phone + doctor
        $phone = $bookingService->normalizePhone($validated['telefon']);
        $phoneKey = 'public-randevu-phone:'.$doktor->id.':'.preg_replace('/\D+/', '', $phone);
        if (RateLimiter::tooManyAttempts($phoneKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Bu telefon numarası ile bugün çok fazla randevu talebi oluşturuldu.',
            ], 429);
        }

        try {
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

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Public appointment error', [
                'doktor_id' => $doktor->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Randevu kaydı sırasında bir hata oluştu. Lütfen daha sonra tekrar deneyin.',
            ], 500);
        }

        RateLimiter::hit($throttleKey, 300);
        RateLimiter::hit($phoneKey, 86400);

        Log::info('Misafir randevu oluşturuldu', [
            'randevu_id' => $randevu->id,
            'doktor_id' => $doktor->id,
            'durum' => $randevu->durum,
        ]);

        $mesaj = $randevu->durum === 'onaylandi'
            ? 'Randevunuz başarıyla oluşturuldu ve onaylandı.'
            : 'Randevu talebiniz alındı. Hekim onayından sonra bilgilendirileceksiniz.';

        // Management + join pages live on the main site frontend, not on the API host
        $siteUrl = rtrim((string) env('SITE_URL', config('app.url')), '/');
        $yonetimUrl = $siteUrl.'/randevu-yonet/'.$randevu->yonetim_token;
        $hesapUrl = $siteUrl.'/randevu-yonet/'.$randevu->yonetim_token.'/hesap';

        $platformJoinUrl = null;
        if (($randevu->gorusme_tipi ?? '') === 'online' && $randevu->meeting_join_token) {
            $platformJoinUrl = $siteUrl.'/gorusme/'.$randevu->meeting_join_token;
        }

        return response()->json([
            'success' => true,
            'message' => $mesaj,
            'data' => [
                'randevu_id' => $randevu->id,
                'durum' => $randevu->durum,
                'gorusme_tipi' => $randevu->gorusme_tipi ?? 'yuz_yuze',
                'tarih' => $randevu->tarih instanceof \DateTimeInterface
                    ? $randevu->tarih->format('Y-m-d')
                    : (string) $randevu->tarih,
                'saat' => substr((string) $randevu->saat, 0, 5),
                // Capability URL only — raw token not exposed (path already contains it)
                'yonetim_url' => $yonetimUrl,
                'hesap_url' => $hesapUrl,
                'hesap_teklifi' => true,
                'platform_join_url' => $platformJoinUrl,
                'hesap_mesaji' => 'Randevularınızı yönetmek için hesap oluşturabilir veya yönetim linkini saklayabilirsiniz.',
            ],
        ], 201);
    }

    /**
     * Show appointment by management token (API).
     */
    public function showByToken(Request $request, string $token): JsonResponse
    {
        if ($deny = $this->manageTokenRateLimit($request, $token)) {
            return $deny;
        }

        $randevu = Randevu::with(['doktor:id,ad_soyad,unvan', 'hizmet:id,ad'])
            ->where('yonetim_token', $token)
            ->first();

        if (! $randevu) {
            return response()->json(['success' => false, 'message' => 'Randevu bulunamadı.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'durum' => $randevu->durum,
                'tarih' => $randevu->tarih instanceof \DateTimeInterface
                    ? $randevu->tarih->format('Y-m-d')
                    : (string) $randevu->tarih,
                'saat' => substr((string) $randevu->saat, 0, 5),
                'doktor' => trim(($randevu->doktor->unvan ?? '').' '.($randevu->doktor->ad_soyad ?? '')),
                'hizmet' => $randevu->hizmet?->ad,
                'ad' => $randevu->ad,
                'soyad' => $randevu->soyad,
            ],
        ]);
    }

    /**
     * Cancel by management token (API).
     */
    public function cancelByToken(Request $request, string $token, AppointmentBookingService $bookingService): JsonResponse
    {
        if ($deny = $this->manageTokenRateLimit($request, $token)) {
            return $deny;
        }

        try {
            $randevu = $bookingService->cancelByToken($token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Randevunuz iptal edildi.',
            'data' => ['durum' => $randevu->durum],
        ]);
    }

    /**
     * Per-IP + token fragment rate limit for capability URLs.
     */
    protected function manageTokenRateLimit(Request $request, string $token): ?JsonResponse
    {
        $max = (int) config('randevu.manage_token_max_attempts', 20);
        $decay = (int) config('randevu.manage_token_decay_seconds', 60);
        $frag = substr(hash('sha256', $token), 0, 16);
        $key = 'manage-token:'.$request->ip().':'.$frag;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json([
                'success' => false,
                'message' => 'Çok fazla istek. Lütfen bir süre sonra tekrar deneyin.',
            ], 429);
        }

        RateLimiter::hit($key, $decay);

        return null;
    }
}
