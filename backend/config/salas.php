<?php

// Reglas de reserva de salas (horario continuo). Servicios y controladores las leen
// desde acá: no repetir estos valores en el código. Cada día, apertura y cierre se
// recortan además al horario de la biblioteca (config/biblioteca.php): un sábado se
// reserva de 09:00 a 14:00 y un domingo no se reserva.
return [
    'apertura' => '08:00',
    'cierre' => '21:00',
    'duracion_minima' => 30, // minutos
    'duracion_maxima' => 120, // minutos
    'granularidad' => 30, // los inicios/fines deben caer en :00 o :30, salvo "inmediata"
    'cuota_diaria' => 240, // minutos por participante y día
    'plazo_confirmacion' => 15, // minutos
];
