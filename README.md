# Randevu Ajandam — API

Ana platform (`site/`) ile **aynı MySQL** veritabanını kullanan public / panel API.

## Local çalıştırma

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
# DB_* → site ile aynı MySQL
php artisan serve --host=127.0.0.1 --port=8001
```

| Değişken | Açıklama |
|----------|----------|
| `DB_*` | site ile aynı veritabanı |
| `APP_URL` | `http://127.0.0.1:8001` |
| `SHARED_PUBLIC_PATH` | site `public` klasörünün yolu (medya) |

Detay: kök [`API_MIMARI.md`](../API_MIMARI.md)

## Test

```bash
php artisan test
```

Site migration’ları testte otomatik yüklenir (`tests/Feature/ApiFeatureTestCase.php`).
