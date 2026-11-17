<?php

return [
    // Tarifa general por ejemplar y día cobrable, en CLP.
    'monto_dia' => 100,

    // Días de gracia antes de empezar a cobrar (0 = se cobra desde el primer día).
    'dias_gracia' => 0,

    // Tope máximo de la multa por préstamo, en CLP. null = sin tope.
    'tope_maximo' => 5000,

    // Devolver tarde la llave de una sala o logia. La multa no se cursa sola: al
    // registrar la devolución, el mesón ve cuántos minutos se atrasó y decide si la
    // genera o la deja pasar (SalaController::multarAtraso()).
    'sala' => [
        // false = no se avisa el atraso ni se pueden generar estas multas.
        'activa' => true,
        // Primeros minutos de atraso sin cargo: siempre se descuentan del cálculo.
        'minutos_tolerancia' => 5,
        'monto_por_minuto' => 10,
        'tope_maximo' => 2000,
        'monto_provisional' => false,
    ],
];
