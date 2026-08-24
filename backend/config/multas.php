<?php

return [
    //  ajustar aquí sintocar código si la biblioteca define una tarifa oficial distinta.
    'monto_dia' => 15,

    // Días de gracia antes de empezar a cobrar (0 = se cobra desde el primer día).
    'dias_gracia' => 0,

    // Tope máximo de la multa por préstamo, en CLP. null = sin tope.
    'tope_maximo' => 15000,
];
