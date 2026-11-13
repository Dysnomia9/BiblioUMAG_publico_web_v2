<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionInstitucional;
use App\Models\Sala;
use App\Services\ReservaSalaService;
use App\Support\Bitacora;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Las salas de la biblioteca (Administración › Salas): crearlas, ajustarlas, darlas de baja
 * y eliminarlas. Una sala con reservas en su historial no se elimina, se da de baja: deja de
 * ofrecerse en el mesón y en el portal, y sus reservas y reportes se conservan.
 */
class MantenedorSalasController extends Controller
{
    public function __construct(private ReservaSalaService $reservaSalaService)
    {
    }

    public function index()
    {
        // Una reserva a la que nadie llegó no debe contar como activa.
        $this->reservaSalaService->liberarVencidas();

        return response()->json([
            'salas' => $this->conConteos(Sala::query())->orderByDesc('activa')->enOrden()->get(),
            // Cuántas personas lleva una reserva de grupo en el mesón y en el portal.
            'personas' => [
                'meson' => array_combine(['minimo', 'maximo'], Sala::personasDelMeson()),
                'portal' => array_combine(['minimo', 'maximo'], Sala::personasDelPortal()),
            ],
            'minimo_logias' => $this->minimoLogias(),
        ]);
    }

    /**
     * «Mínimo de personas para salas o logias»: encendido, el número vale como mínimo solo en las
     * salas cuyo código empieza con L (las logias), en el mesón y en el portal (sin pasar del máximo de cada uno); apagado,
     * vuelven los de config/salas.php.
     */
    public function minimoLogiasActualizar(Request $request)
    {
        $maximo = Sala::personasDelMeson()[1];
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
            'minimo' => ['required', 'integer', 'min:1', 'max:'.$maximo],
        ], [
            'minimo.min' => "El mínimo va de 1 a {$maximo} personas.",
            'minimo.max' => "El mínimo va de 1 a {$maximo} personas.",
        ]);

        $configuracion = ConfiguracionInstitucional::actual();
        $antes = $configuracion->only(['minimo_personas_logias_activo', 'minimo_personas_logias']);
        $despues = ['minimo_personas_logias_activo' => $data['activo'], 'minimo_personas_logias' => $data['minimo']];
        $configuracion->update($despues);

        Bitacora::registrar(
            'configuracion.minimo_logias',
            $data['activo'] ? "Fijó en {$data['minimo']} el mínimo de personas para las logias" : 'Desactivó el mínimo de personas para las logias',
            entidad: $configuracion,
            datos: Bitacora::cambios($antes, $despues),
        );

        return response()->json($this->minimoLogias());
    }

    /** @return array{activo: bool, minimo: int, maximo: int, portal: array{minimo: int, maximo: int}, logias_chicas: list<string>, sin_cambio: list<string>} */
    private function minimoLogias(): array
    {
        $configuracion = ConfiguracionInstitucional::actual();

        return [
            'activo' => $configuracion->minimo_personas_logias_activo,
            'minimo' => $configuracion->minimo_personas_logias,
            'maximo' => Sala::personasDelMeson()[1],
            // Lo que rige en el portal, que llega solo hasta su máximo
            'portal' => array_combine(['minimo', 'maximo'], Sala::personasDelPortal()),
            // Logias activas con capacidad menor que el mínimo: un aviso, no un bloqueo (el mesón
            // ya anota hasta 7 en una logia de 3)
            'logias_chicas' => $configuracion->minimo_personas_logias_activo
                ? Sala::where('codigo', 'like', 'L%')->where('activa', true)->where('solo_responsable', false)
                    ->where('capacidad', '<', $configuracion->minimo_personas_logias)->enOrden()->pluck('codigo')->all()
                : [],
            // Las salas activas que este mínimo no toca (su código no empieza con L)
            'sin_cambio' => Sala::where('activa', true)->where('codigo', 'not like', 'L%')->enOrden()->get(['nombre', 'codigo'])
                ->map(fn (Sala $s) => "{$s->nombre} ({$s->codigo})")->all(),
        ];
    }

    public function store(Request $request)
    {
        $sala = Sala::create($this->datosValidados($request));

        Bitacora::registrar('salas.crear', "Creó la sala {$sala->nombre} ({$sala->codigo})", entidad: $sala);

        return response()->json($this->conConteos(Sala::whereKey($sala->id))->first(), 201);
    }

    public function update(Request $request, Sala $sala)
    {
        $data = $this->datosValidados($request, $sala);

        $antes = $sala->only(array_keys($data));
        $sala->update($data);

        Bitacora::registrar('salas.editar', "Editó la sala {$sala->nombre} ({$sala->codigo})", entidad: $sala, datos: Bitacora::cambios($antes, $data));

        return response()->json($this->conConteos(Sala::whereKey($sala->id))->first());
    }

    public function cambiarActiva(Request $request, Sala $sala)
    {
        $data = $request->validate(['activa' => ['required', 'boolean']]);

        $this->reservaSalaService->liberarVencidas();

        $rechazo = $this->reservaSalaService->conSalaEIntegrantesBloqueados($sala->id, [], function () use ($data, $sala) {
            $activas = $sala->reservas()->where('estado', 'activa')->count();
            if (! $data['activa'] && $activas) {
                return $activas === 1
                    ? "{$sala->nombre} tiene 1 reserva activa: cancélala o muévela a otra sala antes de darla de baja."
                    : "{$sala->nombre} tiene {$activas} reservas activas: cancélalas o muévelas a otra sala antes de darla de baja.";
            }

            $sala->update(['activa' => $data['activa']]);

            return null;
        });

        if ($rechazo) {
            return response()->json(['message' => $rechazo], 409);
        }

        Bitacora::registrar(
            $data['activa'] ? 'salas.reactivar' : 'salas.dar_de_baja',
            ($data['activa'] ? 'Reactivó' : 'Dio de baja')." la sala {$sala->nombre} ({$sala->codigo})",
            entidad: $sala,
        );

        return response()->json($this->conConteos(Sala::whereKey($sala->id))->first());
    }

    public function destroy(Sala $sala)
    {
        $reservas = $this->reservaSalaService->conSalaEIntegrantesBloqueados($sala->id, [], function () use ($sala) {
            $reservas = $sala->reservas()->count();
            if (! $reservas) {
                $sala->delete();
            }

            return $reservas;
        });

        if ($reservas) {
            return response()->json(['message' => "{$sala->nombre} tiene reservas en su historial: no se puede eliminar, pero sí dar de baja."], 409);
        }

        Bitacora::registrar('salas.eliminar', "Eliminó la sala {$sala->nombre} ({$sala->codigo})", datos: $sala->only(['nombre', 'codigo', 'capacidad', 'tipo']));

        return response()->json(null, 204);
    }

    /** @param  Builder<Sala>  $salas */
    private function conConteos(Builder $salas): Builder
    {
        return $salas->withCount(['reservas', 'reservas as reservas_activas_count' => fn ($q) => $q->where('estado', 'activa')]);
    }

    private function datosValidados(Request $request, ?Sala $sala = null): array
    {
        if (is_string($request->input('codigo'))) {
            $request->merge(['codigo' => Sala::normalizarCodigo($request->input('codigo'))]);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('salas', 'nombre')->ignore($sala?->id)],
            'codigo' => ['required', 'string', 'max:10', 'regex:/^[A-Z][A-Z0-9]*$/', Rule::unique('salas', 'codigo')->ignore($sala?->id)],
            'capacidad' => ['required', 'integer', 'min:1', 'max:500'],
            'tipo' => ['required', Rule::in(Sala::TIPOS)],
            'solo_administracion' => ['required', 'boolean'],
            'reserva_exclusiva' => ['sometimes', 'boolean'],
            'solo_responsable' => ['required', 'boolean'],
        ], [
            'nombre.required' => 'Ponle un nombre a la sala.',
            'nombre.unique' => 'Ya hay una sala con ese nombre.',
            'codigo.required' => 'Ponle un código a la sala.',
            'codigo.max' => 'El código tiene hasta 10 caracteres.',
            'codigo.regex' => 'El código parte con una letra y lleva solo letras y números, sin espacios (ej. L16 o SEM2).',
            'codigo.unique' => 'Ya hay una sala con el código :input.',
            'capacidad.required' => 'Indica la capacidad de la sala.',
            'capacidad.integer' => 'La capacidad es un número de personas.',
            'capacidad.min' => 'La capacidad va de 1 a 500 personas.',
            'capacidad.max' => 'La capacidad va de 1 a 500 personas.',
        ]);

        // Una reserva de grupo del portal lleva un mínimo de personas: si no caben, la sala no se
        // podría reservar ahí.
        $minimo = Sala::personasDelPortal()[0];
        if (! $data['solo_responsable'] && $data['capacidad'] < $minimo) {
            throw ValidationException::withMessages(['capacidad' => ["Una reserva de grupo lleva al menos {$minimo} personas: la sala necesita capacidad para ellas, o reservarse a nombre de una sola."]]);
        }

        // Exclusiva es una forma de «solo administración»: sin ella, no aplica.
        $data['reserva_exclusiva'] = $data['solo_administracion'] && ($data['reserva_exclusiva'] ?? false);

        return $data;
    }
}
