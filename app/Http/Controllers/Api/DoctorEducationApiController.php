<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doktor;
use App\Models\Egitim;
use App\Models\EgitimBasvuru;
use App\Models\EgitimFormAlani;
use App\Services\EgitimBasvuruService;
use App\Services\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doctor panel: education CRUD + applications (same DB as main site hekim panel).
 */
class DoctorEducationApiController extends Controller
{
    protected function doktor(Request $request): Doktor
    {
        return $request->attributes->get('auth_doktor');
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->doktor($request)->egitimler()
            ->withCount([
                'basvurular',
                'basvurular as bekleyen_basvuru' => fn ($q) => $q->where('durum', 'beklemede'),
            ])
            ->paginate(min(50, max(1, (int) $request->input('per_page', 12))));

        $mapped = collect($items->items())->map(fn (Egitim $e) => $this->mapEgitimListItem($e))->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $mapped,
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $egitim = $this->doktor($request)->egitimler()
            ->with(['formAlanlari' => fn ($q) => $q->orderBy('sira')])
            ->withCount([
                'basvurular',
                'basvurular as bekleyen_basvuru' => fn ($q) => $q->where('durum', 'beklemede'),
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->mapEgitimDetail($egitim),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $data = $this->validated($request);

        if ($request->hasFile('kapak')) {
            $data['kapak'] = $request->file('kapak')->store('uploads/egitim', 'public');
        }

        $data['basvuru_acik_mi'] = $request->boolean('basvuru_acik_mi', true);
        $egitim = $doktor->egitimler()->create($data);
        $this->syncFormAlanlari($egitim, $this->alanlarInput($request));

        $egitim->load(['formAlanlari' => fn ($q) => $q->orderBy('sira')]);

        return response()->json([
            'success' => true,
            'message' => 'Eğitim kaydedildi.',
            'data' => $this->mapEgitimDetail($egitim),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $egitim = $doktor->egitimler()->findOrFail($id);
        $data = $this->validated($request);

        if ($request->boolean('kapak_sil') && $egitim->kapak) {
            Storage::disk('public')->delete($egitim->kapak);
            $data['kapak'] = null;
        } elseif ($request->hasFile('kapak')) {
            if ($egitim->kapak) {
                Storage::disk('public')->delete($egitim->kapak);
            }
            $data['kapak'] = $request->file('kapak')->store('uploads/egitim', 'public');
        }

        $data['basvuru_acik_mi'] = $request->boolean('basvuru_acik_mi');
        $egitim->update($data);
        $this->syncFormAlanlari($egitim, $this->alanlarInput($request));

        $egitim->load(['formAlanlari' => fn ($q) => $q->orderBy('sira')]);

        return response()->json([
            'success' => true,
            'message' => 'Eğitim güncellendi.',
            'data' => $this->mapEgitimDetail($egitim->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $egitim = $this->doktor($request)->egitimler()->findOrFail($id);
        if ($egitim->kapak) {
            Storage::disk('public')->delete($egitim->kapak);
        }
        $egitim->delete();

        return response()->json(['success' => true, 'message' => 'Eğitim silindi.']);
    }

    /**
     * All applications across doctor's educations.
     */
    public function applicationsAll(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $query = EgitimBasvuru::query()
            ->where('doktor_id', $doktor->id)
            ->with(['egitim:id,baslik,slug,fiyat,durum'])
            ->orderByDesc('id');

        $this->applyApplicationFilters($query, $request);

        $items = $query->paginate(min(50, max(1, (int) $request->input('per_page', 20))));
        $egitimler = $doktor->egitimler()->orderByDesc('id')->get(['id', 'baslik']);

        $ozet = [
            'toplam' => EgitimBasvuru::where('doktor_id', $doktor->id)->count(),
            'beklemede' => EgitimBasvuru::where('doktor_id', $doktor->id)->where('durum', 'beklemede')->count(),
            'onaylandi' => EgitimBasvuru::where('doktor_id', $doktor->id)->where('durum', 'onaylandi')->count(),
            'odeme_bekleyen' => EgitimBasvuru::where('doktor_id', $doktor->id)
                ->whereIn('ucret_durumu', ['bekliyor', 'kismi'])
                ->count(),
        ];

        $alanEtiketleri = EgitimFormAlani::query()
            ->whereIn('egitim_id', $egitimler->pluck('id'))
            ->get(['id', 'egitim_id', 'etiket'])
            ->mapWithKeys(fn ($a) => [(string) $a->id => [
                'id' => $a->id,
                'egitim_id' => $a->egitim_id,
                'etiket' => $a->etiket,
            ]])
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'egitim' => null,
                'tumu' => true,
                'items' => collect($items->items())->map(fn (EgitimBasvuru $b) => $this->mapBasvuru($b))->values(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                ],
                'egitimler' => $egitimler->map(fn ($e) => ['id' => $e->id, 'baslik' => $e->baslik])->values(),
                'ozet' => $ozet,
                'alan_etiketleri' => $alanEtiketleri,
            ],
        ]);
    }

    public function applications(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $egitim = $doktor->egitimler()->with('formAlanlari')->findOrFail($id);

        $query = $egitim->basvurular()->orderByDesc('id');
        $this->applyApplicationFilters($query, $request, false);

        $items = $query->paginate(min(50, max(1, (int) $request->input('per_page', 20))));

        $ozet = [
            'toplam' => $egitim->basvurular()->count(),
            'beklemede' => $egitim->basvurular()->where('durum', 'beklemede')->count(),
            'onaylandi' => $egitim->basvurular()->where('durum', 'onaylandi')->count(),
            'odeme_bekleyen' => $egitim->basvurular()
                ->whereIn('ucret_durumu', ['bekliyor', 'kismi'])
                ->count(),
        ];

        $alanEtiketleri = $egitim->formAlanlari->mapWithKeys(fn ($a) => [(string) $a->id => [
            'id' => $a->id,
            'egitim_id' => $a->egitim_id,
            'etiket' => $a->etiket,
        ]])->all();

        return response()->json([
            'success' => true,
            'data' => [
                'egitim' => $this->mapEgitimListItem($egitim),
                'tumu' => false,
                'items' => collect($items->items())->map(fn (EgitimBasvuru $b) => $this->mapBasvuru($b, $egitim))->values(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                ],
                'egitimler' => [['id' => $egitim->id, 'baslik' => $egitim->baslik]],
                'ozet' => $ozet,
                'alan_etiketleri' => $alanEtiketleri,
            ],
        ]);
    }

    public function updateApplicationStatus(Request $request, int $id, int $basvuruId): JsonResponse
    {
        $egitim = $this->doktor($request)->egitimler()->findOrFail($id);
        $basvuru = $egitim->basvurular()->findOrFail($basvuruId);

        $data = $request->validate([
            'durum' => ['required', 'in:beklemede,onaylandi,reddedildi,iptal'],
            'hekim_notu' => ['nullable', 'string', 'max:2000'],
        ]);

        $basvuru->update([
            'durum' => $data['durum'],
            'hekim_notu' => $data['hekim_notu'] ?? $basvuru->hekim_notu,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Başvuru durumu güncellendi.',
            'data' => $this->mapBasvuru($basvuru->fresh(['egitim:id,baslik,slug,fiyat,durum']), $egitim),
        ]);
    }

    public function markApplicationPaid(Request $request, int $id, int $basvuruId, EgitimBasvuruService $service): JsonResponse
    {
        $egitim = $this->doktor($request)->egitimler()->findOrFail($id);
        $basvuru = $egitim->basvurular()->findOrFail($basvuruId);

        $data = $request->validate([
            'odenen_tutar' => ['required', 'numeric', 'min:0.01'],
            'odeme_yontemi' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $service->odemeAlindi(
                $basvuru,
                (float) $data['odenen_tutar'],
                $data['odeme_yontemi'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ödeme kaydedildi ve finans gelirlerine yansıtıldı (kategori: Eğitim).',
            'data' => $this->mapBasvuru($basvuru->fresh(['egitim:id,baslik,slug,fiyat,durum']), $egitim),
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\EgitimBasvuru>  $query
     */
    protected function applyApplicationFilters($query, Request $request, bool $allowEgitimId = true): void
    {
        if ($request->filled('durum')) {
            $query->where('durum', $request->input('durum'));
        }
        if ($request->filled('ucret')) {
            $query->where('ucret_durumu', $request->input('ucret'));
        }
        if ($allowEgitimId && $request->filled('egitim_id')) {
            $query->where('egitim_id', (int) $request->input('egitim_id'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($w) use ($q) {
                $w->where('ad', 'like', '%'.$q.'%')
                    ->orWhere('soyad', 'like', '%'.$q.'%')
                    ->orWhere('telefon', 'like', '%'.$q.'%')
                    ->orWhere('e_posta', 'like', '%'.$q.'%');
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'baslik' => ['required', 'string', 'max:255'],
            'ozet' => ['nullable', 'string', 'max:2000'],
            'icerik' => ['nullable', 'string'],
            'kapak' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'tip' => ['required', 'in:yuz_yuze,online,hibrit'],
            'baslangic_at' => ['nullable', 'date'],
            'bitis_at' => ['nullable', 'date', 'after_or_equal:baslangic_at'],
            'mekan' => ['nullable', 'string', 'max:255'],
            'online_url' => ['nullable', 'string', 'max:500'],
            'fiyat' => ['nullable', 'numeric', 'min:0'],
            'odeme_notu' => ['nullable', 'string', 'max:500'],
            'kontenjan' => ['nullable', 'integer', 'min:1'],
            'basvuru_bitis_at' => ['nullable', 'date'],
            'durum' => ['required', 'in:taslak,yayinda,arsiv'],
            'meta_baslik' => ['nullable', 'string', 'max:255'],
            'meta_aciklama' => ['nullable', 'string', 'max:500'],
            'meta_anahtar_kelimeler' => ['nullable', 'string', 'max:255'],
            'sira' => ['nullable', 'integer', 'min:0'],
            'alanlar' => ['nullable', 'array'],
            'alanlar.*.id' => ['nullable', 'integer'],
            'alanlar.*.etiket' => ['nullable', 'string', 'max:120'],
            'alanlar.*.tip' => ['nullable', 'in:text,textarea,email,phone,number,select,checkbox,date'],
            'alanlar.*.zorunlu_mu' => ['nullable'],
            'alanlar.*.secenekler' => ['nullable', 'string'],
            'alanlar.*.placeholder' => ['nullable', 'string', 'max:255'],
            'basvuru_acik_mi' => ['nullable'],
            'kapak_sil' => ['nullable'],
        ], [
            'baslik.required' => 'Eğitim başlığı zorunludur.',
        ]);

        $data['sira'] = (int) ($data['sira'] ?? 0);
        if (array_key_exists('fiyat', $data) && ($data['fiyat'] === '' || $data['fiyat'] === null)) {
            $data['fiyat'] = null;
        }
        if (array_key_exists('kontenjan', $data) && ($data['kontenjan'] === '' || $data['kontenjan'] === null)) {
            $data['kontenjan'] = null;
        }

        // Empty datetime strings → null
        foreach (['baslangic_at', 'bitis_at', 'basvuru_bitis_at'] as $dt) {
            if (array_key_exists($dt, $data) && ($data[$dt] === '' || $data[$dt] === null)) {
                $data[$dt] = null;
            }
        }

        $clean = collect($data)->except(['alanlar', 'kapak', 'basvuru_acik_mi', 'kapak_sil'])->all();
        if (array_key_exists('icerik', $clean)) {
            $clean['icerik'] = HtmlSanitizer::clean($clean['icerik'] ?? '');
        }
        if (array_key_exists('ozet', $clean) && is_string($clean['ozet'])) {
            $clean['ozet'] = strip_tags($clean['ozet']);
        }

        return $clean;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function alanlarInput(Request $request): array
    {
        $alanlar = $request->input('alanlar', []);
        if (! is_array($alanlar)) {
            return [];
        }

        return $alanlar;
    }

    /**
     * @param  array<int, array<string, mixed>>  $alanlar
     */
    protected function syncFormAlanlari(Egitim $egitim, array $alanlar): void
    {
        $keep = [];
        $sira = 0;
        foreach ($alanlar as $row) {
            if (! is_array($row)) {
                continue;
            }
            $etiket = trim((string) ($row['etiket'] ?? ''));
            if ($etiket === '') {
                continue;
            }
            $tip = (string) ($row['tip'] ?? 'text');
            $anahtar = Str::slug($etiket, '_');
            if ($anahtar === '') {
                $anahtar = 'alan_'.$sira;
            }
            $secenekler = null;
            if ($tip === 'select' && ! empty($row['secenekler'])) {
                $secenekler = collect(preg_split('/\r\n|\r|\n/', (string) $row['secenekler']))
                    ->map(fn ($l) => trim($l))
                    ->filter()
                    ->values()
                    ->all();
            }

            $payload = [
                'egitim_id' => $egitim->id,
                'etiket' => $etiket,
                'anahtar' => $anahtar,
                'tip' => $tip,
                'zorunlu_mu' => filter_var($row['zorunlu_mu'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || in_array($row['zorunlu_mu'] ?? null, [1, '1', 'on', 'true', true], true),
                'secenekler' => $secenekler,
                'placeholder' => $row['placeholder'] ?? null,
                'sira' => $sira++,
                'aktif_mi' => true,
            ];

            if (! empty($row['id'])) {
                $alan = $egitim->formAlanlari()->where('id', $row['id'])->first();
                if ($alan) {
                    $alan->update($payload);
                    $keep[] = $alan->id;

                    continue;
                }
            }

            $alan = EgitimFormAlani::create($payload);
            $keep[] = $alan->id;
        }

        $egitim->formAlanlari()->whereNotIn('id', $keep ?: [0])->delete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapEgitimListItem(Egitim $e): array
    {
        return [
            'id' => $e->id,
            'baslik' => $e->baslik,
            'slug' => $e->slug,
            'ozet' => $e->ozet,
            'tip' => $e->tip,
            'durum' => $e->durum,
            'fiyat' => $e->fiyat,
            'baslangic_at' => optional($e->baslangic_at)?->toIso8601String(),
            'bitis_at' => optional($e->bitis_at)?->toIso8601String(),
            'basvuru_acik_mi' => (bool) $e->basvuru_acik_mi,
            'kapak' => $this->kapakUrl($e->kapak),
            'basvurular_count' => (int) ($e->basvurular_count ?? 0),
            'bekleyen_basvuru' => (int) ($e->bekleyen_basvuru ?? 0),
            'public_path' => '/egitimler/'.($e->slug ?: $e->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapEgitimDetail(Egitim $e): array
    {
        $base = $this->mapEgitimListItem($e);

        return array_merge($base, [
            'icerik' => $e->icerik,
            'mekan' => $e->mekan,
            'online_url' => $e->online_url,
            'odeme_notu' => $e->odeme_notu,
            'kontenjan' => $e->kontenjan,
            'basvuru_bitis_at' => optional($e->basvuru_bitis_at)?->toIso8601String(),
            'meta_baslik' => $e->meta_baslik,
            'meta_aciklama' => $e->meta_aciklama,
            'meta_anahtar_kelimeler' => $e->meta_anahtar_kelimeler,
            'sira' => $e->sira,
            'form_alanlari' => $e->relationLoaded('formAlanlari')
                ? $e->formAlanlari->map(fn ($a) => [
                    'id' => $a->id,
                    'etiket' => $a->etiket,
                    'anahtar' => $a->anahtar,
                    'tip' => $a->tip,
                    'zorunlu_mu' => (bool) $a->zorunlu_mu,
                    'secenekler' => is_array($a->secenekler)
                        ? implode("\n", $a->secenekler)
                        : (string) ($a->secenekler ?? ''),
                    'placeholder' => $a->placeholder,
                    'sira' => $a->sira,
                ])->values()->all()
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapBasvuru(EgitimBasvuru $b, ?Egitim $egitim = null): array
    {
        $eg = $b->relationLoaded('egitim') ? $b->egitim : $egitim;

        return [
            'id' => $b->id,
            'egitim_id' => $b->egitim_id,
            'ad' => $b->ad,
            'soyad' => $b->soyad,
            'ad_soyad' => $b->ad_soyad,
            'telefon' => $b->telefon,
            'e_posta' => $b->e_posta,
            'cevaplar' => $b->cevaplar ?? [],
            'durum' => $b->durum,
            'ucret_durumu' => $b->ucret_durumu,
            'ucret_tutari' => $b->ucret_tutari,
            'odenen_tutar' => $b->odenen_tutar,
            'odeme_yontemi' => $b->odeme_yontemi,
            'hekim_notu' => $b->hekim_notu,
            'created_at' => optional($b->created_at)?->toIso8601String(),
            'egitim' => $eg ? [
                'id' => $eg->id,
                'baslik' => $eg->baslik,
                'slug' => $eg->slug,
                'fiyat' => $eg->fiyat,
                'durum' => $eg->durum,
            ] : null,
        ];
    }

    protected function kapakUrl(?string $kapak): ?string
    {
        if (! $kapak) {
            return null;
        }
        $path = $kapak;
        if (! str_starts_with($path, 'storage/') && ! str_starts_with($path, 'uploads/') && ! str_starts_with($path, 'http')) {
            $path = 'storage/'.$path;
        }

        return function_exists('site_media_url') ? site_media_url($path) : $path;
    }
}
