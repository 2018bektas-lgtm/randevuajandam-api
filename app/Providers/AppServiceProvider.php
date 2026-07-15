<?php

namespace App\Providers;

use App\Events\RandevuDurumuDegisti;
use App\Events\RandevuOlusturuldu;
use App\Listeners\RandevuBildirimleriniGonder;
use App\Listeners\RandevuFinansKaydet;
use App\Listeners\RandevuLogKaydet;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // NOTE: Do NOT remap usePublicPath to site/public —
        // that breaks `php artisan serve` (loads site's index.php).
        // Shared uploads path is available via shared_public_path() helper binding.
        $shared = env('SHARED_PUBLIC_PATH');
        $this->app->instance(
            'shared_public_path',
            ($shared && is_dir($shared))
                ? rtrim(str_replace('\\', '/', $shared), '/')
                : public_path()
        );
    }

    public function boot(): void
    {
        // Same listeners as main site — appointments created via API still notify
        Event::listen(RandevuOlusturuldu::class, [RandevuLogKaydet::class, 'olusturuldu']);
        Event::listen(RandevuOlusturuldu::class, [RandevuBildirimleriniGonder::class, 'olusturuldu']);
        Event::listen(RandevuDurumuDegisti::class, [RandevuLogKaydet::class, 'durumDegisti']);
        Event::listen(RandevuDurumuDegisti::class, [RandevuBildirimleriniGonder::class, 'durumDegisti']);
        Event::listen(RandevuDurumuDegisti::class, [RandevuFinansKaydet::class, 'durumDegisti']);
    }
}
