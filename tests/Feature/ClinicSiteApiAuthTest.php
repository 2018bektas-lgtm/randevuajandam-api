<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Doktor;
use App\Models\Klinik;
use App\Models\Paket;
use App\Models\PaketOzelligi;
use Illuminate\Support\Facades\Hash;

class ClinicSiteApiAuthTest extends ApiFeatureTestCase
{

    private function makeClinicWithPackage(bool $withWebFeature): array
    {
        $paket = Paket::create([
            'ad' => $withWebFeature ? 'Klinik Kurumsal' : 'Klinik Başlangıç',
            'tur' => 'klinik',
            'aciklama' => 'Test',
            'aylik_fiyat' => 1000,
            'yillik_fiyat' => 10000,
            'ozellikler' => [],
            'aktif_mi' => true,
            'max_doktor_sayisi' => 10,
            'merkezi_finans_mi' => $withWebFeature,
            'toplu_randevu_mi' => $withWebFeature,
            'raporlama_mi' => $withWebFeature,
            'hasta_havuzu_mi' => true,
        ]);

        if ($withWebFeature) {
            $oz = PaketOzelligi::create([
                'kod' => 'klinik_web_sitesi',
                'ad' => 'Klinik Web',
                'aciklama' => 'Test',
            ]);
            $paket->sistemOzellikleri()->sync([$oz->id]);
        }

        $sahip = Doktor::create([
            'ad_soyad' => 'Klinik Sahip',
            'e_posta' => 'sahip'.uniqid().'@test.com',
            'sifre' => Hash::make('password'),
            'telefon' => '05550001122',
            'aktif_mi' => true,
        ]);

        $klinik = Klinik::create([
            'ad' => 'Test Klinik '.uniqid(),
            'sahip_doktor_id' => $sahip->id,
            'paket_id' => $paket->id,
            'telefon' => '02121234567',
            'e_posta' => 'klinik'.uniqid().'@test.com',
            'aktif_mi' => true,
            'max_doktor_sayisi' => 10,
            'uyelik_baslangic' => now(),
            'uyelik_bitis' => now()->addYear(),
        ]);

        $sahip->update([
            'klinik_id' => $klinik->id,
            'klinik_rolu' => 'sahip',
            'klinik_aktif_mi' => true,
        ]);

        $plain = 'secret_klinik_'.uniqid();
        $issued = ApiKey::issue([
            'doktor_id' => null,
            'klinik_id' => $klinik->id,
            'api_key' => 'rk_klinik_'.uniqid(),
            'durum' => true,
        ], $plain);
        $apiKey = $issued['model'];
        $apiKey->setAttribute('_plain_secret', $plain);

        return [$klinik, $apiKey, $paket];
    }

    public function test_missing_clinic_key_returns_401(): void
    {
        $this->getJson('/api/v1/public/clinic/profile')
            ->assertStatus(401);
    }

    public function test_clinic_package_without_web_returns_403(): void
    {
        [, $apiKey] = $this->makeClinicWithPackage(false);

        $this->withHeaders([
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => $apiKey->getAttribute('_plain_secret'),
        ])->getJson('/api/v1/public/clinic/profile')
            ->assertStatus(403)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_clinic_kurumsal_profile_ok(): void
    {
        [$klinik, $apiKey] = $this->makeClinicWithPackage(true);

        $this->withHeaders([
            'X-Api-Key' => $apiKey->api_key,
            'X-Api-Secret' => $apiKey->getAttribute('_plain_secret'),
        ])->getJson('/api/v1/public/clinic/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ad', $klinik->ad);
    }

    public function test_doctor_key_cannot_use_clinic_endpoint(): void
    {
        $paket = Paket::create([
            'ad' => 'Web',
            'tur' => 'bireysel',
            'aciklama' => 'x',
            'aylik_fiyat' => 1,
            'yillik_fiyat' => 1,
            'ozellikler' => [],
            'aktif_mi' => true,
        ]);
        $oz = PaketOzelligi::create(['kod' => 'web_sitesi', 'ad' => 'W', 'aciklama' => 'x']);
        $paket->sistemOzellikleri()->sync([$oz->id]);

        $doktor = Doktor::create([
            'ad_soyad' => 'Hekim',
            'e_posta' => 'h'.uniqid().'@t.com',
            'sifre' => Hash::make('x'),
            'paket_id' => $paket->id,
            'aktif_mi' => true,
        ]);

        $plain = 'sec';
        $issued = ApiKey::issue([
            'doktor_id' => $doktor->id,
            'api_key' => 'rk_doc_'.uniqid(),
            'durum' => true,
        ], $plain);

        $this->withHeaders([
            'X-Api-Key' => $issued['model']->api_key,
            'X-Api-Secret' => $plain,
        ])->getJson('/api/v1/public/clinic/profile')
            ->assertStatus(401);
    }
}
