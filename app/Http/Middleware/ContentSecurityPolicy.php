<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy.
 *
 * NEDEN: Hiçbir projede CSP yoktu; XSS bulunduğunda ikinci bir savunma hattı
 * bulunmuyordu. (Bu oturumda `HtmlSanitizer` + `safe_html` zincirinde gerçek
 * bir depolanmış XSS bulundu — CSP olsaydı etkisi sınırlı kalırdı.)
 *
 * NEDEN ÖNCE RAPOR MODU: Projede 196 blade dosyasında satır içi `<script>`,
 * 130'unda satır içi `<style>` var. Zorunlu CSP bugün açılırsa sayfaların
 * yarısı çalışmaz. Bu yüzden varsayılan `Content-Security-Policy-Report-Only`:
 * tarayıcı hiçbir şeyi engellemez, yalnızca ihlalleri raporlar. Satır içi
 * kod temizlendikçe (P2-13) `CSP_ENFORCE=true` ile zorunlu moda geçilir.
 *
 * Ayarlar (.env):
 *   CSP_ENABLED=true|false     — tamamen kapat/aç (varsayılan: true)
 *   CSP_ENFORCE=true|false     — zorunlu mod (varsayılan: false = report-only)
 *   CSP_REPORT_URI=<url>       — ihlal raporlarının gönderileceği adres
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('csp.enabled', true)) {
            return $response;
        }

        // Yalnızca HTML yanıtlarına uygula (JSON/dosya indirmelerine gerek yok)
        $tur = (string) $response->headers->get('Content-Type', '');
        if ($tur !== '' && ! str_contains($tur, 'text/html')) {
            return $response;
        }

        $zorunlu = (bool) config('csp.enforce', false);
        $baslik = $zorunlu
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        // Zaten ayarlıysa (ör. sunucu seviyesinde) dokunma
        if ($response->headers->has($baslik)) {
            return $response;
        }

        $response->headers->set($baslik, $this->politika());

        return $response;
    }

    protected function politika(): string
    {
        $kurallar = [
            "default-src 'self'",

            // Satır içi script'ler temizlenene kadar 'unsafe-inline' gerekli.
            // Google Analytics / Tag Manager / Meta Pixel de buradan yükleniyor.
            "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com "
                ."https://www.google-analytics.com https://connect.facebook.net "
                ."https://www.google.com https://www.gstatic.com https://cdn.jsdelivr.net",

            // Tema CSS'i ve blade içi <style> blokları
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",

            "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net",

            // Hekim görselleri paylaşılan medya sunucusundan; avatar data: URI
            "img-src 'self' data: https:",

            "connect-src 'self' https://www.google-analytics.com https://connect.facebook.net",

            // YouTube tanıtım videosu ve reCAPTCHA çerçevesi
            "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com "
                ."https://www.google.com https://maps.google.com",

            "media-src 'self' https:",

            // Bu siteler hiçbir yerde çerçevelenmemeli (X-Frame-Options'ın modern karşılığı)
            "frame-ancestors 'self'",

            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        $rapor = trim((string) config('csp.report_uri', ''));
        if ($rapor !== '') {
            $kurallar[] = 'report-uri '.$rapor;
        }

        return implode('; ', $kurallar);
    }
}
