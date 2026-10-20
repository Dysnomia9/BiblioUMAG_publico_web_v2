<?php

return [
    // Días para retirar el libro una vez que queda apartado para la persona
    // (al reservar un libro disponible, o al ser promovida desde la cola de
    // espera cuando alguien más lo devuelve) — ajustar acá, nunca hardcodear
    // en el controller/service.
    'dias_para_retirar' => 3,

    // Reservas de libros activas que una persona puede tener a la vez, sumando
    // las por retirar ('pendiente') y las listas de espera ('en_cola'). Se revisa
    // al reservar, sea desde el portal o desde el mesón; no al pasar de la cola a
    // 'pendiente', para no saltarse a nadie en la fila — y como la cola cuenta,
    // con 1 nadie termina con dos libros apartados por esa vía.
    'maximo_por_persona' => 1,

    // Minutos que espera quien cancela una reserva (o sale de una lista de espera)
    // desde el portal antes de poder pedir otro libro: así nadie pide, cancela y vuelve
    // a pedir en seguidilla. Solo cuenta lo que cancela la propia persona, no el mesón.
    // 0 lo desactiva.
    'espera_tras_cancelar' => 5,
];
