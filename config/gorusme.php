<?php

/**
 * Online görüntülü görüşme — platform WebRTC (PeerJS sinyal + STUN/TURN).
 * Hesap / Jitsi / Zoom girişi yok. VPS gerekmez.
 */
return [
    'provider' => env('GORUSME_PROVIDER', 'platform'),

    'jitsi_base_url' => rtrim((string) env('JITSI_BASE_URL', 'https://framatalk.org'), '/'),

    'join_early_minutes' => (int) env('ONLINE_JOIN_EARLY_MINUTES', 15),
    'join_late_minutes' => (int) env('ONLINE_JOIN_LATE_MINUTES', 120),

    'auto_redirect' => false,
    'open_in_new_tab' => false,

    'join_rate_max' => (int) env('GORUSME_JOIN_RATE_MAX', 60),
    'join_rate_decay' => (int) env('GORUSME_JOIN_RATE_DECAY', 60),
    'signal_ttl' => (int) env('GORUSME_SIGNAL_TTL', 7200),

    /**
     * PeerJS cloud (ücretsiz sinyal sunucusu).
     * Kendi sunucunuz yoksa 0.peerjs.com kullanılır.
     */
    'peerjs' => [
        'host' => env('PEERJS_HOST', '0.peerjs.com'),
        'port' => (int) env('PEERJS_PORT', 443),
        'path' => env('PEERJS_PATH', '/'),
        'secure' => filter_var(env('PEERJS_SECURE', true), FILTER_VALIDATE_BOOLEAN),
        'key' => env('PEERJS_KEY', 'peerjs'),
    ],

    /**
     * STUN + ücretsiz TURN (NAT arkasında ses/görüntü için şart).
     * openrelay.metered.ca demo hesap bilgileri public; prod için kendi TURN önerilir.
     */
    'ice_servers' => array_values(array_filter([
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
        ['urls' => 'stun:stun.cloudflare.com:3478'],
        // Public free TURN (Metered openrelay) — ses gelmemesinin sık nedeni TURN eksikliği
        [
            'urls' => [
                'turn:openrelay.metered.ca:80',
                'turn:openrelay.metered.ca:80?transport=tcp',
                'turn:openrelay.metered.ca:443',
                'turns:openrelay.metered.ca:443?transport=tcp',
            ],
            'username' => env('GORUSME_TURN_USERNAME', 'openrelayproject'),
            'credential' => env('GORUSME_TURN_CREDENTIAL', 'openrelayproject'),
        ],
        env('GORUSME_TURN_URL') ? [
            'urls' => env('GORUSME_TURN_URL'),
            'username' => env('GORUSME_TURN_USERNAME', ''),
            'credential' => env('GORUSME_TURN_CREDENTIAL', ''),
        ] : null,
    ])),
];
