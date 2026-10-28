<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PoliticaVigencia;
use App\Models\Usuario;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cuánto dura la cuenta de cada tipo de usuario al renovarla (Administración › Vigencia
 * de cuentas). Cambiar una política no toca las cuentas que ya existen: solo cambia la
 * fecha que reciben de ahí en adelante al renovarse.
 */
class VigenciaController extends Controller
{
    /** Las tres políticas, con la fecha que recibiría hoy quien se renueve y cuántas cuentas hay de cada tipo. */
    public function index()
    {
        $cuentas = Usuario::vigentes()->selectRaw('tipo, count(*) as n')->groupBy('tipo')->pluck('n', 'tipo');
        $porVencer = Usuario::vigentes()->whereBetween('vigente_hasta', [now()->toDateString(), now()->addDays(60)->toDateString()])
            ->selectRaw('tipo, count(*) as n')->groupBy('tipo')->pluck('n', 'tipo');

        return response()->json(collect(PoliticaVigencia::TIPOS)->map(function (string $tipo) use ($cuentas, $porVencer) {
            $politica = PoliticaVigencia::de($tipo) ?? new PoliticaVigencia(['tipo' => $tipo, 'modo' => 'fecha_fija', 'mes' => 3, 'dia' => 31]);

            return [
                'tipo' => $tipo,
                'modo' => $politica->modo,
                'mes' => $politica->mes,
                'dia' => $politica->dia,
                'anios' => $politica->anios,
                'descripcion' => $politica->descripcion(),
                // La fecha que recibiría hoy una cuenta de este tipo al renovarse (null: no vence).
                'proximo' => Usuario::proximoVencimiento(tipo: $tipo)?->toDateString(),
                'cuentas_vigentes' => (int) ($cuentas[$tipo] ?? 0),
                'por_vencer_60_dias' => (int) ($porVencer[$tipo] ?? 0),
            ];
        })->values());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'politicas' => ['required', 'array', 'size:'.count(PoliticaVigencia::TIPOS)],
            'politicas.*.tipo' => ['required', Rule::in(PoliticaVigencia::TIPOS), 'distinct'],
            'politicas.*.modo' => ['required', Rule::in(PoliticaVigencia::MODOS)],
            'politicas.*.mes' => ['nullable', 'integer', 'between:1,12'],
            'politicas.*.dia' => ['nullable', 'integer', 'between:1,31'],
            'politicas.*.anios' => ['nullable', 'integer', 'between:1,10'],
            'confirmado' => ['accepted'],
        ], ['confirmado.accepted' => 'Confirma el cambio antes de guardar.']);

        $errores = [];
        foreach ($data['politicas'] as $i => $p) {
            if ($p['modo'] === 'fecha_fija' && (empty($p['mes']) || empty($p['dia']) || ! checkdate((int) $p['mes'], (int) $p['dia'], 2028))) {
                $errores["politicas.{$i}.dia"] = ['Elige un mes y un día que existan (ej. 31 de marzo).'];
            }
            if ($p['modo'] === 'duracion' && empty($p['anios'])) {
                $errores["politicas.{$i}.anios"] = ['Indica cuántos años dura la cuenta.'];
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $antes = PoliticaVigencia::todas()->map(fn (PoliticaVigencia $p) => $p->descripcion())->all();

        DB::transaction(function () use ($data) {
            foreach ($data['politicas'] as $p) {
                PoliticaVigencia::updateOrCreate(['tipo' => $p['tipo']], [
                    'modo' => $p['modo'],
                    'mes' => $p['modo'] === 'fecha_fija' ? $p['mes'] : null,
                    'dia' => $p['modo'] === 'fecha_fija' ? $p['dia'] : null,
                    'anios' => $p['modo'] === 'duracion' ? $p['anios'] : null,
                ]);
            }
        });
        PoliticaVigencia::olvidar();

        $despues = PoliticaVigencia::todas()->map(fn (PoliticaVigencia $p) => $p->descripcion())->all();
        $cambios = [];
        foreach (PoliticaVigencia::TIPOS as $tipo) {
            if (($antes[$tipo] ?? null) !== $despues[$tipo]) {
                $cambios[$tipo] = [$antes[$tipo] ?? null, $despues[$tipo]];
            }
        }

        Bitacora::registrar(
            'vigencia.actualizar',
            $cambios
                ? 'Cambió la vigencia de las cuentas: '.collect($cambios)->map(fn ($c, $tipo) => "{$tipo} ({$c[1]})")->implode('; ')
                : 'Guardó la vigencia de las cuentas sin cambios',
            datos: $cambios,
        );

        return $this->index();
    }
}
