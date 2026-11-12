<?php

// Horario de atención de la biblioteca, por día de la semana (1 = lunes ... 7 = domingo):
// [apertura, cierre] o null si no abre. Lo usan los reportes, el portal ("Abierta hasta..."),
// las salas (que solo se reservan dentro de este horario y de la ventana de config/salas.php)
// y las fechas de devolución y de retiro, que no pueden caer en un día sin atención.
return [
    'horario' => [
        1 => ['08:00', '21:00'],
        2 => ['08:00', '21:00'],
        3 => ['08:00', '21:00'],
        4 => ['08:00', '21:00'],
        5 => ['08:00', '21:00'],
        6 => ['09:00', '14:00'],
        7 => null,
    ],
];
