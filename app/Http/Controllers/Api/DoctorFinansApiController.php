<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doktor;
use App\Models\Hasta;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorFinansApiController extends Controller
{
    protected function doktor(Request $request): Doktor
    {
        return $request->attributes->get('auth_doktor');
    }

    public function ozet(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $ayBas = now()->startOfMonth()->toDateString();
        $aySon = now()->endOfMonth()->toDateString();

        $gelir = $doktor->odemeler()
            ->where('durum', '!=', 'iptal')
            ->whereBetween('odeme_tarihi', [$ayBas, $aySon])
            ->sum('odenen_tutar');

        $gider = $doktor->giderler()
            ->whereBetween('tarih', [$ayBas, $aySon])
            ->sum('tutar');

        $toplamBorc = (float) ($doktor->odemeler()
            ->whereIn('durum', ['beklemede', 'kismi_odeme'])
            ->selectRaw('SUM(tutar - odenen_tutar) as bakiye')
            ->value('bakiye') ?? 0);

        // 12 aylık trend
        $trendLabels = [];
        $trendGelir = [];
        $trendGider = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $trendLabels[] = $date->translatedFormat('M Y');
            $trendGelir[] = (float) $doktor->odemeler()
                ->whereYear('odeme_tarihi', $date->year)
                ->whereMonth('odeme_tarihi', $date->month)
                ->where('durum', '!=', 'iptal')
                ->sum('odenen_tutar');
            $trendGider[] = (float) $doktor->giderler()
                ->whereYear('tarih', $date->year)
                ->whereMonth('tarih', $date->month)
                ->sum('tutar');
        }

        // Hizmet bazlı gelir
        $hizmetGelirleriRaw = $doktor->odemeler()
            ->where('durum', '!=', 'iptal')
            ->whereNotNull('hizmet_id')
            ->selectRaw('hizmet_id, SUM(odenen_tutar) as toplam_gelir')
            ->groupBy('hizmet_id')
            ->with('hizmet')
            ->get();

        $hizmetLabels = [];
        $hizmetValues = [];
        $digerGelir = 0.0;
        $serbestGelir = (float) $doktor->odemeler()
            ->where('durum', '!=', 'iptal')
            ->whereNull('hizmet_id')
            ->sum('odenen_tutar');
        if ($serbestGelir > 0) {
            $hizmetLabels[] = 'Diğer / Serbest Gelir';
            $hizmetValues[] = $serbestGelir;
        }
        foreach ($hizmetGelirleriRaw as $item) {
            $hizmetAd = $item->hizmet?->ad ?? 'Bilinmeyen Hizmet';
            if (count($hizmetLabels) < 5) {
                $hizmetLabels[] = $hizmetAd;
                $hizmetValues[] = (float) $item->toplam_gelir;
            } else {
                $digerGelir += (float) $item->toplam_gelir;
            }
        }
        if ($digerGelir > 0) {
            $hizmetLabels[] = 'Diğer Hizmetler';
            $hizmetValues[] = $digerGelir;
        }

        $giderKategorileriRaw = $doktor->giderler()
            ->selectRaw('kategori, SUM(tutar) as toplam_tutar')
            ->groupBy('kategori')
            ->get();
        $kategoriIsimleri = [
            'kira' => 'Kira', 'personel' => 'Personel', 'malzeme' => 'Malzeme',
            'ekipman' => 'Ekipman', 'vergi' => 'Vergi', 'sigorta' => 'Sigorta', 'diger' => 'Diğer',
        ];
        $giderLabels = [];
        $giderValues = [];
        foreach ($giderKategorileriRaw as $item) {
            $giderLabels[] = $kategoriIsimleri[$item->kategori] ?? ($item->kategori ?: 'Diğer');
            $giderValues[] = (float) $item->toplam_tutar;
        }

        $sonOdemeler = $doktor->odemeler()->latest()->take(5)->with(['hasta', 'hizmet'])->get();
        $sonGiderler = $doktor->giderler()->latest()->take(5)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'bu_ay_gelir' => (float) $gelir,
                'bu_ay_gider' => (float) $gider,
                'bu_ay_net' => (float) $gelir - (float) $gider,
                'bekleyen_odeme' => $toplamBorc,
                'kategori_sayisi' => $doktor->finansKategoriler()->count(),
                'trend' => [
                    'labels' => $trendLabels,
                    'gelir' => $trendGelir,
                    'gider' => $trendGider,
                ],
                'hizmet_dagilim' => [
                    'labels' => $hizmetLabels,
                    'values' => $hizmetValues,
                ],
                'gider_dagilim' => [
                    'labels' => $giderLabels,
                    'values' => $giderValues,
                ],
                'son_odemeler' => $sonOdemeler,
                'son_giderler' => $sonGiderler,
            ],
        ]);
    }

    public function kategoriler(Request $request): JsonResponse
    {
        $items = $this->doktor($request)->finansKategoriler()->orderBy('ad')->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function storeKategori(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'ad' => ['required', 'string', 'max:100'],
            'tur' => ['required', 'in:gelir,gider'],
            'renk' => ['nullable', 'string', 'max:20'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        $k = $doktor->finansKategoriler()->create([
            'ad' => $v['ad'],
            'tur' => $v['tur'],
            'renk' => $v['renk'] ?? '#0D9488',
            'aktif' => $request->boolean('aktif', true),
        ]);

        return response()->json(['success' => true, 'message' => 'Kategori eklendi.', 'data' => $k], 201);
    }

    public function updateKategori(Request $request, int $id): JsonResponse
    {
        $k = $this->doktor($request)->finansKategoriler()->findOrFail($id);
        $v = $request->validate([
            'ad' => ['required', 'string', 'max:100'],
            'tur' => ['required', 'in:gelir,gider'],
            'renk' => ['nullable', 'string', 'max:20'],
            'aktif' => ['nullable', 'boolean'],
        ]);
        $k->update([
            'ad' => $v['ad'],
            'tur' => $v['tur'],
            'renk' => $v['renk'] ?? $k->renk,
            'aktif' => $request->has('aktif') ? $request->boolean('aktif') : $k->aktif,
        ]);

        return response()->json(['success' => true, 'message' => 'Kategori güncellendi.', 'data' => $k->fresh()]);
    }

    public function toggleKategori(Request $request, int $id): JsonResponse
    {
        $k = $this->doktor($request)->finansKategoriler()->findOrFail($id);
        $k->update(['aktif' => ! (bool) $k->aktif]);

        return response()->json(['success' => true, 'message' => 'Kategori durumu güncellendi.', 'data' => $k->fresh()]);
    }

    public function destroyKategori(Request $request, int $id): JsonResponse
    {
        $this->doktor($request)->finansKategoriler()->findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Kategori silindi.']);
    }

    public function gelirler(Request $request): JsonResponse
    {
        $q = $this->doktor($request)->odemeler()
            ->with(['finansKategori', 'hasta', 'hizmet', 'kalemler'])
            ->latest('odeme_tarihi');
        if ($request->filled('durum')) {
            $q->where('durum', $request->durum);
        }
        if ($request->filled('hasta_id')) {
            $q->where('hasta_id', $request->hasta_id);
        }
        if ($request->filled('finans_kategori_id')) {
            $q->where('finans_kategori_id', $request->finans_kategori_id);
        }
        if ($request->filled('tarih_baslangic')) {
            $q->whereDate('odeme_tarihi', '>=', $request->tarih_baslangic);
        }
        if ($request->filled('tarih_bitis')) {
            $q->whereDate('odeme_tarihi', '<=', $request->tarih_bitis);
        }
        $items = $q->paginate(min((int) $request->get('per_page', 15), 50));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items->items(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                ],
            ],
        ]);
    }

    public function storeGelir(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'odenen_tutar' => ['nullable', 'numeric', 'min:0'],
            'odeme_yontemi' => ['nullable', 'in:nakit,kredi_karti,havale,online'],
            'ilk_odeme_yontemi' => ['nullable', 'in:nakit,kredi_karti,havale,online'],
            'durum' => ['nullable', 'in:beklemede,odendi,kismi_odeme,iptal'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
            'odeme_tarihi' => ['required', 'date'],
            'finans_kategori_id' => ['nullable', 'integer'],
            'hasta_id' => ['nullable', 'integer'],
            'hizmet_id' => ['nullable', 'integer'],
        ]);

        $odenen = (float) ($v['odenen_tutar'] ?? 0);
        $tutar = (float) $v['tutar'];
        $yontem = $v['odeme_yontemi'] ?? $v['ilk_odeme_yontemi'] ?? 'nakit';
        $durum = $v['durum'] ?? ($odenen >= $tutar ? 'odendi' : ($odenen > 0 ? 'kismi_odeme' : 'beklemede'));

        $odeme = $doktor->odemeler()->create([
            'tutar' => $tutar,
            'odenen_tutar' => $odenen,
            'odeme_yontemi' => $yontem,
            'durum' => $durum,
            'aciklama' => $v['aciklama'] ?? null,
            'odeme_tarihi' => $v['odeme_tarihi'],
            'finans_kategori_id' => $v['finans_kategori_id'] ?? null,
            'hasta_id' => $v['hasta_id'] ?? null,
            'hizmet_id' => $v['hizmet_id'] ?? null,
        ]);

        if ($odenen > 0 && method_exists($odeme, 'kalemler')) {
            $odeme->kalemler()->create([
                'tutar' => $odenen,
                'tarih' => $v['odeme_tarihi'],
                'odeme_yontemi' => $yontem,
                'not' => 'İlk ödeme',
            ]);
            if (method_exists($odeme, 'odenenTutariGuncelle')) {
                $odeme->odenenTutariGuncelle();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Gelir kaydı oluşturuldu.',
            'data' => $odeme->fresh(['kalemler', 'hasta', 'hizmet', 'finansKategori']),
        ], 201);
    }

    public function showGelir(Request $request, int $id): JsonResponse
    {
        $odeme = $this->doktor($request)->odemeler()
            ->with(['finansKategori', 'hasta', 'hizmet', 'kalemler'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $odeme]);
    }

    public function updateGelir(Request $request, int $id): JsonResponse
    {
        $odeme = $this->doktor($request)->odemeler()->findOrFail($id);
        $v = $request->validate([
            'hasta_id' => ['nullable', 'integer'],
            'hizmet_id' => ['nullable', 'integer'],
            'finans_kategori_id' => ['nullable', 'integer'],
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
            'odeme_tarihi' => ['required', 'date'],
        ]);
        $odeme->update($v);
        if (method_exists($odeme, 'odenenTutariGuncelle')) {
            $odeme->odenenTutariGuncelle();
        }

        return response()->json([
            'success' => true,
            'message' => 'Gelir kaydı güncellendi.',
            'data' => $odeme->fresh(['kalemler', 'hasta', 'hizmet', 'finansKategori']),
        ]);
    }

    public function storeGelirKalem(Request $request, int $id): JsonResponse
    {
        $odeme = $this->doktor($request)->odemeler()->findOrFail($id);
        $v = $request->validate([
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'tarih' => ['required', 'date'],
            'odeme_yontemi' => ['required', 'in:nakit,kredi_karti,havale,online'],
            'not' => ['nullable', 'string', 'max:500'],
        ]);
        $kalem = $odeme->kalemler()->create($v);
        if (method_exists($odeme, 'odenenTutariGuncelle')) {
            $odeme->odenenTutariGuncelle();
        }

        return response()->json(['success' => true, 'message' => 'Ödeme kalemi eklendi.', 'data' => $kalem], 201);
    }

    public function destroyGelirKalem(Request $request, int $odemeId, int $kalemId): JsonResponse
    {
        $odeme = $this->doktor($request)->odemeler()->findOrFail($odemeId);
        $kalem = $odeme->kalemler()->findOrFail($kalemId);
        $kalem->delete();
        if (method_exists($odeme, 'odenenTutariGuncelle')) {
            $odeme->odenenTutariGuncelle();
        }

        return response()->json(['success' => true, 'message' => 'Ödeme kalemi silindi.']);
    }

    public function destroyGelir(Request $request, int $id): JsonResponse
    {
        $this->doktor($request)->odemeler()->findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Gelir silindi.']);
    }

    public function giderler(Request $request): JsonResponse
    {
        $q = $this->doktor($request)->giderler()->with('finansKategori')->latest('tarih');
        if ($request->filled('finans_kategori_id')) {
            $q->where('finans_kategori_id', $request->finans_kategori_id);
        }
        if ($request->filled('tarih_baslangic')) {
            $q->whereDate('tarih', '>=', $request->tarih_baslangic);
        }
        if ($request->filled('tarih_bitis')) {
            $q->whereDate('tarih', '<=', $request->tarih_bitis);
        }
        $items = $q->paginate(min((int) $request->get('per_page', 15), 50));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items->items(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                ],
            ],
        ]);
    }

    public function storeGider(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'baslik' => ['required', 'string', 'max:255'],
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'tarih' => ['required', 'date'],
            'kategori' => ['nullable', 'string', 'max:50'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
            'finans_kategori_id' => ['nullable', 'integer'],
        ]);

        $gider = $doktor->giderler()->create([
            'baslik' => $v['baslik'],
            'tutar' => $v['tutar'],
            'tarih' => $v['tarih'],
            'kategori' => $v['kategori'] ?? 'diger',
            'aciklama' => $v['aciklama'] ?? null,
            'finans_kategori_id' => $v['finans_kategori_id'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Gider eklendi.', 'data' => $gider], 201);
    }

    public function updateGider(Request $request, int $id): JsonResponse
    {
        $gider = $this->doktor($request)->giderler()->findOrFail($id);
        $v = $request->validate([
            'baslik' => ['required', 'string', 'max:255'],
            'finans_kategori_id' => ['nullable', 'integer'],
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'tarih' => ['required', 'date'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
        ]);
        $gider->update($v);

        return response()->json(['success' => true, 'message' => 'Gider güncellendi.', 'data' => $gider->fresh()]);
    }

    public function destroyGider(Request $request, int $id): JsonResponse
    {
        $this->doktor($request)->giderler()->findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Gider silindi.']);
    }

    public function hastaBakiyeleri(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);

        $hastalarQuery = Hasta::query()
            ->where(function ($q) use ($doktor) {
                $q->whereHas('randevular', fn ($r) => $r->where('doktor_id', $doktor->id))
                    ->orWhereHas('odemeler', fn ($o) => $o->where('doktor_id', $doktor->id));
            });

        if ($request->filled('arama') || $request->filled('q')) {
            $arama = $request->get('arama', $request->get('q'));
            $hastalarQuery->where(function ($q) use ($arama) {
                $q->where('ad', 'like', "%{$arama}%")
                    ->orWhere('soyad', 'like', "%{$arama}%")
                    ->orWhere('telefon', 'like', "%{$arama}%");
            });
        }

        $items = $hastalarQuery->get()->map(function (Hasta $hasta) use ($doktor) {
            $odemeler = $hasta->odemeler()->where('doktor_id', $doktor->id)->where('durum', '!=', 'iptal')->get();
            $toplamBorc = (float) $odemeler->sum('tutar');
            $toplamOdenen = (float) $odemeler->sum('odenen_tutar');

            return [
                'id' => $hasta->id,
                'ad' => $hasta->ad,
                'soyad' => $hasta->soyad,
                'ad_soyad' => trim($hasta->ad.' '.$hasta->soyad),
                'telefon' => $hasta->telefon,
                'e_posta' => $hasta->e_posta,
                'toplam_borc' => $toplamBorc,
                'toplam_odenen' => $toplamOdenen,
                'kalan_bakiye' => $toplamBorc - $toplamOdenen,
            ];
        })->filter(function ($h) use ($request) {
            if ($request->boolean('sadece_borclular')) {
                return $h['kalan_bakiye'] > 0;
            }

            return $h['toplam_borc'] > 0;
        })->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function hastaHesap(Request $request, int $hastaId): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hasta = $this->resolveHasta($doktor, $hastaId);

        $odemeler = $doktor->odemeler()
            ->where('hasta_id', $hasta->id)
            ->with(['hizmet', 'finansKategori', 'kalemler'])
            ->orderByDesc('odeme_tarihi')
            ->orderByDesc('id')
            ->get();

        $aktif = $odemeler->where('durum', '!=', 'iptal');
        $toplamBorc = (float) $aktif->sum('tutar');
        $toplamOdenen = (float) $aktif->sum('odenen_tutar');

        $faturalar = $odemeler->map(function ($o) {
            return [
                'id' => $o->id,
                'tutar' => (float) $o->tutar,
                'odenen_tutar' => (float) $o->odenen_tutar,
                'kalan' => max(0, (float) $o->tutar - (float) $o->odenen_tutar),
                'durum' => $o->durum,
                'odeme_yontemi' => $o->odeme_yontemi,
                'odeme_tarihi' => optional($o->odeme_tarihi)->format('Y-m-d') ?? (string) $o->odeme_tarihi,
                'aciklama' => $o->aciklama,
                'hizmet' => $o->hizmet?->ad,
                'kategori' => $o->finansKategori?->ad,
                'kalemler' => $o->kalemler->map(fn ($k) => [
                    'id' => $k->id,
                    'tutar' => (float) $k->tutar,
                    'tarih' => optional($k->tarih)->format('Y-m-d') ?? (string) $k->tarih,
                    'odeme_yontemi' => $k->odeme_yontemi,
                    'not' => $k->not,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'hasta' => [
                    'id' => $hasta->id,
                    'ad_soyad' => trim($hasta->ad.' '.$hasta->soyad),
                    'telefon' => $hasta->telefon,
                    'e_posta' => $hasta->e_posta,
                ],
                'ozet' => [
                    'toplam_borc' => $toplamBorc,
                    'toplam_odenen' => $toplamOdenen,
                    'kalan_bakiye' => $toplamBorc - $toplamOdenen,
                ],
                'faturalar' => $faturalar,
                'acik_faturalar' => $faturalar->whereIn('durum', ['beklemede', 'kismi_odeme'])->values(),
            ],
        ]);
    }

    public function hastaTahsilat(Request $request, int $hastaId): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hasta = $this->resolveHasta($doktor, $hastaId);
        $v = $request->validate([
            'odeme_id' => ['required', 'integer', 'exists:odemeler,id'],
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'tarih' => ['required', 'date'],
            'odeme_yontemi' => ['required', 'in:nakit,kredi_karti,havale,online'],
            'not' => ['nullable', 'string', 'max:500'],
        ]);

        $odeme = $doktor->odemeler()->where('hasta_id', $hasta->id)->where('id', $v['odeme_id'])->firstOrFail();
        if (in_array($odeme->durum, ['iptal', 'odendi'], true)) {
            return response()->json(['success' => false, 'message' => 'Fatura tahsilata kapalı.'], 422);
        }
        $kalan = max(0, (float) $odeme->tutar - (float) $odeme->odenen_tutar);
        if ((float) $v['tutar'] > $kalan + 0.001) {
            return response()->json(['success' => false, 'message' => 'Tutar kalan bakiyeyi aşıyor.'], 422);
        }

        $odeme->kalemler()->create([
            'tutar' => $v['tutar'],
            'tarih' => $v['tarih'],
            'odeme_yontemi' => $v['odeme_yontemi'],
            'not' => $v['not'] ?? 'Hasta hesabından tahsilat',
        ]);
        if (method_exists($odeme, 'odenenTutariGuncelle')) {
            $odeme->odenenTutariGuncelle();
        }

        return response()->json(['success' => true, 'message' => 'Tahsilat kaydedildi.', 'data' => $odeme->fresh(['kalemler'])], 201);
    }

    public function hastaBorcEkle(Request $request, int $hastaId): JsonResponse
    {
        $doktor = $this->doktor($request);
        $hasta = $this->resolveHasta($doktor, $hastaId);
        $v = $request->validate([
            'tutar' => ['required', 'numeric', 'min:0.01'],
            'odeme_tarihi' => ['required', 'date'],
            'hizmet_id' => ['nullable', 'integer', 'exists:hizmetler,id'],
            'finans_kategori_id' => ['nullable', 'integer', 'exists:finans_kategoriler,id'],
            'aciklama' => ['nullable', 'string', 'max:1000'],
            'ilk_odeme_tutar' => ['nullable', 'numeric', 'min:0'],
            'ilk_odeme_yontemi' => ['nullable', 'in:nakit,kredi_karti,havale,online'],
        ]);

        $ilk = (float) ($v['ilk_odeme_tutar'] ?? 0);
        $durum = 'beklemede';
        if ($ilk >= (float) $v['tutar']) {
            $durum = 'odendi';
        } elseif ($ilk > 0) {
            $durum = 'kismi_odeme';
        }

        $odeme = $doktor->odemeler()->create([
            'hasta_id' => $hasta->id,
            'hizmet_id' => $v['hizmet_id'] ?? null,
            'finans_kategori_id' => $v['finans_kategori_id'] ?? null,
            'tutar' => $v['tutar'],
            'odenen_tutar' => $ilk,
            'odeme_yontemi' => $v['ilk_odeme_yontemi'] ?? 'nakit',
            'durum' => $durum,
            'aciklama' => $v['aciklama'] ?? null,
            'odeme_tarihi' => $v['odeme_tarihi'],
        ]);
        if ($ilk > 0) {
            $odeme->kalemler()->create([
                'tutar' => $ilk,
                'tarih' => $v['odeme_tarihi'],
                'odeme_yontemi' => $v['ilk_odeme_yontemi'] ?? 'nakit',
                'not' => 'İlk ödeme',
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Borç eklendi.', 'data' => $odeme->fresh(['kalemler'])], 201);
    }

    protected function resolveHasta(Doktor $doktor, int $hastaId): Hasta
    {
        $hasta = Hasta::query()
            ->whereKey($hastaId)
            ->where(function ($q) use ($doktor) {
                $q->whereHas('randevular', fn ($r) => $r->where('doktor_id', $doktor->id))
                    ->orWhereHas('odemeler', fn ($o) => $o->where('doktor_id', $doktor->id));
            })
            ->first();
        if (! $hasta) {
            abort(404, 'Hasta hesabı bulunamadı.');
        }

        return $hasta;
    }

    public function rapor(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $bas = $request->filled('tarih_baslangic')
            ? Carbon::parse($request->tarih_baslangic)->startOfDay()
            : Carbon::now()->startOfMonth();
        $bit = $request->filled('tarih_bitis')
            ? Carbon::parse($request->tarih_bitis)->endOfDay()
            : Carbon::now()->endOfMonth();

        $odemeler = $doktor->odemeler()
            ->whereBetween('odeme_tarihi', [$bas, $bit])
            ->with(['hasta', 'hizmet'])
            ->orderBy('odeme_tarihi')
            ->get();
        $giderler = $doktor->giderler()
            ->whereBetween('tarih', [$bas, $bit])
            ->orderBy('tarih')
            ->get();

        $toplamGelir = (float) $odemeler->where('durum', '!=', 'iptal')->sum('odenen_tutar');
        $toplamGider = (float) $giderler->sum('tutar');
        $toplamTahsilEdilmeyen = (float) $odemeler->whereIn('durum', ['beklemede', 'kismi_odeme'])->sum(fn ($o) => $o->tutar - $o->odenen_tutar);

        return response()->json([
            'success' => true,
            'data' => [
                'doktor' => [
                    'ad_soyad' => $doktor->ad_soyad,
                    'unvan' => $doktor->unvan,
                    'e_posta' => $doktor->e_posta,
                    'telefon' => $doktor->telefon,
                ],
                'tarih_baslangic' => $bas->toDateString(),
                'tarih_bitis' => $bit->toDateString(),
                'odemeler' => $odemeler,
                'giderler' => $giderler,
                'toplam_gelir' => $toplamGelir,
                'toplam_gider' => $toplamGider,
                'net_kar' => $toplamGelir - $toplamGider,
                'toplam_tahsil_edilmeyen' => $toplamTahsilEdilmeyen,
            ],
        ]);
    }
}
