<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucional;
use App\Models\PeriodoPlazoSala;
use App\Support\Bitacora;
use Illuminate\Http\Request;

/**
 * Cuánto tiene un grupo para presentarse en el mesón antes de que su sala se libere: un
 * tiempo general y fechas con otro tiempo (Administración › Salas). Cada
 * reserva guarda el suyo al hacerse (ver Reserva::booted()): un cambio vale para las
 * siguientes, no para el grupo que ya va en camino con lo que se le dijo.
 */
class PlazoSalaController extends Controller
{
    public function show()
    {
        return response()->json([
            'general' => ConfiguracionInstitucional::actual()->plazo_confirmacion_sala,
            // Las que ya terminaron quedan solo en la bitácora.
            'periodos' => PeriodoPlazoSala::whereDate('hasta', '>=', today())->orderBy('desde')->get(),
            'minimo' => PeriodoPlazoSala::MINUTOS_MINIMOS,
            'maximo' => PeriodoPlazoSala::MINUTOS_MAXIMOS,
        ]);
    }

    public function actualizar(Request $request)
    {
        $data = $request->validate(['minutos' => $this->reglaMinutos()], $this->mensajes());

        $configuracion = ConfiguracionInstitucional::actual();
        $antes = $configuracion->plazo_confirmacion_sala;
        $configuracion->update(['plazo_confirmacion_sala' => $data['minutos']]);

        Bitacora::registrar(
            'configuracion.plazo_salas',
            "Cambió el tiempo para presentarse a una sala de {$antes} a {$data['minutos']} minutos",
            entidad: $configuracion,
            datos: Bitacora::cambios(['plazo_confirmacion_sala' => $antes], ['plazo_confirmacion_sala' => $data['minutos']]),
        );

        return response()->json(['general' => $data['minutos']]);
    }

    public function crearPeriodo(Request $request)
    {
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde', 'after_or_equal:today'],
            'minutos' => $this->reglaMinutos(),
            'motivo' => ['nullable', 'string', 'max:255'],
        ], $this->mensajes());

        $cruce = PeriodoPlazoSala::whereDate('desde', '<=', $data['hasta'])->whereDate('hasta', '>=', $data['desde'])->orderBy('desde')->first();
        if ($cruce) {
            return response()->json(['message' => "Esas fechas se cruzan con las que ya están fijadas {$cruce->descripcion()}: quítalas primero o elige otras."], 422);
        }

        $periodo = PeriodoPlazoSala::create($data);

        Bitacora::registrar(
            'configuracion.crear_periodo_plazo_sala',
            "Fijó {$periodo->minutos} minutos para presentarse a una sala {$periodo->descripcion()}",
            entidad: $periodo,
        );

        return response()->json($periodo, 201);
    }

    public function eliminarPeriodo(PeriodoPlazoSala $periodoPlazoSala)
    {
        $descripcion = $periodoPlazoSala->descripcion();
        $periodoPlazoSala->delete();

        Bitacora::registrar('configuracion.eliminar_periodo_plazo_sala', "Quitó el tiempo especial para presentarse a una sala {$descripcion}");

        return response()->json(null, 204);
    }

    private function reglaMinutos(): array
    {
        return ['required', 'integer', 'between:'.PeriodoPlazoSala::MINUTOS_MINIMOS.','.PeriodoPlazoSala::MINUTOS_MAXIMOS];
    }

    private function mensajes(): array
    {
        $rango = 'El tiempo va de '.PeriodoPlazoSala::MINUTOS_MINIMOS.' a '.PeriodoPlazoSala::MINUTOS_MAXIMOS.' minutos.';

        return [
            'minutos.required' => $rango,
            'minutos.integer' => $rango,
            'minutos.between' => $rango,
            'desde.required' => 'Elige desde qué fecha.',
            'hasta.required' => 'Elige hasta qué fecha.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial ni a hoy.',
        ];
    }
}
