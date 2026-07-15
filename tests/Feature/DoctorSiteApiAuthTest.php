<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Doktor;
use App\Models\Paket;
use App\Models\PaketOzelligi;
use Illuminate\Support\Facades\Hash;

class DoctorSiteApiAuthTest extends ApiFeatureTestCase
{

    private function makeDoctorWithPackage(bool $withWebFeature): array
    {
        $paket = Paket::create([
            'ad' => $withWebFeature ? 'Web Paket' : 'Starter',
            'tur' => 'bireysel',
            'aciklama' => 'Test',
            'aylik_fiyat' => 100,
            'yillik_fiyat' => 1000,
            'ozellikler' => [],
            'aktif_mi' => true,
        ]);

        if ($withWebFeature) {
            $oz = PaketOzelligi::create([
                'kod' => 'web_sitesi',
                'ad' => 'Web Sitesi',
                'aciklama' => 'Test web',
            ]);
            $paket->sistemOzellikleri()->sync([$oz->id]);
        }

        $doktor = Doktor::create([
            'ad_soyad' => 'Test Hekim',
            'e_posta' => 'hekim'.uniqid().'@test.com',
            'sifre' => Hash::make('password'),
            'telefon' => '05551112233',
            'paket_id' => $paket->id,
            'aktif_mi' => true,
            'uyelik_baslangic' => now(),
            'uyelik_bitis' => now()->addYear(),
        ]);

        $plainSecret = 'secret_'.uniqid();
        $issued = ApiKey::issue([
            'doktor_id' => $doktor->id,
            'klinik_id' => null,
            'api_key' => 'rk_test_'.uniqid(),
            'durum' => true,
        ], $plainSecret);
        $apiKey = $issued['model'];
        // Middleware verify uses plain; expose for tests
        $apiKey->setAttribute('_plain_secret', $plainSecret);

        return [$doktor, $apiKey, $paket];
    }

    public function test_missing_api_key_returns_401(): void
    {
        $this->getJson('/api/v1/public/profile')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $this->withHeaders([
            'X-Api-Key' => 'rk_yok',
            'X-Api-Secret' => 'x',
        ])->getJson('/api/v1/public/profile')
            ->assertStatus(401);
    }

    public function test_query_string_api_key_is_rejected(): void
    {
        [, $apiKey] = $this->makeDoctorWithPackage(true);

        // Query ile key artık kabul edilmemeli
        $this->getJson('/api/v1/public/profile?api_key='.$apiKey->api_key.'&secret_key='.$apiKey->getAttribute('_plain_secret'))
            ->assertStatus(401);
    }

    public function test_wrong_secret_returns_401(): void
    {
        [, $apiKey] = $this->makeDoctorWithPackage(true);

        $this->withHeaders([
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => 'yanlis-secret',
        ])->getJson('/api/v1/public/profile')
            ->assertStatus(401);
    }

    public function test_package_without_web_sitesi_returns_403(): void
    {
        [, $apiKey] = $this->makeDoctorWithPackage(false);

        $this->withHeaders([
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => $apiKey->getAttribute('_plain_secret'),
        ])->getJson('/api/v1/public/profile')
            ->assertStatus(403)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_valid_web_package_returns_profile(): void
    {
        [$doktor, $apiKey] = $this->makeDoctorWithPackage(true);

        $this->withHeaders([
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => $apiKey->getAttribute('_plain_secret'),
        ])->getJson('/api/v1/public/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ad_soyad', $doktor->ad_soyad);
    }

    public function test_inactive_doctor_returns_403(): void
    {
        [$doktor, $apiKey] = $this->makeDoctorWithPackage(true);
        $doktor->update(['aktif_mi' => false]);

        $this->withHeaders([
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => $apiKey->getAttribute('_plain_secret'),
        ])->getJson('/api/v1/public/profile')
            ->assertStatus(403);
    }

    public function test_secret_is_stored_hashed(): void
    {
        [, $apiKey] = $this->makeDoctorWithPackage(true);
        $fresh = ApiKey::find($apiKey->id);
        $this->assertTrue($fresh->secretIsHashed());
        $this->assertTrue($fresh->verifySecret($apiKey->getAttribute('_plain_secret')));
    }
}
