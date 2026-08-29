<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Doktor;
use App\Models\DoktorApiToken;
use App\Models\Hasta;
use App\Models\Hizmet;
use App\Models\Paket;
use App\Models\PaketOzelligi;
use App\Models\Randevu;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Hekim paneli takvimi ile randevuajandam-site aynı randevuları göstermeli.
 *
 * Bildirilen sorun: site panelinde görünen randevu, hekim projesinin
 * takviminde görünmüyor. Hekim paneli randevuları bu API ucundan
 * (`GET /takvim/events`) çekiyor; site ise doğrudan veritabanından.
 *
 * Bu test API ucunun aynı randevuları döndürdüğünü ve hangi koşulda
 * BOŞ döndüğünü kayıt altına alır.
 */
class TakvimSenkronizasyonTest extends ApiFeatureTestCase
{
    private Doktor $doktor;

    private string $token;

    private string $apiKey;

    private string $apiSecret;

    private function kur(bool $takvimYetkisi = true): void
    {
        $ozellikler = [PaketOzelligi::firstOrCreate(['kod' => 'web_sitesi'], ['ad' => 'Web Sitesi'])->id];
        if ($takvimYetkisi) {
            $ozellikler[] = PaketOzelligi::firstOrCreate(['kod' => 'online_takvim'], ['ad' => 'Online Takvim'])->id;
        }

        $paket = Paket::create([
            'ad' => 'Test Paket',
            'tur' => 'bireysel',
            'aciklama' => 'Test',
            'aylik_fiyat' => 0,
            'yillik_fiyat' => 0,
            'ozellikler' => [],
            'aktif_mi' => true,
        ]);
        $paket->sistemOzellikleri()->sync($ozellikler);

        $this->doktor = Doktor::create([
            'ad_soyad' => 'Takvim Hekim',
            'e_posta' => 'takvim@test.com',
            'sifre' => Hash::make('sifre123'),
            'tur' => 'bireysel',
            'aktif_mi' => true,
            'paket_id' => $paket->id,
        ]);

        $this->token = DoktorApiToken::issue($this->doktor)['plain'];

        // Uc ayrica doctor.site.key istiyor (hekim projesi de key+secret gonderiyor)
        $this->apiSecret = 'secret_'.uniqid();
        $this->apiKey = 'rk_test_'.uniqid();
        ApiKey::issue([
            'doktor_id' => $this->doktor->id,
            'klinik_id' => null,
            'api_key' => $this->apiKey,
            'durum' => true,
        ], $this->apiSecret);
    }

    private function randevuOlustur(string $tarih, string $saat = '10:00', string $durum = 'onaylandi'): Randevu
    {
        $hizmet = Hizmet::create([
            'doktor_id' => $this->doktor->id,
            'ad' => 'Muayene',
            'slug' => 'muayene-'.uniqid(),
            'aciklama' => 'Test',
            'sure' => 30,
            'fiyat' => 100,
            'aktif_mi' => true,
        ]);

        $hasta = Hasta::create([
            'ad' => 'Test',
            'soyad' => 'Hasta',
            'e_posta' => 'hasta-'.uniqid().'@test.com',
            'telefon' => '05551112233',
            'sifre' => Hash::make('sifre123'),
        ]);

        return Randevu::create([
            'doktor_id' => $this->doktor->id,
            'hizmet_id' => $hizmet->id,
            'hasta_id' => $hasta->id,
            'ad' => $hasta->ad,
            'soyad' => $hasta->soyad,
            'telefon' => $hasta->telefon,
            'e_posta' => $hasta->e_posta,
            'tarih' => $tarih,
            'saat' => $saat,
            'durum' => $durum,
        ]);
    }

    private function takvimiCek(string $start, string $end): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->basliklar())
            ->getJson("/api/v1/doctor/takvim/events?start={$start}&end={$end}");
    }

    /**
     * @return array<string, string>
     */
    private function basliklar(?string $token = null): array
    {
        return [
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ];
    }

    public function test_olusturulan_randevu_takvimde_gorunur(): void
    {
        $this->kur();
        $tarih = now()->addDays(3)->toDateString();
        $this->randevuOlustur($tarih);

        $yanit = $this->takvimiCek(
            now()->toDateString(),
            now()->addDays(10)->toDateString()
        );

        $yanit->assertStatus(200);
        $this->assertCount(1, $yanit->json(), 'Randevu takvim ucunda gorunmuyor.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function durumSaglayici(): array
    {
        return [
            'beklemede' => ['beklemede'],
            'onaylandi' => ['onaylandi'],
            'tamamlandi' => ['tamamlandi'],
            'iptal' => ['iptal'],
        ];
    }

    /**
     * Site tarafı da bu dört durumu gösteriyor; API'nin de göstermesi gerekir.
     *
     */
    #[DataProvider('durumSaglayici')]
    public function test_tum_durumlar_takvimde_gorunur(string $durum): void
    {
        $this->kur();
        $tarih = now()->addDays(2)->toDateString();
        $this->randevuOlustur($tarih, '11:00', $durum);

        $yanit = $this->takvimiCek(
            now()->toDateString(),
            now()->addDays(10)->toDateString()
        );

        $yanit->assertStatus(200);
        $this->assertCount(1, $yanit->json(), "Durum '{$durum}' takvimde gorunmuyor.");
    }

    /**
     * ASIL ŞÜPHE: paket yetkisi yoksa uç 403 döner ve takvim BOŞ görünür.
     * Site tarafı da aynı yetkiyle korunuyor; fakat hekim panelinde bu durum
     * kullanıcıya "randevu yok" gibi görünüyor olabilir.
     */
    public function test_online_takvim_yetkisi_yoksa_403(): void
    {
        $this->kur(takvimYetkisi: false);
        $this->randevuOlustur(now()->addDays(3)->toDateString());

        $yanit = $this->takvimiCek(
            now()->toDateString(),
            now()->addDays(10)->toDateString()
        );

        $yanit->assertStatus(403);
    }

    public function test_tarih_araligi_disindaki_randevu_gelmez(): void
    {
        $this->kur();
        $this->randevuOlustur(now()->addDays(40)->toDateString());

        $yanit = $this->takvimiCek(
            now()->toDateString(),
            now()->addDays(10)->toDateString()
        );

        $yanit->assertStatus(200);
        $this->assertCount(0, $yanit->json());
    }

    public function test_baska_hekimin_randevusu_gelmez(): void
    {
        $this->kur();
        $this->randevuOlustur(now()->addDays(3)->toDateString());

        $digerPaket = Paket::first();
        $diger = Doktor::create([
            'ad_soyad' => 'Diger Hekim',
            'e_posta' => 'diger@test.com',
            'sifre' => Hash::make('sifre123'),
            'tur' => 'bireysel',
            'aktif_mi' => true,
            'paket_id' => $digerPaket->id,
        ]);
        $digerToken = DoktorApiToken::issue($diger)['plain'];

        $yanit = $this->withHeaders($this->basliklar($digerToken))
            ->getJson('/api/v1/doctor/takvim/events?start='.now()->toDateString()
                .'&end='.now()->addDays(10)->toDateString());

        // Bu sitenin anahtariyla BASKA hekimin token'i kullanilamaz:
        // VerifyDoctorSiteApiKey + AuthenticateDoctorApiToken eslesmeyi
        // zorunlu kilar. 403 dogru davranistir (bos liste degil).
        $yanit->assertStatus(403);
    }
}
