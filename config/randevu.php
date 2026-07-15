<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Misafir randevu için SMS OTP zorunlu mu?
    |--------------------------------------------------------------------------
    | Production varsayılan: true. Local: RANDEVU_OTP_REQUIRED=false ile kapatın.
    */
    'otp_required' => filter_var(
        env(
            'RANDEVU_OTP_REQUIRED',
            env('APP_ENV', 'production') === 'local' || env('APP_ENV') === 'testing' ? 'false' : 'true'
        ),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Honeypot captcha alanı adı (botlar doldurursa reddedilir)
    |--------------------------------------------------------------------------
    */
    'honeypot_field' => 'website_url',

    /*
    |--------------------------------------------------------------------------
    | Yönetim token (capability URL) rate limit
    |--------------------------------------------------------------------------
    */
    'manage_token_max_attempts' => (int) env('RANDEVU_MANAGE_TOKEN_MAX', 20),
    'manage_token_decay_seconds' => (int) env('RANDEVU_MANAGE_TOKEN_DECAY', 60),
];
