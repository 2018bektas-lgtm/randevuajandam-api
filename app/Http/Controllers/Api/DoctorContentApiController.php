<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Doktor;
use App\Models\DoktorGaleri;
use App\Models\Faq;
use App\Models\Yorum;
use App\Services\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DoctorContentApiController extends Controller
{
    protected function doktor(Request $request): Doktor
    {
        return $request->attributes->get('auth_doktor');
    }

    // ——— Blog ———
    public function blogs(Request $request): JsonResponse
    {
        $items = $this->doktor($request)->bloglar()->latest()->paginate(20);

        $mapped = collect($items->items())->map(function ($b) {
            $arr = $b->toArray();
            if (! empty($arr['resim'])) {
                $path = $arr['resim'];
                if (! str_starts_with($path, 'storage/') && ! str_starts_with($path, 'uploads/') && ! str_starts_with($path, 'http')) {
                    $path = 'storage/'.$path;
                }
                $arr['resim'] = site_media_url($path);
            }

            return $arr;
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $mapped,
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                ],
            ],
        ]);
    }

    public function storeBlog(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $data = $this->validateBlog($request);

        if ($request->hasFile('resim')) {
            $data['resim'] = $request->file('resim')->store('uploads/blog', 'public');
        }
        $data['aktif_mi'] = $request->boolean('aktif_mi', true);

        $blog = $doktor->bloglar()->create($data);

        return response()->json(['success' => true, 'message' => 'Blog eklendi.', 'data' => $blog], 201);
    }

    public function updateBlog(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $blog = $doktor->bloglar()->findOrFail($id);
        $data = $this->validateBlog($request);

        if ($request->hasFile('resim')) {
            if ($blog->resim) {
                Storage::disk('public')->delete($blog->resim);
            }
            $data['resim'] = $request->file('resim')->store('uploads/blog', 'public');
        }
        if ($request->has('aktif_mi')) {
            $data['aktif_mi'] = $request->boolean('aktif_mi');
        }

        $blog->update($data);

        return response()->json(['success' => true, 'message' => 'Blog güncellendi.', 'data' => $blog->fresh()]);
    }

    public function destroyBlog(Request $request, int $id): JsonResponse
    {
        $blog = $this->doktor($request)->bloglar()->findOrFail($id);
        $blog->delete();

        return response()->json(['success' => true, 'message' => 'Blog silindi.']);
    }

    protected function validateBlog(Request $request): array
    {
        $data = $request->validate([
            'baslik' => ['required', 'string', 'max:255'],
            'icerik' => ['required', 'string'],
            'resim' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
            'meta_baslik' => ['nullable', 'string', 'max:255'],
            'meta_aciklama' => ['nullable', 'string', 'max:255'],
            'meta_anahtar_kelimeler' => ['nullable', 'string', 'max:255'],
            'aktif_mi' => ['nullable'],
        ]);
        $data['icerik'] = HtmlSanitizer::clean($data['icerik'] ?? '');

        return $data;
    }

    // ——— FAQ ———
    public function faqs(Request $request): JsonResponse
    {
        $items = $this->doktor($request)->faqs()->orderBy('sira')->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'soru' => ['required', 'string', 'max:255'],
            'cevap' => ['required', 'string'],
            'sira' => ['nullable', 'integer', 'min:0'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        $faq = $doktor->faqs()->create([
            'soru' => strip_tags($v['soru']),
            'cevap' => HtmlSanitizer::clean($v['cevap']),
            'sira' => $v['sira'] ?? 0,
            'aktif' => $request->boolean('aktif', true),
        ]);

        return response()->json(['success' => true, 'message' => 'SSS eklendi.', 'data' => $faq], 201);
    }

    public function updateFaq(Request $request, int $id): JsonResponse
    {
        $faq = $this->doktor($request)->faqs()->findOrFail($id);
        $v = $request->validate([
            'soru' => ['required', 'string', 'max:255'],
            'cevap' => ['required', 'string'],
            'sira' => ['nullable', 'integer', 'min:0'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        $faq->update([
            'soru' => strip_tags($v['soru']),
            'cevap' => HtmlSanitizer::clean($v['cevap']),
            'sira' => $v['sira'] ?? 0,
            'aktif' => $request->has('aktif') ? $request->boolean('aktif') : $faq->aktif,
        ]);

        return response()->json(['success' => true, 'message' => 'SSS güncellendi.', 'data' => $faq->fresh()]);
    }

    public function destroyFaq(Request $request, int $id): JsonResponse
    {
        $this->doktor($request)->faqs()->findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'SSS silindi.']);
    }

    public function toggleFaq(Request $request, int $id): JsonResponse
    {
        $faq = $this->doktor($request)->faqs()->findOrFail($id);
        $faq->update(['aktif' => ! $faq->aktif]);

        return response()->json(['success' => true, 'data' => $faq->fresh()]);
    }

    // ——— Gallery ———
    public function gallery(Request $request): JsonResponse
    {
        $items = $this->doktor($request)->galeriler()->orderBy('sira')->get()->map(function (DoktorGaleri $g) {
            return [
                'id' => $g->id,
                'baslik' => $g->baslik,
                'sira' => $g->sira,
                'resim_yolu' => $g->resim_yolu,
                'url' => site_media_url($g->resim_yolu),
            ];
        });

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function storeGallery(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $request->validate([
            'resimler' => ['required', 'array'],
            'resimler.*' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'basliklar' => ['nullable', 'array'],
            'basliklar.*' => ['nullable', 'string', 'max:255'],
        ]);

        $uploadPath = app('shared_public_path').'/uploads/galeri';
        if (! File::exists($uploadPath)) {
            File::makeDirectory($uploadPath, 0755, true);
        }

        $maxSira = $doktor->galeriler()->max('sira') ?? 0;
        $created = [];
        $basliklar = $request->input('basliklar', []);

        foreach ($request->file('resimler') as $index => $file) {
            $fileName = 'doktor_'.$doktor->id.'_galeri_'.time().'_'.rand(1000, 9999).'.'.$file->getClientOriginalExtension();
            $file->move($uploadPath, $fileName);
            $created[] = $doktor->galeriler()->create([
                'resim_yolu' => 'uploads/galeri/'.$fileName,
                'baslik' => $basliklar[$index] ?? null,
                'sira' => ++$maxSira,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => count($created).' fotoğraf eklendi.',
            'data' => $created,
        ], 201);
    }

    public function updateGallery(Request $request, int $id): JsonResponse
    {
        $g = $this->doktor($request)->galeriler()->findOrFail($id);
        $v = $request->validate([
            'baslik' => ['nullable', 'string', 'max:255'],
            'sira' => ['nullable', 'integer', 'min:0'],
        ]);
        $g->update($v);

        return response()->json(['success' => true, 'message' => 'Galeri güncellendi.', 'data' => $g->fresh()]);
    }

    public function destroyGallery(Request $request, int $id): JsonResponse
    {
        $g = $this->doktor($request)->galeriler()->findOrFail($id);
        $path = app('shared_public_path').'/'.ltrim((string) $g->resim_yolu, '/');
        if ($g->resim_yolu && File::exists($path)) {
            File::delete($path);
        }
        $g->delete();

        return response()->json(['success' => true, 'message' => 'Fotoğraf silindi.']);
    }

    public function sortGallery(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $v = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($v['ids'] as $index => $id) {
            $doktor->galeriler()->where('id', $id)->update(['sira' => $index + 1]);
        }

        return response()->json(['success' => true, 'message' => 'Sıralama güncellendi.']);
    }

    // ——— Reviews ———
    public function reviews(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $q = $doktor->yorumlar()->with('hasta:id,ad,soyad')->latest();

        if ($request->filled('durum')) {
            $q->where('onay_durumu', $request->durum);
        }

        $items = $q->paginate(20);
        $stats = [
            'toplam' => $doktor->yorumlar()->count(),
            'beklemede' => $doktor->yorumlar()->where('onay_durumu', 'beklemede')->count(),
            'onaylandi' => $doktor->yorumlar()->where('onay_durumu', 'onaylandi')->count(),
            'ortalama_puan' => $doktor->ortalama_puan,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items->items(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                ],
                'stats' => $stats,
            ],
        ]);
    }

    public function replyReview(Request $request, int $id): JsonResponse
    {
        $yorum = $this->doktor($request)->yorumlar()->findOrFail($id);
        $v = $request->validate([
            'doktor_yaniti' => ['required', 'string', 'min:5', 'max:500'],
            'onay_durumu' => ['nullable', 'in:beklemede,onaylandi,reddedildi'],
        ]);

        $data = ['doktor_yaniti' => $v['doktor_yaniti']];
        if (! empty($v['onay_durumu'])) {
            $data['onay_durumu'] = $v['onay_durumu'];
        }
        $yorum->update($data);

        return response()->json(['success' => true, 'message' => 'Yanıt kaydedildi.', 'data' => $yorum->fresh()]);
    }

    public function moderateReview(Request $request, int $id): JsonResponse
    {
        $yorum = $this->doktor($request)->yorumlar()->findOrFail($id);
        $v = $request->validate([
            'onay_durumu' => ['required', 'in:beklemede,onaylandi,reddedildi'],
        ]);
        $yorum->update(['onay_durumu' => $v['onay_durumu']]);

        return response()->json(['success' => true, 'message' => 'Yorum durumu güncellendi.', 'data' => $yorum->fresh()]);
    }
}
