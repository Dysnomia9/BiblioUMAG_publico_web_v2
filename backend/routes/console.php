<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Las tareas de acá las corre el servicio "scheduler" de docker-compose (schedule:work).

// Cada inicio de sesión deja un token en personal_access_tokens. Cerrar sesión lo
// borra, pero si la persona solo cierra la pestaña, el token vence a las 8 horas
// (config/sanctum.php) y la fila se queda para siempre: una vez al día se borran los
// que vencieron hace más de un día.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// La reserva de libro que no se retiró hasta su fecha límite se cancela apenas empieza
// el día siguiente, y la copia pasa al siguiente de la lista de espera.
Schedule::command('reservas-libro:vencer')->dailyAt('00:05');

// Las reservas de sala a las que nadie llegó pasan a inasistencia apenas alguien mira las
// salas; cada noche se cierran todas las que quedaron, para que los reportes no dependan
// de eso.
Schedule::command('salas:liberar-vencidas')->dailyAt('00:10');
