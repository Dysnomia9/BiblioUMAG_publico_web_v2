<?php

namespace Tests\Feature;

use App\Models\Entrada;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Services\ReservaSalaService;
use App\Support\HorarioBiblioteca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** mockup:datos no inventa movimiento cuando la biblioteca está cerrada (config/biblioteca.php). */
class DatosDePruebaHorarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_un_lunes_los_datos_quedan_dentro_del_horario(): void
    {
        $this->comprobarHorario('2026-07-13 10:30:00');
    }

    public function test_un_domingo_los_datos_quedan_dentro_del_horario(): void
    {
        $this->comprobarHorario('2026-07-12 11:00:00');
    }

    public function test_un_sabado_despues_del_cierre_los_datos_quedan_dentro_del_horario(): void
    {
        $this->comprobarHorario('2026-07-11 15:30:00');
    }

    public function test_pasada_la_medianoche_los_datos_quedan_dentro_del_horario(): void
    {
        $this->comprobarHorario('2026-07-14 00:04:00');
    }

    private function comprobarHorario(string $ahora): void
    {
        Carbon::setTestNow($ahora);
        $this->artisan('mockup:datos')->assertSuccessful();

        $momentos = Entrada::pluck('fecha_hora_entrada')
            ->merge(Prestamo::pluck('fecha_prestamo'))
            ->merge(Prestamo::whereNotNull('fecha_devolucion_real')->pluck('fecha_devolucion_real'))
            ->map(fn ($m) => Carbon::parse($m));
        $this->assertNotEmpty($momentos);

        $fueraDeHorario = $momentos->reject(function (Carbon $m) {
            $horario = HorarioBiblioteca::semana()[$m->dayOfWeekIso] ?? null;

            return $horario && $m->format('H:i') >= $horario[0] && $m->format('H:i') < $horario[1];
        });
        $this->assertSame([], $fueraDeHorario->map(fn (Carbon $m) => $m->format('D d-m H:i'))->values()->all());

        $this->assertSame([], Reserva::pluck('fecha')->filter(fn ($f) => HorarioBiblioteca::cerrado(Carbon::parse($f)))->values()->all());

        // Las salas, además, dentro del horario de ese día (el sábado, hasta las 14:00).
        $salas = app(ReservaSalaService::class);
        $fueraDeHorario = Reserva::all()->reject(function (Reserva $r) use ($salas) {
            $ventana = $salas->ventanaDelDia(Carbon::parse($r->fecha));

            return $ventana
                && $r->hora_inicio < $r->hora_fin
                && substr($r->hora_inicio, 0, 5) >= $ventana[0]
                && substr($r->hora_fin, 0, 5) <= $ventana[1];
        });
        $this->assertSame([], $fueraDeHorario->map(fn (Reserva $r) => Carbon::parse($r->fecha)->format('D d-m')." {$r->hora_inicio}-{$r->hora_fin}")->values()->all());
    }
}
