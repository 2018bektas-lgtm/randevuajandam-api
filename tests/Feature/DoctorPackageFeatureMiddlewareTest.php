<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Doktor;
use App\Models\DoktorApiToken;
use App\Models\Paket;
use App\Models\PaketOzelligi;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DoctorPackageFeatureMiddlewareTest extends ApiFeatureTestCase
{

    /**
     * Web paketi var ama blog özelliği yok → finans/blog 403 olmalı.
     * Web_sitesi zorunlu (site key), ek özellikler doctor.paket ile.
     */
    private function doctorWithFeatures(array $featureCodes): array
    {
        $paket = Paket::create([
            'ad' => 'Test Paket',
            'tur' => 'bireysel',
            'aciklama' => 'x',
            'aylik_fiyat' => 10,
            'yillik_fiyat' => 100,
            'ozellikler' => [],
            'aktif_mi' => true,
        ]);

        $ids = [];
        foreach (array_unique(array_merge(['web_sitesi'], $featureCodes)) as $kod) {
            $oz = PaketOzelligi::firstOrCreate(
                ['kod' => $kod],
                ['ad' => $kod, 'aciklama' => $kod]
            );
            $ids[] = $oz->id;
        }
        $paket->sistemOzellikleri()->sync($ids);

        $doktor = Doktor::create([
            'ad_soyad' => 'Feature Hekim',
            'e_posta' => 'f'.uniqid().'@test.com',
            'sifre' => Hash::make('password123'),
            'paket_id' => $paket->id,
            'aktif_mi' => true,
            'uyelik_baslangic' => now(),
            'uyelik_bitis' => now()->addYear(),
        ]);

        $plainSecret = 'sec_'.uniqid();
        $issued = ApiKey::issue([
            'doktor_id' => $doktor->id,
            'api_key' => 'rk_'.uniqid(),
            'durum' => true,
        ], $plainSecret);
        $apiKey = $issued['model'];
        $apiKey->setAttribute('_plain_secret', $plainSecret);

        // AuthenticateDoctorApiToken looks up the raw bearer value in DB (not hash)
        $plain = Str::random(48);
        DoktorApiToken::create([
            'doktor_id' => $doktor->id,
            'name' => 'test',
            'token' => $plain,
            'expires_at' => now()->addDay(),
        ]);

        return [$doktor, $apiKey, $plain];
    }

    private function authHeaders(ApiKey $apiKey, string $bearer): array
    {
        return [
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => $apiKey->getAttribute('_plain_secret'),
            'Authorization' => 'Bearer '.$bearer,
        ];
    }

    public function test_finans_blocked_without_feature(): void
    {
        [, $apiKey, $bearer] = $this->doctorWithFeatures([]); // only web_sitesi

        $this->withHeaders($this->authHeaders($apiKey, $bearer))
            ->getJson('/api/v1/doctor/finans/ozet')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'finans');
    }

    public function test_finans_allowed_with_feature(): void
    {
        [, $apiKey, $bearer] = $this->doctorWithFeatures(['finans']);

        $this->withHeaders($this->authHeaders($apiKey, $bearer))
            ->getJson('/api/v1/doctor/finans/ozet')
            ->assertOk();
    }

    public function test_blog_blocked_without_feature(): void
    {
        [, $apiKey, $bearer] = $this->doctorWithFeatures([]);

        $this->withHeaders($this->authHeaders($apiKey, $bearer))
            ->getJson('/api/v1/doctor/bloglar')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'blog');
    }

    public function test_blog_allowed_with_feature(): void
    {
        [, $apiKey, $bearer] = $this->doctorWithFeatures(['blog']);

        $this->withHeaders($this->authHeaders($apiKey, $bearer))
            ->getJson('/api/v1/doctor/bloglar')
            ->assertOk();
    }
}
