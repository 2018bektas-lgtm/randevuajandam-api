<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doktor;
use App\Services\AsistanService;
use App\Support\PaketYetki;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsistanApiController extends Controller
{
    public function __construct(protected AsistanService $asistanService) {}

    protected function doktor(Request $request): Doktor
    {
        return $request->attributes->get('auth_doktor');
    }

    public function mesaj(Request $request): JsonResponse
    {
        $request->validate([
            'mesaj'              => 'sometimes|string|max:500',
            'gecmis'             => 'sometimes|array|max:20',
            'onay'               => 'sometimes|array',
            'onay.fonksiyon'     => 'required_with:onay|string',
            'onay.parametreler'  => 'required_with:onay|array',
            'secim'              => 'sometimes|string|in:sadece_kapat,kapat_ve_iptal,kapat_iptal_sms,kapat_bekleme,vazgec',
            'secim_parametreler' => 'required_with:secim|array',
        ]);

        $doktor   = $this->doktor($request);
        $doktorId = $doktor->id;

        if (! PaketYetki::has($doktor, 'ai_asistan')) {
            return response()->json([
                'success' => false,
                'message' => 'AI Asistan özelliği Profesyonel ve üzeri paketlerde kullanılabilir.',
            ], 403);
        }

        if ($request->filled('onay')) {
            $onay  = $request->input('onay');
            $sonuc = $this->asistanService->onayliIsleCalistir(
                $doktorId,
                $onay['fonksiyon'],
                $onay['parametreler'],
            );
            return response()->json(['success' => true, 'yanit' => $sonuc['yanit'], 'onay_gerekli' => null]);
        }

        if ($request->filled('secim')) {
            $secim  = $request->input('secim');
            $params = (array) $request->input('secim_parametreler', []);

            if ($secim === 'vazgec') {
                return response()->json(['success' => true, 'yanit' => 'İptal edildi.', 'onay_gerekli' => null]);
            }

            $fonksiyon        = match ($secim) {
                'kapat_ve_iptal',
                'kapat_iptal_sms',
                'kapat_bekleme'  => 'takvim_kapat_ve_iptal',
                default          => 'takvim_kapat',
            };
            $params['_secim'] = $secim;
            $sonuc            = $this->asistanService->onayliIsleCalistir($doktorId, $fonksiyon, $params);
            return response()->json(['success' => true, 'yanit' => $sonuc['yanit'], 'onay_gerekli' => null]);
        }

        $mesaj = trim((string) $request->input('mesaj', ''));
        if ($mesaj === '') {
            return response()->json(['success' => false, 'message' => 'Mesaj boş olamaz.'], 422);
        }

        $gecmis = (array) $request->input('gecmis', []);
        $sonuc  = $this->asistanService->isle($doktorId, $mesaj, $gecmis);

        return response()->json([
            'success'       => true,
            'yanit'         => $sonuc['yanit'],
            'onay_gerekli'  => $sonuc['onay_gerekli']  ?? null,
            'secim_gerekli' => $sonuc['secim_gerekli'] ?? null,
        ]);
    }
}
