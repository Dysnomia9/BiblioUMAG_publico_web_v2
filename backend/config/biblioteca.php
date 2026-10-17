<?php

// Horario de atención de la biblioteca, por día de la semana (1 = lunes ... 7 = domingo):
// [apertura, cierre] o null si no abre. Lo usan los reportes, el portal ("Abierta hasta..."),
// las salas (que solo se reservan dentro de este horario y de la ventana de config/salas.php)
// y las fechas de devolución y de retiro, que no pueden caer en un día sin atención.
return [
    'horario' => [
        1 => ['08:45', '20:30'],
        2 => ['08:45', '20:30'],
        3 => ['08:45', '20:30'],
        4 => ['08:45', '20:30'],
        5 => ['08:45', '20:30'],
        6 => ['09:00', '14:00'],
        7 => null,
    ],

    // Capacidad de la biblioteca: el "de 220" de Entradas, el inicio del personal y el portal.
    'aforo' => 220,
];
