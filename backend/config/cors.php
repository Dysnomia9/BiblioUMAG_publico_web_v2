<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // Permite acceder al frontend desde otro dispositivo en la misma red
    // local (p. ej. un celular por WiFi) usando la IP LAN del host, sin
    // tener que hardcodear esa IP (puede cambiar por DHCP).
    'allowed_origins_patterns' => [
        '#^http://(192\.168|10\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1]))\.\d{1,3}\.\d{1,3}:5173$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
