<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ForceHttps middleware'i production'da temel guvenlik basliklarini eklemeli.
 *
 * Regresyon: bu projelerde ForceHttps hic yoktu; yalnizca randevuajandam-site
 * bu basliklari gonderiyordu. Hekim/klinik siteleri ve API clickjacking ile
 * MIME sniffing'e acikti.
 */
class GuvenlikBasliklariTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function beklenenBasliklar(): array
    {
        return [
            'Strict-Transport-Security',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Permissions-Policy',
        ];
    }

    public function test_force_https_middleware_kayitli(): void
    {
        $this->assertTrue(
            class_exists(\App\Http\Middleware\ForceHttps::class),
            'ForceHttps middleware sinifi bulunamadi.'
        );

        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString(
            'ForceHttps::class',
            (string) $bootstrap,
            'ForceHttps bootstrap/app.php icinde middleware olarak eklenmemis.'
        );
    }

    public function test_production_ortaminda_guvenlik_basliklari_eklenir(): void
    {
        $app = $this->app;
        $app->detectEnvironment(fn () => 'production');

        $middleware = new \App\Http\Middleware\ForceHttps;

        $request = \Illuminate\Http\Request::create('https://ornek.test/', 'GET');
        $request->server->set('HTTPS', 'on');

        $response = $middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'));

        foreach ($this->beklenenBasliklar() as $baslik) {
            $this->assertTrue(
                $response->headers->has($baslik),
                "Eksik guvenlik basligi: {$baslik}"
            );
        }

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_yerel_ortamda_hsts_eklenmez(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $middleware = new \App\Http\Middleware\ForceHttps;
        $request = \Illuminate\Http\Request::create('http://localhost/', 'GET');

        $response = $middleware->handle($request, fn () => new \Illuminate\Http\Response('ok'));

        // Yerelde HTTPS zorlanmamali ve HSTS gonderilmemeli
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
