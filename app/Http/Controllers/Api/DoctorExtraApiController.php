<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeklemeListesi;
use App\Models\Doktor;
use App\Models\Hasta;
use App\Models\HastaDosya;
use App\Models\OnamFormu;
use App\Models\OnamImza;
use App\Services\BeklemeListesiService;
use App\Services\HtmlSanitizer;
use App\Support\PublicMedia;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ana site ile parity: bekleme, iCal, hasta dosya/export, onam.
 */
class DoctorExtraApiController extends Controller
{
    protected function doktor(Request $request): Doktor
    {
        return $request->attributes->get('auth_doktor');
    }

    // ── Bekleme listesi ───────────────────────────────────────

    public function waitlist(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $items = BeklemeListesi::query()
            ->where('doktor_id', $doktor->id)
            ->orderByDesc('id')
            ->paginate(min((int) $request->get('per_page', 20), 50));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => collect($items->items())->map(fn ($b) => [
                    'id' => $b->id,
                    'ad' => $b->ad,
                    'soyad' => $b->soyad,
                    'telefon' => $b->telefon,
                    'e_posta' => $b->e_posta,
                    'durum' => $b->durum ?? null,
                    'tercih_tarih' => $b->tercih_tarih,
                    'tercih_saat' => $b->tercih_saat,
                    'not' => $b->not,
                    'created_at' => $b->created_at?->toIso8601String(),
                ])->values(),
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'total' => $items->total(),
                ],
            ],
        ]);
    }

    public function waitlistUpdateStatus(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $row = BeklemeListesi::query()->where('doktor_id', $doktor->id)->findOrFail($id);
        $data = $request->validate([
            'durum' => ['required', 'string', 'max:40'],
        ]);
        $row->update(['durum' => $data['durum']]);

        return response()->json(['success' => true, 'message' => 'Durum güncellendi.']);
    }

    public function waitlistNotify(Request $request, int $id, BeklemeListesiService $service): JsonResponse
    {
        $doktor = $this->doktor($request);
        $row = BeklemeListesi::query()->where('doktor_id', $doktor->id)->findOrFail($id);
        try {
            if (method_exists($service, 'bildir')) {
                $service->bildir($row);
            } elseif (method_exists($service, 'notifyEntry')) {
                $service->notifyEntry($row);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Bildirim gönderildi.']);
    }

    public function waitlistDestroy(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        BeklemeListesi::query()->where('doktor_id', $doktor->id)->whereKey($id)->delete();

        return response()->json(['success' => true, 'message' => 'Kayıt silindi.']);
    }

    // ── iCal ──────────────────────────────────────────────────

    public function ical(Request $request)
    {
        $doktor = $this->doktor($request);
        $from = now()->subMonths(1)->startOfDay();
        $to = now()->addMonths(6)->endOfDay();
        $randevular = $doktor->randevular()
            ->with(['hasta', 'hizmet'])
            ->whereBetween('tarih', [$from->toDateString(), $to->toDateString()])
            ->whereIn('durum', ['beklemede', 'onaylandi', 'tamamlandi'])
            ->orderBy('tarih')->orderBy('saat')
            ->get();

        $periyot = (int) ($doktor->randevuAyari?->randevu_periyodu ?? 30);
        if ($periyot < 5) {
            $periyot = 30;
        }

        $esc = fn (string $t) => addcslashes(str_replace(["\r\n", "\n", "\r"], '\\n', $t), ',;\\');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0',
            'PRODID:-//Randevu Ajandam//Hekim Takvim//TR',
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$esc(($doktor->ad_soyad ?? 'Hekim').' Randevuları'),
        ];
        foreach ($randevular as $r) {
            $tarih = $r->tarih instanceof \DateTimeInterface
                ? $r->tarih->format('Y-m-d')
                : Carbon::parse($r->tarih)->toDateString();
            $saat = substr((string) $r->saat, 0, 8);
            if (strlen($saat) === 5) {
                $saat .= ':00';
            }
            $start = Carbon::parse($tarih.' '.$saat);
            $end = $start->copy()->addMinutes($periyot);
            $summary = ($r->hizmet?->ad ?? 'Randevu').' — '.($r->hasta?->ad_soyad ?? 'Hasta');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:randevu-'.$r->id.'@randevuajandam';
            $lines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$start->format('Ymd\THis');
            $lines[] = 'DTEND:'.$end->format('Ymd\THis');
            $lines[] = 'SUMMARY:'.$esc($summary);
            $lines[] = 'STATUS:'.($r->durum === 'iptal' ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $ics = implode("\r\n", $lines)."\r\n";
        $filename = 'randevular-'.Str::slug($doktor->ad_soyad ?? 'hekim').'.ics';

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // ── Hasta export ──────────────────────────────────────────

    public function patientsExport(Request $request): StreamedResponse
    {
        $doktor = $this->doktor($request);
        $ids = $doktor->randevular()->distinct()->pluck('hasta_id');
        $hastalar = Hasta::whereIn('id', $ids)->orderBy('ad')->orderBy('soyad')->get();
        $filename = 'hastalar-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($hastalar, $doktor) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Ad', 'Soyad', 'Telefon', 'E-posta', 'Randevu'], ';');
            foreach ($hastalar as $h) {
                $cnt = $doktor->randevular()->where('hasta_id', $h->id)->count();
                fputcsv($out, [$h->id, $h->ad, $h->soyad, $h->telefon, $h->e_posta, $cnt], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Hasta dosya ───────────────────────────────────────────

    public function patientFiles(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $this->assertHasta($doktor, $id);
        $files = HastaDosya::where('doktor_id', $doktor->id)->where('hasta_id', $id)->orderByDesc('id')->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'baslik' => $f->baslik,
                'url' => site_media_url($f->dosya_yolu),
                'orijinal_ad' => $f->orijinal_ad,
                'not' => $f->not,
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $files]);
    }

    public function storePatientFile(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $this->assertHasta($doktor, $id);
        $data = $request->validate([
            'dosya' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx'],
            'baslik' => ['nullable', 'string', 'max:255'],
            'not' => ['nullable', 'string', 'max:1000'],
        ]);
        $file = $request->file('dosya');
        $path = PublicMedia::store($file, 'uploads/hasta-dosya');
        $row = HastaDosya::create([
            'doktor_id' => $doktor->id,
            'hasta_id' => $id,
            'baslik' => $data['baslik'] ?? $file->getClientOriginalName(),
            'dosya_yolu' => $path,
            'orijinal_ad' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'boyut' => $file->getSize(),
            'not' => $data['not'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $row->id, 'url' => site_media_url($row->dosya_yolu)]], 201);
    }

    public function destroyPatientFile(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $row = HastaDosya::where('doktor_id', $doktor->id)->findOrFail($id);
        PublicMedia::delete($row->dosya_yolu);
        $row->delete();

        return response()->json(['success' => true, 'message' => 'Silindi.']);
    }

    // ── Onam ──────────────────────────────────────────────────

    public function consentForms(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $forms = $doktor->onamFormlari()->orderBy('sira')->orderByDesc('id')->get()->map(fn ($f) => [
            'id' => $f->id,
            'baslik' => $f->baslik,
            'icerik' => $f->icerik,
            'aktif_mi' => (bool) $f->aktif_mi,
        ]);

        return response()->json(['success' => true, 'data' => $forms]);
    }

    public function storeConsentForm(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $data = $request->validate([
            'baslik' => ['required', 'string', 'max:255'],
            'icerik' => ['required', 'string', 'max:50000'],
            'aktif_mi' => ['nullable', 'boolean'],
        ]);
        $form = $doktor->onamFormlari()->create([
            'baslik' => $data['baslik'],
            'icerik' => HtmlSanitizer::clean($data['icerik']),
            'aktif_mi' => $data['aktif_mi'] ?? true,
            'sira' => (int) ($doktor->onamFormlari()->max('sira') ?? 0) + 1,
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $form->id]], 201);
    }

    public function updateConsentForm(Request $request, int $id): JsonResponse
    {
        $doktor = $this->doktor($request);
        $form = $doktor->onamFormlari()->findOrFail($id);
        $data = $request->validate([
            'baslik' => ['required', 'string', 'max:255'],
            'icerik' => ['required', 'string', 'max:50000'],
            'aktif_mi' => ['nullable', 'boolean'],
        ]);
        $form->update([
            'baslik' => $data['baslik'],
            'icerik' => HtmlSanitizer::clean($data['icerik']),
            'aktif_mi' => $data['aktif_mi'] ?? $form->aktif_mi,
        ]);

        return response()->json(['success' => true, 'message' => 'Güncellendi.']);
    }

    public function destroyConsentForm(Request $request, int $id): JsonResponse
    {
        $this->doktor($request)->onamFormlari()->whereKey($id)->delete();

        return response()->json(['success' => true, 'message' => 'Silindi.']);
    }

    public function signConsentForm(Request $request): JsonResponse
    {
        $doktor = $this->doktor($request);
        $data = $request->validate([
            'onam_form_id' => ['required', 'integer'],
            'hasta_id' => ['required', 'integer'],
            'not' => ['nullable', 'string', 'max:1000'],
        ]);
        $form = $doktor->onamFormlari()->where('aktif_mi', true)->findOrFail($data['onam_form_id']);
        $this->assertHasta($doktor, (int) $data['hasta_id']);
        $hasta = Hasta::findOrFail($data['hasta_id']);
        $imza = OnamImza::create([
            'onam_form_id' => $form->id,
            'doktor_id' => $doktor->id,
            'hasta_id' => $hasta->id,
            'hasta_ad_soyad' => trim($hasta->ad.' '.$hasta->soyad),
            'ip' => $request->ip(),
            'imzalandi_at' => now(),
            'not' => $data['not'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $imza->id]], 201);
    }

    private function assertHasta(Doktor $doktor, int $hastaId): void
    {
        $ids = $doktor->randevular()->whereNotNull('hasta_id')->distinct()->pluck('hasta_id');
        abort_unless($ids->contains($hastaId), 404);
    }
}
