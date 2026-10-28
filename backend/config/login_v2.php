<?php

// Solo para probar el login (/login) en desarrollo: además del correo o RUT reales con su
// contraseña, cada cuenta del personal y cada usuario puede entrar con un correo SIMULADO
// con sus iniciales (Ignacio Contreras → igcontre@umag.cl) y una clave de prueba, guardados
// aparte en credenciales_simuladas (php artisan login-v2:correos): no reemplazan el correo,
// el RUT ni la clave reales de nadie. Encendido solo en desarrollo; nunca en producción
// (ver IngresoController).
return [
    'correos_simulados' => (bool) env('LOGIN_V2_CORREOS_SIMULADOS', env('APP_ENV') === 'local'),
    'dominio' => 'umag.cl',
    'clave' => 'demo1234',
];
