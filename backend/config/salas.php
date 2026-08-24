<?php

// Reglas de reserva de salas (horario continuo, ver docs/reserva-salas-horario-continuo.md
// si existe, o CLAUDE.md) — nunca hardcodear estos valores en el service/controller.
return [
    'apertura' => '08:00',
    'cierre' => '21:00',
    'duracion_minima' => 30, // minutos
    'duracion_maxima' => 120, // minutos
    'granularidad' => 30, // los inicios/fines deben caer en :00 o :30, salvo "inmediata"
    'cuota_diaria' => 240, // minutos por participante y día
    'plazo_confirmacion' => 15, // minutos
];
