<?php

namespace Tests\Feature;

use App\Http\Middleware\ContentSecurityPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Content-Security-Policy başlığı.
 *
 * Regresyon P2-1: Hiçbir projede CSP yoktu; XSS bulunduğunda ikinci savunma
 * hattı bulunmuyordu. Bu oturumda gerçek bir depolanmış XSS bulunduğu için
 * (bkz. P2-3) bu katman anlamlı.
 *
 * Varsayılan RAPOR MODU'dur: 196 blade satır içi `<script>` içerdiği için
 * zorunlu CSP bugün sayfaları kırardı. Satır içi kod temizlendikçe (P2-13)
 * `CSP_ENFORCE=true` ile zorunlu moda geçilir.
 */
class CspTest extends TestCase
{
    private function basliklar(array $ayar = []): \Symfony\Component\HttpFoundation\HeaderBag
    {
        config(array_merge([
            'csp.enabled' => true,
            'csp.enforce' => false,
            'csp.report_uri' => '',
        ], $ayar));

        $middleware = new ContentSecurityPolicy;

        $yanit = $middleware->handle(
            Request::create('/', 'GET'),
            fn () => new Response('<html></html>', 200, ['Content-Type' => 'text/html'])
        );

        return $yanit->headers;
    }

    public function test_varsayilan_rapor_modu(): void
    {
        $h = $this->basliklar();

        $this->assertTrue(
            $h->has('Content-Security-Policy-Report-Only'),
            'Varsayilan rapor modu basligi yok.'
        );
        $this->assertFalse(
            $h->has('Content-Security-Policy'),
            'Varsayilan ZORUNLU mod olmamali — satir ici script/style henuz temizlenmedi.'
        );
    }

    public function test_zorunlu_mod_acilabilir(): void
    {
        $h = $this->basliklar(['csp.enforce' => true]);

        $this->assertTrue($h->has('Content-Security-Policy'));
        $this->assertFalse($h->has('Content-Security-Policy-Report-Only'));
    }

    public function test_kapatilabilir(): void
    {
        $h = $this->basliklar(['csp.enabled' => false]);

        $this->assertFalse($h->has('Content-Security-Policy'));
        $this->assertFalse($h->has('Content-Security-Policy-Report-Only'));
    }

    public function test_politika_temel_direktifleri_icerir(): void
    {
        $politika = (string) $this->basliklar()->get('Content-Security-Policy-Report-Only');

        foreach ([
            "default-src 'self'",
            'script-src',
            'style-src',
            'img-src',
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ] as $direktif) {
            $this->assertStringContainsString($direktif, $politika, "Eksik direktif: {$direktif}");
        }
    }

    public function test_kullanilan_harici_kaynaklar_izinli(): void
    {
        $politika = (string) $this->basliklar()->get('Content-Security-Policy-Report-Only');

        // Projede fiilen kullanilan kaynaklar (blade taramasindan)
        foreach ([
            'fonts.googleapis.com',
            'fonts.gstatic.com',
            'www.googletagmanager.com',
            'connect.facebook.net',
            'cdn.jsdelivr.net',
            'www.youtube.com',
        ] as $kaynak) {
            $this->assertStringContainsString($kaynak, $politika, "Izinli olmayan kullanilan kaynak: {$kaynak}");
        }
    }

    public function test_rapor_adresi_eklenebilir(): void
    {
        $politika = (string) $this->basliklar(['csp.report_uri' => 'https://ornek.test/csp'])
            ->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('report-uri https://ornek.test/csp', $politika);
    }

    public function test_html_olmayan_yanitlara_uygulanmaz(): void
    {
        config(['csp.enabled' => true, 'csp.enforce' => false]);

        $yanit = (new ContentSecurityPolicy)->handle(
            Request::create('/api/test', 'GET'),
            fn () => new Response('{}', 200, ['Content-Type' => 'application/json'])
        );

        $this->assertFalse($yanit->headers->has('Content-Security-Policy-Report-Only'));
    }
}
