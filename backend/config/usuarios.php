<?php

return [
    /*
    | Vigencia de las cuentas de usuario: vencen un día fijo del año, el del cierre del año
    | académico, en vez de un año exacto desde la renovación. Quien se renueva (a mano o
    | porque viene en la carga masiva) queda vigente hasta el mes/día de abajo del año
    | calendario SIGUIENTE: renovar en octubre de 2026 vence el 31 de marzo de 2027, y
    | renovar en febrero de 2027 vence el 31 de marzo de 2028.
    */
    'vigencia' => [
        'mes' => 3,
        'dia' => 31,
    ],
];
