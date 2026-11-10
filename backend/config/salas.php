<?php

// Reglas de reserva de salas (horario continuo). Servicios y controladores las leen
// desde acá: no repetir estos valores en el código. Cada día, apertura y cierre se
// recortan además al horario de la biblioteca (config/biblioteca.php): un sábado se
// reserva de 09:00 a 14:00 y un domingo no se reserva. El tiempo para presentarse no está
// acá: se fija en Administración › Salas (PlazoSalaController).
return [
    'apertura' => '08:00',
    'cierre' => '21:00',
    'duracion_minima' => 30, // minutos
    'duracion_maxima' => 120, // minutos
    'granularidad' => 30, // los inicios/fines deben caer en :00 o :30, salvo "inmediata"
    // Integrantes de una reserva, contando a quien queda a cargo. Desde el portal los
    // estudiantes reservan en grupo; en el mesón el personal puede anotar menos o más.
    'personas_minimas' => 3, // portal
    'personas_maximas' => 6, // portal
    'personas_minimas_meson' => 1,
    'personas_maximas_meson' => 7,
    'cuota_diaria' => 240, // minutos por participante y día
    'minimo_para_liberar' => 5, // minutos sin usar desde los que, al devolver la llave antes, se ofrece liberar el resto del bloque

    // Cancelaciones desde el portal de quien hizo la reserva: las primeras del día no
    // tienen consecuencias (para corregir un error); desde la siguiente, espera estos
    // minutos antes de poder reservar otra sala. Si cancela un integrante o el mesón no
    // cuenta, para que nadie deje a otro sin poder reservar poniendo su RUT.
    'cancelaciones_sin_espera' => 1, // por día
    'espera_tras_cancelar' => 5, // minutos (0 lo desactiva)
];
