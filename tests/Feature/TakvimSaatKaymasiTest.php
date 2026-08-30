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
 * Takvim uçları saati KAYDIRMADAN göndermeli.
 *
 * Bildirilen sorun: "05.09.2026'da 18:00'da randevu var, sürükleyerek
 * 15:00'a çekemiyorum."
 *
 * Veritabanına bakıldığında o gün tek randevu vardı ve saati 15:00'dı —
 * 18:00 değil. Aradaki fark tam 3 saat, yani Türkiye'nin UTC farkı.
 *
 * Kök neden: `app.timezone = UTC` ve uç, randevuyu
 *
 *     $startDateTime->toIso8601String()   ->  "2026-09-05T15:00:00+00:00"
 *
 * olarak gönderiyordu. UTC+3'teki tarayıcı bunu 18:00 olarak çiziyordu.
 * Hekim 15:00'a sürüklediğinde tarayıcı yerel 15:00 gönderiyor, sunucu
 * zaten 15:00 olan kaydı yazıyor, tazelemede yine 18:00 çiziliyordu —
 * blok "geri sıçrıyor" gibi görünüyordu.
 *
 * Randevu tarih+saati DUVAR SAATİDİR: hekim 15:00 girdiyse 15:00 demektir,
 * bir UTC iddiası taşımaz. Bu yüzden ofsetsiz gönderilmeli. Aynı ucun
 * öğle arası blokları zaten ofsetsiz gönderiliyordu; randevular onlarla
 * tutarsızdı.
 */
class TakvimSaatKaymasiTest extends ApiFeatureTestCase
{
    private Doktor $doktor;

    private string $token;

    private string $apiKey;

    private string $apiSecret;

    private function kur(): void
    {
        $paket = Paket::create([
            'ad' => 'Saat Testi',
            'tur' => 'bireysel',
            'aciklama' => 'Test',
            'aylik_fiyat' => 0,
            'yillik_fiyat' => 0,
            'ozellikler' => [],
            'aktif_mi' => true,
        ]);
        $paket->sistemOzellikleri()->sync([
            PaketOzelligi::firstOrCreate(['kod' => 'web_sitesi'], ['ad' => 'Web Sitesi'])->id,
            PaketOzelligi::firstOrCreate(['kod' => 'online_takvim'], ['ad' => 'Online Takvim'])->id,
        ]);

        $this->doktor = Doktor::create([
            'ad_soyad' => 'Saat Hekim',
            'e_posta' => 'saat@test.com',
            'sifre' => Hash::make('sifre123'),
            'tur' => 'bireysel',
            'aktif_mi' => true,
            'paket_id' => $paket->id,
        ]);

        $this->token = DoktorApiToken::issue($this->doktor)['plain'];
        $this->apiSecret = 'secret_'.uniqid();
        $this->apiKey = 'rk_test_'.uniqid();
        ApiKey::issue([
            'doktor_id' => $this->doktor->id,
            'klinik_id' => null,
            'api_key' => $this->apiKey,
            'durum' => true,
        ], $this->apiSecret);
    }

    private function randevuOlustur(string $tarih, string $saat): Randevu
    {
        $hizmet = Hizmet::create([
            'doktor_id' => $this->doktor->id,
            'ad' => 'Seans',
            'slug' => 'seans-'.uniqid(),
            'aciklama' => 'Test',
            'sure' => 60,
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
            'durum' => 'onaylandi',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function takvimiCek(string $start, string $end): array
    {
        return $this->withHeaders([
            'X-Api-Key' => $this->apiKey,
            'X-Api-Secret' => $this->apiSecret,
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson("/api/v1/doctor/takvim/events?start={$start}&end={$end}")->json();
    }

    /**
     * Bildirilen senaryonun birebir kendisi.
     */
    public function test_randevu_saati_kaymadan_doner(): void
    {
        $this->kur();
        $this->randevuOlustur('2026-09-05', '15:00');

        $olaylar = $this->takvimiCek('2026-09-01', '2026-09-10');

        $this->assertCount(1, $olaylar);
        $this->assertSame(
            '2026-09-05T15:00:00',
            $olaylar[0]['start'],
            'Randevu saati kaymis ya da ofsetli gonderilmis.'
        );
    }

    /**
     * ASIL KORUMA: start/end bir zaman dilimi ofseti TAŞIMAMALI.
     *
     * "+00:00" / "Z" eklenirse UTC+3 tarayıcı saati 3 saat ileri çizer.
     *
     * @return array<string, array{0: string}>
     */
    public static function alanSaglayici(): array
    {
        return ['start' => ['start'], 'end' => ['end']];
    }

    #[DataProvider('alanSaglayici')]
    public function test_ofset_eklenmez(string $alan): void
    {
        $this->kur();
        $this->randevuOlustur('2026-09-05', '15:00');

        $olaylar = $this->takvimiCek('2026-09-01', '2026-09-10');
        $deger = (string) $olaylar[0][$alan];

        $this->assertMatchesRegularExpression(
            '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$~',
            $deger,
            "'{$alan}' ofsetli gonderiliyor ({$deger}); tarayici saati kaydirir."
        );
    }

    /**
     * Bitiş saati de hizmet süresine göre kaymadan hesaplanmalı.
     */
    public function test_bitis_saati_hizmet_suresine_gore(): void
    {
        $this->kur();
        $this->randevuOlustur('2026-09-05', '15:00');   // sure 60 dk

        $olaylar = $this->takvimiCek('2026-09-01', '2026-09-10');

        $this->assertSame('2026-09-05T16:00:00', $olaylar[0]['end']);
    }

    /**
     * Randevular ile arka plan bloklari AYNI bicimde gonderilmeli; aksi
     * halde ogle arasi dogru yerde, randevu 3 saat ileride cizilir.
     */
    public function test_arka_plan_bloklariyla_ayni_bicim(): void
    {
        $this->kur();
        $this->doktor->calismaSaatleri()->create([
            'gun' => 6,                       // 2026-09-05 Cumartesi
            'aktif_mi' => true,
            'mesai_baslangic' => '11:00',
            'mesai_bitis' => '18:00',
            'ogle_arasi_aktif_mi' => true,
            'ogle_baslangic' => '13:00',
            'ogle_bitis' => '14:00',
        ]);
        $this->randevuOlustur('2026-09-05', '15:00');

        $olaylar = $this->takvimiCek('2026-09-05', '2026-09-06');

        $bicim = '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$~';
        foreach ($olaylar as $olay) {
            $this->assertMatchesRegularExpression(
                $bicim,
                (string) $olay['start'],
                'Olay bicimleri birbirini tutmuyor: '.($olay['id'] ?? '?')
            );
        }
    }
}
