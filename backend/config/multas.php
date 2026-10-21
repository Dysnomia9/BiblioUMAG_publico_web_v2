<?php

return [
    //  ajustar aquí sintocar código si la biblioteca define una tarifa oficial distinta.
    'monto_dia' => 15,

    // Días de gracia antes de empezar a cobrar (0 = se cobra desde el primer día).
    'dias_gracia' => 0,

    // Tope máximo de la multa por préstamo, en CLP. null = sin tope.
    'tope_maximo' => 15000,

    // Devolver tarde la llave de una sala o logia. La multa no se cursa sola: al
    // registrar la devolución, el mesón ve cuántos minutos se atrasó y decide si la
    // genera o la deja pasar (SalaController::multarAtraso()).
    'sala' => [
        // false = no se avisa el atraso ni se pueden generar estas multas.
        'activa' => true,
        // Minutos después de la hora de término que no se avisan.
        'minutos_tolerancia' => 5,
        // MONTOS PROVISIONALES: la biblioteca todavía no define la tarifa. Al fijarla,
        // cambiar estos valores y poner 'monto_provisional' en false (el aviso del
        // mesón deja de decir que el monto es provisional).
        'monto_por_minuto' => 50,
        'tope_maximo' => 5000,
        'monto_provisional' => true,
    ],
];
