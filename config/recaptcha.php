<?php

/**
 * Google reCAPTCHA v3 (API — public randevu doğrulama)
 * Anahtarlar: istekte hekim site ayarından gelmez; platform env veya
 * randevu oluştururken secret site üzerinden SiteAyari / env.
 */
return [
    'enabled' => filter_var(env('RECAPTCHA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'site_key' => env('RECAPTCHA_SITE_KEY', ''),
    'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
    'score_threshold' => (float) env('RECAPTCHA_SCORE_THRESHOLD', 0.5),
    'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
    'soft_fail_when_unconfigured' => filter_var(env('RECAPTCHA_SOFT_FAIL', true), FILTER_VALIDATE_BOOLEAN),
];
