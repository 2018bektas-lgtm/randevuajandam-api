<?php

namespace App\Http\Controllers\Api;

use App\Events\RandevuDurumuDegisti;
use App\Http\Controllers\Controller;
use App\Models\Brans;
use App\Models\Doktor;
use App\Models\DoktorCalismaSaati;
use App\Models\DoktorIzin;
use App\Models\Hasta;
use App\Models\Hizmet;
use App\Models\Il;
use App\Models\Ilce;
use App\Models\Randevu;
use App\Models\RandevuAyari;
use Illuminate\Support\Facades\DB;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bidirectional doctor panel API — same data as main hekim panel (shared DB).
 */
class DoctorPanelApiController extends Controller
{
    protected function doktor(Request $request): Doktor
    {
        return $request->attributes->get('auth_doktor');
    }

    public function dashboard(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $bugun = now()->toDateString();

        $statsRaw = DB::table('randevular')
            ->where('doktor_id', $doktor->id)
            ->whereNull('deleted_at')
            ->selectRaw("
                COUNT(*) as toplam_randevu,
                COUNT(DISTINCT hasta_id) as kayitli_hasta,
                COUNT(CASE WHEN durum = 'beklemede' THEN 1 END) as bekleyen_talep,
                COUNT(CASE WHEN tarih = ? AND durum IN ('beklemede', 'onaylandi') THEN 1 END) as bugun_randevu
            ", [$bugun])
            ->first();

        $randevularBugun = $doktor->randevular()
            ->whereDate('tarih', $bugun)
            ->whereIn('durum', ['beklemede', 'onaylandi', 'tamamlandi'])
            ->with('hizmet:id,ad')
            ->orderBy('saat')
            ->get()
            ->map(fn (Randevu $r) => $this->randevuPayload($r));

        return response()->json([
            'success' => true,
            'data' => [
                'doktor' => [
                    'ad_soyad' => $doktor->ad_soyad,
                    'unvan' => $doktor->unvan,
                ],
                'stats' => [
                    'toplam_randevu' => (int) ($statsRaw->toplam_randevu ?? 0),
                    'kayitli_hasta' => (int) ($statsRaw->kayitli_hasta ?? 0),
                    'bekleyen_talep' => (int) ($statsRaw->bekleyen_talep ?? 0),
                    'bugun_randevu' => (int) ($statsRaw->bugun_randevu ?? 0),
                    'randevuya_acik_mi' => (bool) $doktor->randevuya_acik_mi,
                    'aktif_hizmet' => $doktor->hizmetler()->where('aktif_mi', true)->count(),
                ],
                'bugun_randevular' => $randevularBugun,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'ad_soyad' => ['sometimes', 'string', 'max:255'],
            'unvan' => ['nullable', 'string', 'max:100'],
            'telefon' => ['sometimes', 'string', 'max:40'],
            'uzmanlik_alani' => ['nullable', 'string', 'max:255'],
            'biyografi' => ['nullable', 'string', 'max:10000'],
            'adres' => ['nullable', 'string', 'max:500'],
            'klinik_adi' => ['nullable', 'string', 'max:255'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'facebook' => ['nullable', 'string', 'max:255'],
            'twitter' => ['nullable', 'string', 'max:255'],
            'linkedin' => ['nullable', 'string', 'max:255'],
            'youtube' => ['nullable', 'string', 'max:255'],
            'web_sitesi' => ['nullable', 'string', 'max:255'],
            'enlem' => ['nullable', 'numeric'],
            'boylam' => ['nullable', 'numeric'],
            'il' => ['nullable', 'string', 'max:100', 'exists:iller,ad'],
            'ilce' => ['nullable', 'string', 'max:100'],
        ]);

        if (array_key_exists('il', $validated)) {
            $il = Il::query()->where('ad', $validated['il'])->firstOrFail();
            $validated['il_id'] = $il->id;
            unset($validated['il']);

            if (array_key_exists('ilce', $validated)) {
                $ilce = Ilce::query()
                    ->where('il_id', $il->id)
                    ->where('ad', $validated['ilce'])
                    ->firstOrFail();
                $validated['ilce_id'] = $ilce->id;
            }
        }
        unset($validated['ilce']);

        if ($request->hasFile('profil_resmi')) {
            $request->validate([
                'profil_resmi' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
            ]);
            $rel = $this->storeSharedUpload($request->file('profil_resmi'), 'uploads/profil');
            if ($rel) {
                $this->deleteSharedUpload($doktor->profil_resmi);
                $validated['profil_resmi'] = $rel;
            }
        }

        $doktor->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profil güncellendi. Değişiklikler ana platformda da geçerlidir.',
            'data' => array_merge(
                $doktor->fresh()->toArray(),
                ['profil_resmi' => site_media_url($doktor->fresh()->profil_resmi)]
            ),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'mevcut_sifre' => ['required', 'string'],
            'sifre' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['mevcut_sifre'], $doktor->sifre)) {
            return response()->json(['success' => false, 'message' => 'Mevcut şifre hatalı.'], 422);
        }

        $doktor->update(['sifre' => $validated['sifre']]);

        return response()->json([
            'success' => true,
            'message' => 'Şifre güncellendi. Ana panel girişi de aynı şifre ile yapılır.',
        ]);
    }

    public function randevuAyarlari(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $ayar = $doktor->randevuAyari;
        $saatler = $doktor->calismaSaatleri()->orderBy('gun')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'ayar' => $ayar,
                'calisma_saatleri' => $saatler,
            ],
        ]);
    }

    public function updateRandevuAyarlari(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'randevu_periyodu' => ['sometimes', 'integer', 'in:10,15,20,30,45,60'],
            'randevu_onay_tipi' => ['sometimes', 'in:manuel,otomatik'],
            'en_erken_randevu_saati' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'en_gec_randevu_gunu' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'randevu_iptal_aktif_mi' => ['sometimes', 'boolean'],
            'iptal_saat_limiti' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'gunluk_maksimum_randevu' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'email_bildirimleri' => ['sometimes', 'boolean'],
            'sms_bildirimleri' => ['sometimes', 'boolean'],
            'aktif_mi' => ['sometimes', 'boolean'],
            'online_randevu_aktif' => ['sometimes', 'boolean'],
            'yuzyuze_randevu_aktif' => ['sometimes', 'boolean'],
        ]);

        $ayar = RandevuAyari::updateOrCreate(
            ['doktor_id' => $doktor->id],
            array_merge([
                'randevu_periyodu' => 30,
                'randevu_onay_tipi' => 'manuel',
                'en_erken_randevu_saati' => 2,
                'en_gec_randevu_gunu' => 30,
                'randevu_iptal_aktif_mi' => true,
                'iptal_saat_limiti' => 24,
                'gunluk_maksimum_randevu' => 0,
                'email_bildirimleri' => true,
                'sms_bildirimleri' => true,
                'aktif_mi' => true,
                'online_randevu_aktif' => true,
                'yuzyuze_randevu_aktif' => true,
            ], $validated)
        );

        return response()->json([
            'success' => true,
            'message' => 'Randevu ayarları kaydedildi.',
            'data' => $ayar->fresh(),
        ]);
    }

    public function updateCalismaSaatleri(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'saatler' => ['required', 'array', 'size:7'],
            'saatler.*.gun' => ['required', 'integer', 'between:1,7'],
            'saatler.*.aktif_mi' => ['required', 'boolean'],
            'saatler.*.mesai_baslangic' => ['nullable', 'date_format:H:i'],
            'saatler.*.mesai_bitis' => ['nullable', 'date_format:H:i'],
            'saatler.*.ogle_arasi_aktif_mi' => ['sometimes', 'boolean'],
            'saatler.*.ogle_baslangic' => ['nullable', 'date_format:H:i'],
            'saatler.*.ogle_bitis' => ['nullable', 'date_format:H:i'],
        ]);

        foreach ($validated['saatler'] as $row) {
            DoktorCalismaSaati::updateOrCreate(
                ['doktor_id' => $doktor->id, 'gun' => $row['gun']],
                [
                    'aktif_mi' => $row['aktif_mi'],
                    'mesai_baslangic' => ($row['mesai_baslangic'] ?? '09:00').':00',
                    'mesai_bitis' => ($row['mesai_bitis'] ?? '17:00').':00',
                    'ogle_arasi_aktif_mi' => $row['ogle_arasi_aktif_mi'] ?? false,
                    'ogle_baslangic' => ! empty($row['ogle_baslangic']) ? $row['ogle_baslangic'].':00' : null,
                    'ogle_bitis' => ! empty($row['ogle_bitis']) ? $row['ogle_bitis'].':00' : null,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Çalışma saatleri güncellendi.',
            'data' => $doktor->calismaSaatleri()->orderBy('gun')->get(),
        ]);
    }

    /**
     * FullCalendar events feed (appointments + lunch + leaves).
     * Query: start, end (ISO dates from FullCalendar).
     */
    public function calendarEvents(Request $request): JsonResponse
    {
        $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
        ]);

        $doktor = $this->doktor($request);
        $start = \Carbon\Carbon::parse($request->start);
        $end = \Carbon\Carbon::parse($request->end);
        $events = [];

        $randevular = $doktor->randevular()
            ->whereBetween('tarih', [$start->toDateString(), $end->toDateString()])
            ->whereIn('durum', ['beklemede', 'onaylandi', 'tamamlandi', 'iptal'])
            ->with(['hizmet:id,ad,sure', 'hasta:id,ad,soyad,telefon'])
            ->get();

        foreach ($randevular as $randevu) {
            $hizmetDuration = 30;
            if ($randevu->hizmet && $randevu->hizmet->sure) {
                $hizmetDuration = (int) $randevu->hizmet->sure;
            } elseif ($doktor->randevuAyari && $doktor->randevuAyari->randevu_periyodu) {
                $hizmetDuration = (int) $doktor->randevuAyari->randevu_periyodu;
            }

            $tarihStr = $randevu->tarih instanceof \DateTimeInterface
                ? $randevu->tarih->format('Y-m-d')
                : substr((string) $randevu->tarih, 0, 10);
            $saatStr = strlen((string) $randevu->saat) === 5 ? $randevu->saat.':00' : (string) $randevu->saat;
            $startDateTime = \Carbon\Carbon::parse($tarihStr.' '.$saatStr);
            $endDateTime = $startDateTime->copy()->addMinutes($hizmetDuration);

            $color = '#C96A2B';
            if ($randevu->durum === 'onaylandi') {
                $color = '#10B981';
            } elseif ($randevu->durum === 'tamamlandi') {
                $color = '#3B82F6';
            } elseif ($randevu->durum === 'iptal') {
                $color = '#EF4444';
            }

            $isOnline = method_exists($randevu, 'isOnline') && $randevu->isOnline();
            $joinUrl = null;
            $hekimJoinUrl = null;
            $canJoin = false;
            if ($isOnline && $randevu->durum === 'onaylandi') {
                try {
                    $meet = app(\App\Services\MeetingRoomService::class);
                    if (! $randevu->meeting_join_token) {
                        $randevu = $meet->ensureRoom($randevu);
                    }
                    $joinUrl = $meet->platformJoinUrl($randevu);
                    $canJoin = $meet->canJoin($randevu);
                    $siteUrl = rtrim((string) env('SITE_URL', config('app.url')), '/');
                    $hekimJoinUrl = $siteUrl.'/hekim/gorusme/'.$randevu->id.'/app';
                } catch (\Throwable) {
                    //
                }
            }

            $events[] = [
                'id' => 'randevu_'.$randevu->id,
                'title' => ($isOnline ? '📹 ' : '').trim($randevu->ad.' '.$randevu->soyad).' ('.($randevu->hizmet?->ad ?? 'Hizmet').')',
                'start' => $startDateTime->toIso8601String(),
                'end' => $endDateTime->toIso8601String(),
                'backgroundColor' => $color,
                'borderColor' => $color,
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'type' => 'randevu',
                    'randevu_id' => $randevu->id,
                    'hasta_ad' => trim($randevu->ad.' '.$randevu->soyad),
                    'telefon' => $randevu->telefon,
                    'e_posta' => $randevu->e_posta,
                    'hizmet_id' => $randevu->hizmet_id,
                    'hizmet_ad' => $randevu->hizmet?->ad ?? 'Genel Hizmet',
                    'durum' => $randevu->durum,
                    'hekim_notu' => $randevu->hekim_notu,
                    'not' => $randevu->not,
                    'tarih' => $tarihStr,
                    'saat' => substr($saatStr, 0, 5),
                    'gorusme_tipi' => $randevu->gorusme_tipi ?? 'yuz_yuze',
                    'platform_join_url' => $joinUrl,
                    'hekim_join_url' => $hekimJoinUrl,
                    'can_join' => $canJoin,
                ],
            ];
        }

        // Lunch breaks as background events
        $calismaSaatleri = $doktor->calismaSaatleri()->get()->keyBy('gun');
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $gunIndeksi = $cursor->dayOfWeekIso; // 1..7
            $cs = $calismaSaatleri->get($gunIndeksi);
            if ($cs && $cs->aktif_mi && $cs->ogle_arasi_aktif_mi && $cs->ogle_baslangic && $cs->ogle_bitis) {
                $dateStr = $cursor->toDateString();
                $events[] = [
                    'id' => 'ogle_'.$dateStr,
                    'title' => 'Öğle Arası',
                    'start' => $dateStr.'T'.\Carbon\Carbon::parse($cs->ogle_baslangic)->format('H:i:s'),
                    'end' => $dateStr.'T'.\Carbon\Carbon::parse($cs->ogle_bitis)->format('H:i:s'),
                    'display' => 'background',
                    'backgroundColor' => '#FEF9C3',
                    'extendedProps' => ['type' => 'ogle'],
                ];
            }
            $cursor->addDay();
        }

        // Leaves / blocked times
        if (method_exists($doktor, 'izinler')) {
            $izinler = $doktor->izinler()
                ->where(function ($q) use ($start, $end) {
                    $q->where('baslangic_zaman', '<=', $end->toDateTimeString())
                        ->where('bitis_zaman', '>=', $start->toDateTimeString());
                })
                ->get();

            foreach ($izinler as $izin) {
                $isHizli = ($izin->aciklama === 'Hızlı Randevu Kapatma');
                $bas = $izin->baslangic_zaman instanceof \DateTimeInterface
                    ? $izin->baslangic_zaman->format('c')
                    : (string) $izin->baslangic_zaman;
                $bit = $izin->bitis_zaman instanceof \DateTimeInterface
                    ? $izin->bitis_zaman->format('c')
                    : (string) $izin->bitis_zaman;

                if ($isHizli) {
                    $events[] = [
                        'id' => 'izin_'.$izin->id,
                        'start' => $bas,
                        'end' => $bit,
                        'display' => 'background',
                        'backgroundColor' => '#F3F4F6',
                        'extendedProps' => [
                            'type' => 'izin',
                            'aciklama' => $izin->aciklama,
                        ],
                    ];
                } else {
                    $events[] = [
                        'id' => 'izin_'.$izin->id,
                        'title' => 'İzin: '.($izin->aciklama ?? 'İzin Dönemi'),
                        'start' => $bas,
                        'end' => $bit,
                        'backgroundColor' => '#EF4444',
                        'borderColor' => '#EF4444',
                        'textColor' => '#ffffff',
                        'extendedProps' => [
                            'type' => 'izin',
                            'aciklama' => $izin->aciklama,
                        ],
                    ];
                }
            }
        }

        return response()->json($events);
    }

    public function randevular(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $q = $doktor->randevular()->with(['hizmet:id,ad', 'hasta:id,ad,soyad,telefon'])->latest('tarih')->latest('saat');

        if ($request->filled('durum')) {
            $q->where('durum', $request->durum);
        }
        if ($request->filled('tarih')) {
            $q->whereDate('tarih', $request->tarih);
        }
        if ($request->filled('from') && $request->filled('to')) {
            $q->whereBetween('tarih', [$request->from, $request->to]);
        }

        $paginator = $q->paginate(min((int) $request->get('per_page', 20), 50));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => collect($paginator->items())->map(fn (Randevu $r) => $this->randevuPayload($r))->values(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function updateRandevuDurum(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $randevu = $doktor->randevular()->findOrFail($id);

        $validated = $request->validate([
            'durum' => ['required', 'in:beklemede,onaylandi,tamamlandi,iptal'],
            'hekim_notu' => ['nullable', 'string', 'max:2000'],
        ]);

        $eski = $randevu->durum;
        $data = ['durum' => $validated['durum']];
        if (array_key_exists('hekim_notu', $validated)) {
            $data['hekim_notu'] = $validated['hekim_notu'];
        }
        $randevu->update($data);

        if ($eski !== $validated['durum']) {
            RandevuDurumuDegisti::dispatch($randevu, $eski, $validated['durum']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Randevu durumu güncellendi.',
            'data' => $this->randevuPayload($randevu->fresh(['hizmet', 'hasta'])),
        ]);
    }

    /**
     * Calendar: update slot period (15/20/30/45/60).
     */
    public function updatePeriod(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $validated = $request->validate([
            'periyot' => ['required', 'integer', 'in:15,20,30,45,60'],
        ]);

        $defaults = [
            'randevu_periyodu' => (int) $validated['periyot'],
            'randevu_onay_tipi' => 'manuel',
            'en_erken_randevu_saati' => 2,
            'en_gec_randevu_gunu' => 30,
            'randevu_iptal_aktif_mi' => true,
            'iptal_saat_limiti' => 24,
            'gunluk_maksimum_randevu' => 0,
            'email_bildirimleri' => true,
            'sms_bildirimleri' => true,
            'aktif_mi' => true,
        ];

        $existing = $doktor->randevuAyari;
        if ($existing) {
            $existing->update(['randevu_periyodu' => (int) $validated['periyot']]);
        } else {
            RandevuAyari::create(array_merge($defaults, ['doktor_id' => $doktor->id]));
        }

        return response()->json([
            'success' => true,
            'message' => 'Zaman dilimi periyodu güncellendi.',
            'data' => ['periyot' => (int) $validated['periyot']],
        ]);
    }

    /**
     * Calendar: create appointment for existing patient.
     */
    public function storeRandevu(Request $request, AppointmentBookingService $bookingService): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'hizmet_id' => ['required', 'integer'],
            'danisan_id' => ['required', 'integer'],
            'tarih' => ['required', 'date'],
            'saat' => ['required', 'date_format:H:i'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
            'not' => ['nullable', 'string', 'max:1000'],
            'gorusme_tipi' => ['nullable', 'in:yuz_yuze,online'],
        ]);

        $hasta = Hasta::find($validated['danisan_id']);
        if (! $hasta) {
            return response()->json(['success' => false, 'message' => 'Danışan bulunamadı.'], 422);
        }

        try {
            $randevu = $bookingService->create([
                'doktor' => $doktor,
                'hasta' => $hasta,
                'hizmet_id' => (int) $validated['hizmet_id'],
                'tarih' => Carbon::parse($validated['tarih'])->toDateString(),
                'saat' => $validated['saat'],
                'not' => $validated['aciklama'] ?? $validated['not'] ?? null,
                'durum' => 'onaylandi',
                'gorusme_tipi' => ($validated['gorusme_tipi'] ?? 'yuz_yuze') === 'online' ? 'online' : 'yuz_yuze',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Randevu başarıyla oluşturuldu.',
            'data' => $this->randevuPayload($randevu->load(['hizmet', 'hasta'])),
        ], 201);
    }

    /**
     * Calendar: create guest-style appointment (ad/soyad/telefon without hasta account).
     */
    public function storeRandevuMisafir(Request $request, AppointmentBookingService $bookingService): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'hizmet_id' => ['required', 'integer'],
            'tarih' => ['required', 'date'],
            'saat' => ['required', 'date_format:H:i'],
            'ad' => ['required', 'string', 'max:100'],
            'soyad' => ['required', 'string', 'max:100'],
            'telefon' => ['required', 'string', 'max:30'],
            'e_posta' => ['nullable', 'email', 'max:255'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
            'gorusme_tipi' => ['nullable', 'in:yuz_yuze,online'],
        ]);

        // Soft-find or create hasta by phone
        $tel = preg_replace('/\D+/', '', $validated['telefon']);
        $hasta = Hasta::query()
            ->where(function ($q) use ($validated, $tel) {
                $q->where('telefon', $validated['telefon']);
                if ($tel) {
                    $q->orWhere('telefon', 'like', '%'.substr($tel, -10).'%');
                }
                if (! empty($validated['e_posta'])) {
                    $q->orWhere('e_posta', $validated['e_posta']);
                }
            })
            ->first();

        if (! $hasta) {
            $hasta = Hasta::create([
                'ad' => $validated['ad'],
                'soyad' => $validated['soyad'],
                'telefon' => $validated['telefon'],
                'e_posta' => $validated['e_posta'] ?? ('misafir_'.Str::lower(Str::random(8)).'@temp.local'),
                'sifre' => Str::password(12),
                'aktif_mi' => true,
            ]);
        }

        try {
            $randevu = $bookingService->create([
                'doktor' => $doktor,
                'hasta' => $hasta,
                'hizmet_id' => (int) $validated['hizmet_id'],
                'tarih' => Carbon::parse($validated['tarih'])->toDateString(),
                'saat' => $validated['saat'],
                'not' => $validated['aciklama'] ?? null,
                'durum' => 'onaylandi',
                'gorusme_tipi' => ($validated['gorusme_tipi'] ?? 'yuz_yuze') === 'online' ? 'online' : 'yuz_yuze',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Randevu oluşturuldu.',
            'data' => $this->randevuPayload($randevu->load(['hizmet', 'hasta'])),
        ], 201);
    }

    public function searchHastalar(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $q = trim((string) $request->get('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $hastaIds = $doktor->randevular()
            ->whereNotNull('hasta_id')
            ->distinct()
            ->pluck('hasta_id');

        if ($hastaIds->isEmpty()) {
            // Fallback: search all by phone/name for doctor panel convenience
            $hastalar = Hasta::query()
                ->where(function ($query) use ($q) {
                    $query->where('ad', 'like', "%{$q}%")
                        ->orWhere('soyad', 'like', "%{$q}%")
                        ->orWhere('telefon', 'like', "%{$q}%")
                        ->orWhere('e_posta', 'like', "%{$q}%");
                })
                ->limit(20)
                ->get(['id', 'ad', 'soyad', 'e_posta', 'telefon']);
        } else {
            $hastalar = Hasta::query()
                ->whereIn('id', $hastaIds)
                ->where(function ($query) use ($q) {
                    $query->where('ad', 'like', "%{$q}%")
                        ->orWhere('soyad', 'like', "%{$q}%")
                        ->orWhere('telefon', 'like', "%{$q}%")
                        ->orWhere('e_posta', 'like', "%{$q}%");
                })
                ->limit(20)
                ->get(['id', 'ad', 'soyad', 'e_posta', 'telefon']);
        }

        $results = $hastalar->map(fn (Hasta $h) => [
            'id' => $h->id,
            'text' => trim($h->ad.' '.$h->soyad).' ('.($h->telefon ?: $h->e_posta).')',
            'ad' => $h->ad,
            'soyad' => $h->soyad,
            'telefon' => $h->telefon,
            'e_posta' => $h->e_posta,
        ])->values();

        return response()->json(['results' => $results]);
    }

    public function storeHasta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:hastalar,e_posta'],
            'telefon' => ['required', 'string', 'max:40'],
        ]);

        $parts = preg_split('/\s+/', trim($validated['name'])) ?: [];
        $soyad = count($parts) > 1 ? array_pop($parts) : '';
        $ad = implode(' ', $parts) ?: $validated['name'];
        $tempPassword = Str::password(10);

        $hasta = Hasta::create([
            'ad' => $ad,
            'soyad' => $soyad,
            'e_posta' => $validated['email'],
            'telefon' => $validated['telefon'],
            'sifre' => $tempPassword,
            'aktif_mi' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Yeni danışan oluşturuldu.',
            'danisan' => [
                'id' => $hasta->id,
                'name' => trim($hasta->ad.' '.$hasta->soyad),
                'email' => $hasta->e_posta,
                'telefon' => $hasta->telefon,
            ],
            'gecici_sifre' => $tempPassword,
        ], 201);
    }

    public function destroyRandevu(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $randevu = $doktor->randevular()->findOrFail($id);
        $randevu->delete();

        return response()->json(['success' => true, 'message' => 'Randevu silindi.']);
    }

    public function reschedule(Request $request, int $id, AppointmentBookingService $bookingService): JsonResponse
    {
        $doktor = $this->doktor($request);
        $randevu = $doktor->randevular()->findOrFail($id);

        $validated = $request->validate([
            'tarih' => ['required', 'date'],
            'saat' => ['required', 'date_format:H:i'],
        ]);

        if (Carbon::parse($validated['tarih'].' '.$validated['saat'])->isPast()) {
            return response()->json(['success' => false, 'message' => 'Geçmiş bir tarihe randevu taşınamaz.'], 422);
        }

        try {
            $bookingService->reschedule($randevu, $validated['tarih'], $validated['saat']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Randevu tarihi ve saati güncellendi.',
            'data' => $this->randevuPayload($randevu->fresh(['hizmet', 'hasta'])),
        ]);
    }

    public function patients(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hastaIds = $doktor->randevular()->whereNotNull('hasta_id')->distinct()->pluck('hasta_id');

        $q = Hasta::query()->whereIn('id', $hastaIds);
        if ($request->filled('q')) {
            $term = $request->get('q');
            $q->where(function ($w) use ($term) {
                $w->where('ad', 'like', "%{$term}%")
                    ->orWhere('soyad', 'like', "%{$term}%")
                    ->orWhere('telefon', 'like', "%{$term}%")
                    ->orWhere('e_posta', 'like', "%{$term}%");
            });
        }

        $paginator = $q->orderBy('ad')->orderBy('soyad')->paginate(min((int) $request->get('per_page', 20), 50));

        $items = collect($paginator->items())->map(function (Hasta $h) use ($doktor) {
            return [
                'id' => $h->id,
                'ad' => $h->ad,
                'soyad' => $h->soyad,
                'telefon' => $h->telefon,
                'e_posta' => $h->e_posta,
                'aktif_mi' => (bool) $h->aktif_mi,
                'randevu_sayisi' => $doktor->randevular()->where('hasta_id', $h->id)->count(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function showHasta(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hasta = Hasta::query()->findOrFail($id);

        $randevular = $doktor->randevular()
            ->where('hasta_id', $hasta->id)
            ->with(['hizmet:id,ad,fiyat'])
            ->latest('tarih')
            ->latest('saat')
            ->get();

        $odemeler = $doktor->odemeler()
            ->where('hasta_id', $hasta->id)
            ->latest('odeme_tarihi')
            ->get();

        $toplamOdenen = (float) $odemeler->where('durum', '!=', 'iptal')->sum('odenen_tutar');
        $toplamTutar = (float) $odemeler->where('durum', '!=', 'iptal')->sum('tutar');

        // Randevu bazlı borç hesabı (ödemesi eksik kalanlar)
        if ($toplamTutar == 0 && $randevular->isNotEmpty()) {
            foreach ($randevular as $r) {
                if (in_array($r->durum, ['onaylandi', 'tamamlandi'])) {
                    $toplamTutar += (float) ($r->hizmet?->fiyat ?? 0);
                }
            }
        }

        $kalanBakiye = max(0, $toplamTutar - $toplamOdenen);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $hasta->id,
                'ad' => $hasta->ad,
                'soyad' => $hasta->soyad,
                'telefon' => $hasta->telefon,
                'e_posta' => $hasta->e_posta,
                'randevular' => $randevular->map(fn (Randevu $r) => $this->randevuPayload($r)),
                'finans' => [
                    'toplam_tutar' => $toplamTutar,
                    'toplam_odenen' => $toplamOdenen,
                    'kalan_bakiye' => $kalanBakiye,
                    'odemeler' => $odemeler->map(fn ($o) => [
                        'id' => $o->id,
                        'tutar' => (float) $o->tutar,
                        'odenen_tutar' => (float) $o->odenen_tutar,
                        'odeme_yontemi' => $o->odeme_yontemi,
                        'durum' => $o->durum,
                        'odeme_tarihi' => $o->odeme_tarihi,
                        'aciklama' => $o->aciklama,
                    ]),
                ],
            ],
        ]);
    }

    public function updateHasta(Request $request, int $id): JsonResponse
    {
        $hasta = Hasta::query()->findOrFail($id);
        $v = $request->validate([
            'ad' => ['sometimes', 'string', 'max:255'],
            'soyad' => ['sometimes', 'string', 'max:255'],
            'telefon' => ['nullable', 'string', 'max:40'],
            'e_posta' => ['nullable', 'email', 'max:255'],
        ]);

        $hasta->update($v);

        return response()->json([
            'success' => true,
            'message' => 'Danışan bilgileri güncellendi.',
            'data' => [
                'id' => $hasta->id,
                'ad' => $hasta->ad,
                'soyad' => $hasta->soyad,
                'telefon' => $hasta->telefon,
                'e_posta' => $hasta->e_posta,
            ],
        ]);
    }

    public function destroyHasta(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hasta = Hasta::query()->findOrFail($id);
        $doktor->randevular()->where('hasta_id', $hasta->id)->update(['hasta_id' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Danışan kaydı listeden kaldırıldı.',
        ]);
    }

    public function leaves(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $items = $doktor->izinler()
            ->where('bitis_zaman', '>=', now())
            ->orderBy('baslangic_zaman')
            ->get()
            ->map(fn (DoktorIzin $i) => [
                'id' => $i->id,
                'baslangic_zaman' => $i->baslangic_zaman?->toIso8601String(),
                'bitis_zaman' => $i->bitis_zaman?->toIso8601String(),
                'aciklama' => $i->aciklama,
            ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function storeLeave(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'baslangic_tarih' => ['required', 'date'],
            'baslangic_saat' => ['required', 'date_format:H:i'],
            'bitis_tarih' => ['required', 'date'],
            'bitis_saat' => ['required', 'date_format:H:i'],
            'aciklama' => ['nullable', 'string', 'max:500'],
        ]);

        $bas = $v['baslangic_tarih'].' '.$v['baslangic_saat'].':00';
        $bit = $v['bitis_tarih'].' '.$v['bitis_saat'].':00';
        if ($bit <= $bas) {
            return response()->json(['success' => false, 'message' => 'Bitiş zamanı başlangıçtan sonra olmalıdır.'], 422);
        }

        $izin = $doktor->izinler()->create([
            'baslangic_zaman' => $bas,
            'bitis_zaman' => $bit,
            'aciklama' => $v['aciklama'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'İzin/tatil eklendi.',
            'data' => $izin,
        ], 201);
    }

    public function destroyLeave(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $izin = $doktor->izinler()->findOrFail($id);
        $izin->delete();

        return response()->json(['success' => true, 'message' => 'İzin silindi.']);
    }

    public function quickCloseSlots(Request $request): JsonResponse
    {
        $request->validate(['tarih' => ['required', 'date']]);
        $doktor = $this->doktor($request);
        $tarih = Carbon::parse($request->tarih);
        $gunIndeksi = (int) $tarih->format('N');

        $calismaSaati = $doktor->calismaSaatleri()->where('gun', $gunIndeksi)->first();
        $periyot = (int) ($doktor->randevuAyari?->randevu_periyodu ?: 30);
        if ($periyot <= 0) {
            $periyot = 30;
        }

        if (! $calismaSaati || ! $calismaSaati->aktif_mi) {
            return response()->json([
                'aktif_mi' => false,
                'periyot' => $periyot,
                'mesaj' => 'Seçilen günde çalışma saati yok veya kapalı.',
                'slots' => [],
            ]);
        }

        $randevular = $doktor->randevular()
            ->whereDate('tarih', $tarih->toDateString())
            ->whereIn('durum', ['beklemede', 'onaylandi', 'tamamlandi'])
            ->get();
        $izinler = $doktor->izinler()
            ->where('baslangic_zaman', '<=', $tarih->toDateString().' 23:59:59')
            ->where('bitis_zaman', '>=', $tarih->toDateString().' 00:00:00')
            ->get();

        $slots = [];
        $current = Carbon::parse($calismaSaati->mesai_baslangic);
        $end = Carbon::parse($calismaSaati->mesai_bitis);

        while ($current->lt($end)) {
            $slotStart = $current->format('H:i');
            $current = $current->copy()->addMinutes($periyot);
            $slotEnd = $current->format('H:i');
            if ($current->gt($end)) {
                break;
            }

            $slotDateTimeStr = $tarih->toDateString().' '.$slotStart.':00';
            $isLunch = false;
            if ($calismaSaati->ogle_arasi_aktif_mi && $calismaSaati->ogle_baslangic && $calismaSaati->ogle_bitis) {
                $ls = Carbon::parse($calismaSaati->ogle_baslangic)->format('H:i');
                $le = Carbon::parse($calismaSaati->ogle_bitis)->format('H:i');
                $isLunch = $slotStart >= $ls && $slotStart < $le;
            }
            $isIzin = $izinler->contains(function ($izin) use ($slotDateTimeStr) {
                return $slotDateTimeStr >= $izin->baslangic_zaman->toDateTimeString()
                    && $slotDateTimeStr < $izin->bitis_zaman->toDateTimeString();
            });
            $isDolu = $randevular->contains(fn ($item) => substr((string) $item->saat, 0, 5) === $slotStart);

            $slots[] = [
                'saat_baslangic' => $slotStart,
                'saat_bitis' => $slotEnd,
                'saat_string' => $slotStart,
                'ogle_mi' => $isLunch,
                'kapali_mi' => $isIzin,
                'dolu_mu' => $isDolu,
            ];
        }

        return response()->json(['aktif_mi' => true, 'periyot' => $periyot, 'slots' => $slots]);
    }

    public function quickCloseSave(Request $request): JsonResponse
    {
        $request->validate([
            'tarih' => ['required', 'date'],
            'saatler' => ['nullable', 'array'],
            'saatler.*' => ['required', 'date_format:H:i'],
        ]);

        $doktor = $this->doktor($request);
        $tarih = Carbon::parse($request->tarih);
        $periyot = (int) ($doktor->randevuAyari?->randevu_periyodu ?: 30);
        if ($periyot <= 0) {
            $periyot = 30;
        }

        $gonderilenSaatler = $request->input('saatler', []);
        $mevcutIzinler = $doktor->izinler()
            ->where('baslangic_zaman', '<=', $tarih->toDateString().' 23:59:59')
            ->where('bitis_zaman', '>=', $tarih->toDateString().' 00:00:00')
            ->get();

        $gunIndeksi = (int) $tarih->format('N');
        $calismaSaati = $doktor->calismaSaatleri()->where('gun', $gunIndeksi)->first();
        $eklenen = 0;
        $silinen = 0;

        if ($calismaSaati && $calismaSaati->aktif_mi) {
            $current = Carbon::parse($calismaSaati->mesai_baslangic);
            $end = Carbon::parse($calismaSaati->mesai_bitis);
            while ($current->lt($end)) {
                $slotStart = $current->format('H:i');
                $current = $current->copy()->addMinutes($periyot);
                $slotEnd = $current->format('H:i');
                if ($current->gt($end)) {
                    break;
                }

                $slotStartStr = $tarih->toDateString().' '.$slotStart.':00';
                $slotEndStr = $tarih->toDateString().' '.$slotEnd.':00';
                $mevcutIzin = $mevcutIzinler->first(function ($izin) use ($slotStartStr) {
                    return $izin->baslangic_zaman->toDateTimeString() === $slotStartStr
                        && $izin->aciklama === 'Hızlı Randevu Kapatma';
                });
                $isSelected = in_array($slotStart, $gonderilenSaatler, true);

                if ($mevcutIzin && ! $isSelected) {
                    $mevcutIzin->delete();
                    $silinen++;
                } elseif (! $mevcutIzin && $isSelected) {
                    $doktor->izinler()->create([
                        'baslangic_zaman' => $slotStartStr,
                        'bitis_zaman' => $slotEndStr,
                        'aciklama' => 'Hızlı Randevu Kapatma',
                    ]);
                    $eklenen++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'basarili' => true,
            'message' => 'Seçilen saat dilimleri güncellendi.',
            'mesaj' => 'Seçilen saat dilimleri güncellendi.',
            'eklenen' => $eklenen,
            'silinen' => $silinen,
        ]);
    }

    public function updateAbout(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'biyografi' => ['nullable', 'string', 'max:10000'],
            'klinik_adi' => ['nullable', 'string', 'max:255'],
            'uzmanlik_alani' => ['nullable', 'string', 'max:500'],
            'mezuniyet' => ['nullable', 'array'],
            'mezuniyet.*' => ['nullable', 'string', 'max:255'],
            'branslar' => ['nullable', 'array'],
            'branslar.*' => ['integer', 'exists:branslar,id'],
        ]);

        $data = [];
        if (array_key_exists('biyografi', $v)) {
            $data['biyografi'] = \App\Services\HtmlSanitizer::clean($v['biyografi'] ?? '');
        }
        if (array_key_exists('klinik_adi', $v)) {
            $data['klinik_adi'] = $v['klinik_adi'];
        }
        if (array_key_exists('mezuniyet', $v)) {
            $data['mezuniyet'] = array_values(array_filter($v['mezuniyet'] ?? [], fn ($x) => filled($x)));
        }

        if (! empty($v['branslar'])) {
            $bransIsimleri = Brans::whereIn('id', $v['branslar'])->pluck('ad')->all();
            $data['uzmanlik_alani'] = implode(', ', $bransIsimleri);
            $doktor->branslar()->sync($v['branslar']);
        } elseif (array_key_exists('uzmanlik_alani', $v)) {
            $data['uzmanlik_alani'] = $v['uzmanlik_alani'];
        }

        $doktor->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Hakkımda bilgileri güncellendi.',
            'data' => $doktor->fresh(['branslar']),
        ]);
    }

    public function branslar(): JsonResponse
    {
        $items = Brans::query()->orderBy('ad')->get(['id', 'ad', 'slug']);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function updateRandevu(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $randevu = $doktor->randevular()->findOrFail($id);

        $v = $request->validate([
            'hizmet_id' => ['required', 'integer'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
        ]);

        $hizmet = $doktor->hizmetler()->where('id', $v['hizmet_id'])->first();
        if (! $hizmet) {
            return response()->json(['success' => false, 'message' => 'Seçilen hizmet size ait değil.'], 422);
        }

        $randevu->update([
            'hizmet_id' => $hizmet->id,
            'not' => $v['aciklama'] ?? $randevu->not,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Randevu güncellendi.',
            'data' => $this->randevuPayload($randevu->fresh(['hizmet', 'hasta'])),
        ]);
    }

    public function websiteInfo(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $webSite = DB::table('hekim_web_siteleri')->where('doktor_id', $doktor->id)->first();
        $apiKey = \App\Models\ApiKey::query()->where('doktor_id', $doktor->id)->first();
        $webhook = DB::table('webhook_endpoints')->where('doktor_id', $doktor->id)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'web_site' => $webSite,
                'api_key' => $apiKey ? [
                    'api_key' => $apiKey->api_key,
                    // Secret is hashed — never return stored value; regenerate to get a new plain secret once
                    'secret_key' => null,
                    'secret_is_hashed' => $apiKey->secretIsHashed(),
                    'durum' => $apiKey->durum ?? 1,
                ] : null,
                'webhook' => $webhook ? [
                    'url' => $webhook->url,
                    'aktif' => $webhook->aktif,
                ] : null,
            ],
        ]);
    }

    public function websiteSetup(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        if (DB::table('hekim_web_siteleri')->where('doktor_id', $doktor->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Zaten tanımlı bir web siteniz bulunuyor.'], 422);
        }

        $request->validate([
            'domain' => ['required', 'string', 'max:100'],
        ]);

        $domain = strtolower(trim((string) $request->domain));
        $domain = preg_replace('#^https?://(www\.)?#', '', $domain);
        $domain = rtrim($domain, '/');
        if ($domain === '') {
            return response()->json(['success' => false, 'message' => 'Geçersiz alan adı.'], 422);
        }

        if (DB::table('hekim_web_siteleri')->where('domain', $domain)->exists()) {
            return response()->json(['success' => false, 'message' => 'Bu alan adı başka bir hekim tarafından kullanılıyor.'], 422);
        }

        DB::table('hekim_web_siteleri')->insert([
            'doktor_id' => $doktor->id,
            'domain' => $domain,
            'tema' => 'custom',
            'durum' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keys = $this->generateApiKeys($doktor->id, $domain);

        return response()->json([
            'success' => true,
            'message' => 'Web sitesi kaydedildi ve API anahtarları oluşturuldu.',
            'data' => $keys,
        ], 201);
    }

    public function regenerateApiKeys(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $web = DB::table('hekim_web_siteleri')->where('doktor_id', $doktor->id)->first();
        $keys = $this->generateApiKeys($doktor->id, $web->domain ?? null);

        return response()->json([
            'success' => true,
            'message' => 'API anahtarları yenilendi. Doktor sitesi .env dosyasını güncellemeniz gerekir.',
            'data' => $keys,
        ]);
    }

    protected function generateApiKeys(int $doktorId, ?string $domain = null): array
    {
        $apiKeyVal = 'rk_'.strtolower(Str::random(30));
        $secretKeyVal = strtolower(Str::random(60));

        \App\Models\ApiKey::issue([
            'doktor_id' => $doktorId,
            'klinik_id' => null,
            'api_key' => $apiKeyVal,
            'durum' => true,
            'yetkiler' => ['*'],
        ], $secretKeyVal);

        if ($domain) {
            $webhookUrl = $domain;
            if (! str_starts_with($webhookUrl, 'http://') && ! str_starts_with($webhookUrl, 'https://')) {
                if (! str_contains($webhookUrl, '.') && ! str_contains($webhookUrl, 'localhost')) {
                    $webhookUrl = 'http://localhost/'.$webhookUrl.'/webhook/receiver';
                } else {
                    $webhookUrl = 'http://'.$webhookUrl.'/webhook/receiver';
                }
            } else {
                $webhookUrl .= '/webhook/receiver';
            }

            DB::table('webhook_endpoints')->updateOrInsert(
                ['doktor_id' => $doktorId],
                [
                    'url' => $webhookUrl,
                    'secret_key' => $secretKeyVal, // plain for HMAC outbound
                    'events' => json_encode(['*']),
                    'aktif' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } else {
            DB::table('webhook_endpoints')
                ->where('doktor_id', $doktorId)
                ->update(['secret_key' => $secretKeyVal, 'updated_at' => now()]);
        }

        return [
            'api_key' => $apiKeyVal,
            'secret_key' => $secretKeyVal, // returned once to client
            'secret_shown_once' => true,
        ];
    }

    public function hizmetler(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $items = $doktor->hizmetler()->orderBy('ad')->get()->map(function ($h) {
            $arr = $h->toArray();
            $arr['resim_url'] = site_media_url($h->resim);
            // Keep relative path; absolute URL for clients
            if (! empty($arr['resim'])) {
                $arr['resim'] = site_media_url($h->resim);
            }

            return $arr;
        });

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function storeHizmet(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $validated = $request->validate([
            'ad' => ['required', 'string', 'max:255'],
            'aciklama' => ['nullable', 'string', 'max:5000'],
            'sure' => ['nullable', 'integer', 'min:5', 'max:480'],
            'fiyat' => ['nullable', 'numeric', 'min:0'],
            'aktif_mi' => ['sometimes', 'boolean'],
            'meta_baslik' => ['nullable', 'string', 'max:255'],
            'meta_aciklama' => ['nullable', 'string', 'max:500'],
            'meta_anahtar_kelimeler' => ['nullable', 'string', 'max:500'],
            'resim' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
        ]);

        $payload = [
            'ad' => $validated['ad'],
            'slug' => Str::slug($validated['ad']).'-'.Str::lower(Str::random(4)),
            'aciklama' => $validated['aciklama'] ?? null,
            'sure' => $validated['sure'] ?? 30,
            'fiyat' => $validated['fiyat'] ?? 0,
            'aktif_mi' => $request->boolean('aktif_mi', true),
            'meta_baslik' => $validated['meta_baslik'] ?? null,
            'meta_aciklama' => $validated['meta_aciklama'] ?? null,
            'meta_anahtar_kelimeler' => $validated['meta_anahtar_kelimeler'] ?? null,
        ];

        if ($request->hasFile('resim')) {
            $rel = $this->storeSharedUpload($request->file('resim'), 'uploads/hizmet');
            if ($rel) {
                $payload['resim'] = $rel;
            }
        }

        $hizmet = $doktor->hizmetler()->create($payload);
        $fresh = $hizmet->fresh();
        $arr = $fresh->toArray();
        $arr['resim_url'] = site_media_url($fresh->resim);
        if (! empty($arr['resim'])) {
            $arr['resim'] = site_media_url($fresh->resim);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hizmet eklendi.',
            'data' => $arr,
        ], 201);
    }

    public function updateHizmet(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hizmet = $doktor->hizmetler()->findOrFail($id);

        $validated = $request->validate([
            'ad' => ['sometimes', 'string', 'max:255'],
            'aciklama' => ['nullable', 'string', 'max:5000'],
            'sure' => ['nullable', 'integer', 'min:5', 'max:480'],
            'fiyat' => ['nullable', 'numeric', 'min:0'],
            'aktif_mi' => ['sometimes', 'boolean'],
            'meta_baslik' => ['nullable', 'string', 'max:255'],
            'meta_aciklama' => ['nullable', 'string', 'max:500'],
            'meta_anahtar_kelimeler' => ['nullable', 'string', 'max:500'],
            'resim' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
        ]);

        $data = collect($validated)->except('resim')->all();
        if ($request->has('aktif_mi')) {
            $data['aktif_mi'] = $request->boolean('aktif_mi');
        }

        if ($request->hasFile('resim')) {
            $rel = $this->storeSharedUpload($request->file('resim'), 'uploads/hizmet');
            if ($rel) {
                $this->deleteSharedUpload($hizmet->resim);
                $data['resim'] = $rel;
            }
        }

        $hizmet->update($data);
        $fresh = $hizmet->fresh();
        $arr = $fresh->toArray();
        $arr['resim_url'] = site_media_url($fresh->resim);
        if (! empty($arr['resim'])) {
            $arr['resim'] = site_media_url($fresh->resim);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hizmet güncellendi.',
            'data' => $arr,
        ]);
    }

    public function destroyHizmet(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hizmet = $doktor->hizmetler()->findOrFail($id);
        $hizmet->delete();

        return response()->json(['success' => true, 'message' => 'Hizmet silindi.']);
    }

    /**
     * Public website content snapshot (for doctor site frontend sync).
     */
    public function siteIcerik(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $doktor->load(['il', 'ilce', 'branslar', 'randevuAyari', 'calismaSaatleri', 'faqs', 'galeriler']);

        $hizmetler = $doktor->hizmetler()->where('aktif_mi', true)->orderBy('ad')->get();
        $bloglar = $doktor->bloglar()->where('aktif_mi', true)->latest()->limit(20)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'doktor' => $doktor,
                'hizmetler' => $hizmetler,
                'bloglar' => $bloglar,
                'faqs' => $doktor->faqs,
                'galeriler' => $doktor->galeriler,
            ],
        ]);
    }

    /**
     * Hekim online görüşme oturumu — platform SITE_URL üzerinde WebRTC odası.
     * Sinyal ana sitede kalır (hasta ile aynı cache); hekim bearer ile /app URL açar.
     */
    public function meetingSession(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $randevu = $doktor->randevular()->with(['hasta', 'hizmet', 'doktor'])->findOrFail($id);

        if (! method_exists($randevu, 'isOnline') || ! $randevu->isOnline()) {
            return response()->json(['success' => false, 'message' => 'Bu randevu online görüşme değil.'], 422);
        }
        if ($randevu->durum !== 'onaylandi') {
            return response()->json([
                'success' => false,
                'message' => 'Görüşme için randevunun onaylı olması gerekir.',
                'data' => ['can_join' => false, 'durum' => $randevu->durum],
            ], 422);
        }

        try {
            $meet = app(\App\Services\MeetingRoomService::class);
            $randevu = $meet->ensureRoom($randevu);
            $canJoin = $meet->canJoin($randevu);
            $window = $meet->joinWindow($randevu);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Görüşme odası hazırlanamadı: '.$e->getMessage(),
            ], 500);
        }

        $siteUrl = rtrim((string) env('SITE_URL', config('app.url')), '/');
        $hekimAppUrl = $siteUrl.'/hekim/gorusme/'.$randevu->id.'/app';
        $patientUrl = null;
        try {
            $patientUrl = $meet->platformJoinUrl($randevu);
        } catch (\Throwable) {
            //
        }

        return response()->json([
            'success' => true,
            'data' => [
                'randevu_id' => $randevu->id,
                'can_join' => $canJoin,
                'online_mi' => true,
                'role' => 'hekim',
                'display_name' => (string) ($doktor->ad_soyad ?? 'Hekim'),
                'room' => $randevu->meeting_room_id,
                'hekim_join_url' => $hekimAppUrl,
                'platform_join_url' => $patientUrl,
                'window' => $window ? [
                    'baslangic' => $window[0]->toIso8601String(),
                    'bitis' => $window[1]->toIso8601String(),
                ] : null,
                'hasta_adi' => trim(($randevu->hasta->ad ?? $randevu->ad).' '.($randevu->hasta->soyad ?? $randevu->soyad)),
                'tarih' => $randevu->tarih instanceof \DateTimeInterface
                    ? $randevu->tarih->format('Y-m-d')
                    : (string) $randevu->tarih,
                'saat' => substr((string) $randevu->saat, 0, 5),
            ],
        ]);
    }

    protected function randevuPayload(Randevu $r): array
    {
        $canJoin = false;
        $joinUrl = null;
        $hekimJoinUrl = null;
        try {
            if (method_exists($r, 'isOnline') && $r->isOnline() && $r->durum === 'onaylandi') {
                $meet = app(\App\Services\MeetingRoomService::class);
                $canJoin = $meet->canJoin($r);
                $joinUrl = $meet->platformJoinUrl($r);
                $siteUrl = rtrim((string) env('SITE_URL', config('app.url')), '/');
                $hekimJoinUrl = $siteUrl.'/hekim/gorusme/'.$r->id.'/app';
            }
        } catch (\Throwable) {
            //
        }

        return [
            'id' => $r->id,
            'tarih' => $r->tarih instanceof \DateTimeInterface ? $r->tarih->format('Y-m-d') : (string) $r->tarih,
            'saat' => substr((string) $r->saat, 0, 5),
            'durum' => $r->durum,
            'gorusme_tipi' => $r->gorusme_tipi ?? 'yuz_yuze',
            'meeting_provider' => $r->meeting_provider,
            'can_join' => $canJoin,
            'platform_join_url' => $joinUrl,
            'hekim_join_url' => $hekimJoinUrl,
            'ad' => $r->ad,
            'soyad' => $r->soyad,
            'telefon' => $r->telefon,
            'e_posta' => $r->e_posta,
            'not' => $r->not,
            'hekim_notu' => $r->hekim_notu,
            'hizmet' => $r->hizmet?->only(['id', 'ad']),
            'hizmet_id' => $r->hizmet_id,
            'hasta_id' => $r->hasta_id,
        ];
    }

    /**
     * Store upload under SHARED_PUBLIC_PATH (shared with site public/uploads).
     */
    protected function storeSharedUpload($file, string $subdir = 'uploads'): ?string
    {
        if (! $file) {
            return null;
        }

        $root = rtrim((string) app('shared_public_path'), '/\\');
        $subdir = trim(str_replace('\\', '/', $subdir), '/');
        $dir = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $subdir);

        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name = 'doktor_'.time().'_'.Str::lower(Str::random(8)).'.'.$ext;
        $file->move($dir, $name);

        return $subdir.'/'.$name;
    }

    protected function deleteSharedUpload(?string $relative): void
    {
        if (! $relative) {
            return;
        }

        // Ignore absolute URLs
        if (preg_match('#^(https?:)?//#i', $relative) || str_starts_with($relative, 'data:')) {
            return;
        }

        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $path = rtrim((string) app('shared_public_path'), '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
