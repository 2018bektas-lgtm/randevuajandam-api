<?php

return [
    'driver' => env('SMS_DRIVER', 'log'),

    'netgsm' => [
        'user' => env('NETGSM_USER'),
        'pass' => env('NETGSM_PASS'),
        'header' => env('NETGSM_HEADER'),
    ],

    'iletimerkezi' => [
        'key' => env('ILETIMERKEZI_KEY'),
        'hash' => env('ILETIMERKEZI_HASH'),
        'sender' => env('ILETIMERKEZI_SENDER'),
    ],
];
