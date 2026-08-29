<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

/**
 * Tüm GET rotalarını gezip HTTP 500 veren sayfa var mı diye bakar.
 *
 * Amaç: tek tek sayfa testi yazmak yerine, uygulamanın TAMAMINI tarayıp
 * sunucu hatası üreten bir uç kalmadığını doğrulamak.
 *
 * 500 dışındaki her şey kabul edilir:
 *   200 (açıldı) · 302 (giriş/yönlendirme) · 401/403 (yetki) · 404 (kayıt yok)
 *   419 (CSRF) · 422 (doğrulama)
 *   502/503 — yalnızca API proxy uçlarında: ana sunucu yapılandırılmamış /
 *   erişilemez olduğunda PlatformApiClient::publicProxy() BİLEREK yapılandırılmış
 *   JSON döner (`['success' => false, 'message' => '...']`). Bu bir çöküş değil,
 *   kontrollü "upstream yok" yanıtıdır; üretimde API tanımlı olduğu için 200 gelir.
 *
 * 500 ise kontrolsüz çöküştür ve asla kabul edilmez.
 */
class TumRotalarTest extends ApiFeatureTestCase
{

    /** Parametreli rotalarda denenecek makul değerler. */
    private const ORNEK_PARAMETRE = [
        'slug' => 'ornek-slug',
        'id' => '1',
        'token' => 'ornek-token',
        'hasta' => '1',
        'hizmet' => '1',
        'blog' => '1',
        'egitim' => '1',
        'doktor' => '1',
        'klinik' => '1',
        'randevu' => '1',
        'il_slug' => 'istanbul',
        'ilce_slug' => 'kadikoy',
        'brans_slug' => 'psikoloji',
        'doctor_slug' => 'ornek-hekim',
        'klinik_slug' => 'ornek-klinik',
        'blog_slug' => 'ornek-yazi',
        'hizmet_slug' => 'ornek-hizmet',
        'egitim_slug' => 'ornek-egitim',
        'hastaId' => '1',
        'bloglar' => '1',
        'il_id' => '1',
        'merchantOid' => 'ORNEK123',
        'path' => 'ornek.jpg',
    ];

    /**
     * @return array<int, array{uri: string, ad: string}>
     */
    private function gezilecekRotalar(): array
    {
        $sonuc = [];

        foreach (Route::getRoutes() as $rota) {
            if (! in_array('GET', $rota->methods(), true)) {
                continue;
            }

            $uri = $rota->uri();

            // Framework / altyapı uçları
            if (str_starts_with($uri, '_') || $uri === 'up' || str_contains($uri, 'sanctum')) {
                continue;
            }
            // Oturum kapatma gibi yan etkili uçlar
            if (str_contains($uri, 'cikis') || str_contains($uri, 'logout')) {
                continue;
            }

            // Parametreleri doldur; bilinmeyen zorunlu parametre varsa atla
            $doldurulmus = preg_replace_callback('/\{(\w+)\??\}/', function ($m) {
                return self::ORNEK_PARAMETRE[$m[1]] ?? '__BILINMIYOR__';
            }, $uri);

            if (str_contains($doldurulmus, '__BILINMIYOR__')) {
                continue;
            }

            $sonuc[] = ['uri' => '/'.ltrim($doldurulmus, '/'), 'ad' => $rota->getName() ?? $uri];
        }

        // Aynı URI birden çok kez gezilmesin
        return collect($sonuc)->unique('uri')->values()->all();
    }

    public function test_hicbir_get_rotasi_500_vermiyor(): void
    {
        $rotalar = $this->gezilecekRotalar();
        $this->assertNotEmpty($rotalar, 'Gezilecek GET rotasi bulunamadi.');

        // Hata sayfasinin kendisi yerine gercek istisnayi gormek icin
        $this->withoutExceptionHandling([
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Symfony\Component\HttpKernel\Exception\HttpException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Session\TokenMismatchException::class,
        ]);

        $kirik = [];

        foreach ($rotalar as $rota) {
            try {
                $yanit = $this->get($rota['uri']);
                $kod = $yanit->status();
            } catch (\Throwable $e) {
                $kirik[] = sprintf(
                    '%s (%s) -> %s: %s',
                    $rota['uri'],
                    $rota['ad'],
                    class_basename($e),
                    substr($e->getMessage(), 0, 160)
                );

                continue;
            }

            // API proxy uclarinda 502/503 kontrollu "upstream yok" yanitidir
            $proxyUcu = str_contains($rota['uri'], '/booking/') || str_contains($rota['uri'], '/site-api/');
            if ($proxyUcu && in_array($kod, [502, 503], true)) {
                continue;
            }

            if ($kod >= 500) {
                $kirik[] = sprintf('%s (%s) -> HTTP %d', $rota['uri'], $rota['ad'], $kod);
            }
        }

        fwrite(STDERR, sprintf(
            "\n  [TumRotalar] %d GET rotasi gezildi, %d sunucu hatasi\n",
            count($rotalar),
            count($kirik)
        ));

        $this->assertSame(
            [],
            $kirik,
            sprintf(
                "%d rotadan %d tanesi sunucu hatasi verdi:\n  - %s",
                count($rotalar),
                count($kirik),
                implode("\n  - ", $kirik)
            )
        );
    }
}
