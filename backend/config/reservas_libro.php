<?php

return [
    // Días para retirar el libro una vez que queda apartado para la persona
    // (al reservar un libro disponible, o al ser promovida desde la cola de
    // espera cuando alguien más lo devuelve) — ajustar acá, nunca hardcodear
    // en el controller/service.
    'dias_para_retirar' => 3,
];
