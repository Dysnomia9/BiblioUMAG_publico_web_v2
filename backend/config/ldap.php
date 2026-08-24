<?php

/**
 * Config de directorytree/ldaprecord-laravel para el login institucional (LDAP/AD
 * de la UMAG) desde LoginV2View.vue — ver LdapAuthController. Sin LDAP_HOST
 * configurado, el controller rechaza limpio antes de intentar conectar (ver
 * .env.example) — por eso 'hosts' cae a un array vacío por default, a diferencia
 * del stub del paquete que trae '127.0.0.1' hardcodeado (con eso, el chequeo de
 * "no configurado" nunca detectaría el caso sin configurar).
 *
 * attributes.user/attributes.email son propios (no del paquete) y a propósito NO
 * viven dentro de connections.default — LdapRecord valida esa sub-key contra un
 * esquema fijo y rechaza cualquier clave que no reconozca ("Option X does not
 * exist"), así que cualquier dato propio de LdapAuthController tiene que ir afuera.
 * No sabemos todavía el esquema real del directorio de la UMAG (podría ser Active
 * Directory con sAMAccountName, OpenLDAP con uid, etc.) — cuando se tengan los
 * datos reales, alcanza con ajustar esas dos variables de entorno, sin tocar código.
 */
return [
    'default' => env('LDAP_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'hosts' => array_filter(explode(',', (string) env('LDAP_HOST', ''))),
            'username' => env('LDAP_BIND_USERNAME'),
            'password' => env('LDAP_BIND_PASSWORD'),
            'port' => (int) env('LDAP_PORT', 389),
            'base_dn' => env('LDAP_BASE_DN', ''),
            'timeout' => (int) env('LDAP_TIMEOUT', 5),
            'use_tls' => (bool) env('LDAP_TLS', false),
            'use_starttls' => (bool) env('LDAP_STARTTLS', false),
            'use_sasl' => (bool) env('LDAP_SASL', false),
            'sasl_options' => [],
        ],
    ],

    'attributes' => [
        'user' => env('LDAP_USER_ATTRIBUTE', 'mail'),
        'email' => env('LDAP_EMAIL_ATTRIBUTE', 'mail'),
    ],

    'logging' => [
        'enabled' => (bool) env('LDAP_LOGGING', false),
        'channel' => env('LOG_CHANNEL', 'stack'),
        'level' => env('LOG_LEVEL', 'info'),
    ],

    'cache' => [
        'enabled' => (bool) env('LDAP_CACHE', false),
        'driver' => env('CACHE_DRIVER', 'file'),
    ],
];
