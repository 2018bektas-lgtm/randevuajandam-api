#!/usr/bin/env bash
# randevuajandam-api — production dağıtım / doğrulama betiği
#
# Kullanım (sunucuda API kök dizininde):
#   bash deploy/production-optimize.sh
#
# NEDEN VAR:
# Bu proje model/servis/notification sınıflarının TAMAMINI kardeş dizindeki
# randevuajandam-site projesinden PSR-4 ile alır (bkz. composer.json):
#     "App\\Models\\":   "../randevuajandam-site/app/Models/"
#     "App\\Services\\": "../randevuajandam-site/app/Services/"
#     ...
# Bu yüzden iki şey kritik:
#   1) randevuajandam-site TAM OLARAK bu adla ve bu seviyede yan yana durmalı,
#   2) autoloader her dağıtımda yeniden üretilmeli.
# Geçmişte üretilmiş autoloader eski "../site/" yolunu gösterdiği için
# App\Models\ApiKey ve App\Services\HtmlSanitizer çözülemedi ve API'nin
# tamamı 500 verdi. Aşağıdaki kontroller bunun sessizce tekrar etmesini önler.

set -euo pipefail

echo "==> Proje dizini: $(pwd)"

if [[ ! -f artisan ]]; then
  echo "HATA: artisan bulunamadı. API kök dizinine gidin."
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "HATA: .env yok."
  exit 1
fi

echo "==> Kardeş proje kontrolü (paylaşılan kod)"
PAYLASILAN="../randevuajandam-site/app"
if [[ ! -d "$PAYLASILAN/Models" || ! -d "$PAYLASILAN/Services" ]]; then
  echo "HATA: '$PAYLASILAN' bulunamadı."
  echo "      randevuajandam-api ile randevuajandam-site aynı üst klasörde,"
  echo "      tam olarak bu adlarla yan yana durmalıdır."
  exit 1
fi
echo "  $PAYLASILAN bulundu"

echo "==> Git pull (varsa)"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git pull --ff-only origin main || git pull --ff-only || true
  git log -1 --oneline || true
else
  echo "Uyarı: git repo değil, pull atlandı."
fi

echo "==> Composer (production)"
if ! command -v composer >/dev/null 2>&1; then
  echo "HATA: composer bulunamadı. Autoloader üretilemez, dağıtım durduruldu."
  exit 1
fi
composer install --no-dev --optimize-autoloader --no-interaction
composer dump-autoload --optimize --no-interaction

echo "==> Autoloader doğrulaması (paylaşılan sınıflar çözülüyor mu)"
php -r '
require "vendor/autoload.php";
$gerekli = [
    "App\\Models\\ApiKey",
    "App\\Models\\Doktor",
    "App\\Models\\Randevu",
    "App\\Services\\HtmlSanitizer",
    "App\\Services\\AppointmentBookingService",
];
$eksik = array_values(array_filter($gerekli, fn ($c) => ! class_exists($c)));
if ($eksik) {
    fwrite(STDERR, "HATA: paylasilan siniflar cozulemedi -> ".implode(", ", $eksik)."\n");
    fwrite(STDERR, "      composer.json PSR-4 yollari ile vendor/composer/autoload_psr4.php uyusmuyor.\n");
    exit(1);
}
echo "  paylasilan siniflar yuklu\n";
'

echo "==> .env production sertleştirme"
if grep -q '^APP_ENV=' .env; then
  sed -i.bak 's/^APP_ENV=.*/APP_ENV=production/' .env
else
  echo 'APP_ENV=production' >> .env
fi
if grep -q '^APP_DEBUG=' .env; then
  sed -i.bak 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
else
  echo 'APP_DEBUG=false' >> .env
fi
rm -f .env.bak 2>/dev/null || true

echo "==> Storage dizinleri"
mkdir -p storage/framework/cache/data \
         storage/framework/views \
         storage/logs \
         bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

echo "==> Önbellekler"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan optimize 2>/dev/null || true

echo ""
echo "==> Durum"
php artisan about 2>/dev/null | head -n 30 || true

echo ""
echo "Bitti. Kontrol listesi:"
echo "  - APP_DEBUG=false"
echo "  - DB_* ayarları randevuajandam-site ile aynı veritabanını göstermeli"
echo "  - SHARED_PUBLIC_PATH site public klasörünü göstermeli (medya)"
