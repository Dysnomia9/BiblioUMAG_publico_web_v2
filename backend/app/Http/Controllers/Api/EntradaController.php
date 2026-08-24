<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entrada;
use App\Models\Usuario;
use Illuminate\Http\Request;

class EntradaController extends Controller
{
    /**
     * Dos modos, mutuamente excluyentes:
     * - Por defecto (sin desde/hasta/q): un día exacto, igual que siempre — incluye
     *   personasEnSala (solo tiene sentido para "hoy", no para un rango).
     * - Si viene desde/hasta y/o q: modo búsqueda — rango de fechas abierto (cualquiera
     *   de los dos extremos es opcional) y/o texto libre por RUT/nombre, sobre usuarios
     *   registrados y externos/convenio/visita. Pensado para auditoría puntual ("¿cuándo
     *   vino esta persona?"), no para el uso diario de mesón.
     */
    public function index(Request $request)
    {
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $busqueda = trim((string) $request->query('q', ''));

        if ($desde || $hasta || $busqueda !== '') {
            $query = Entrada::with('usuario:id,nombre,apellido,rut,tipo');

            if ($desde) {
                $query->whereDate('fecha_hora_entrada', '>=', $desde);
            }

            if ($hasta) {
                $query->whereDate('fecha_hora_entrada', '<=', $hasta);
            }

            if ($busqueda !== '') {
                $query->where(function ($q) use ($busqueda) {
                    $q->where('rut_externo', 'ilike', "%{$busqueda}%")
                        ->orWhere('nombre_externo', 'ilike', "%{$busqueda}%")
                        ->orWhereHas('usuario', function ($u) use ($busqueda) {
                            $u->where('rut', 'ilike', "%{$busqueda}%")
                                ->orWhere('nombre', 'ilike', "%{$busqueda}%")
                                ->orWhere('apellido', 'ilike', "%{$busqueda}%");
                        });
                });
            }

            // Tope defensivo: un rango abierto sin más filtro podría devolver años de
            // historial de una sola vez — 500 alcanza de sobra para revisar resultados
            // reales sin tener que paginar.
            $entradas = $query->latest('fecha_hora_entrada')->limit(500)->get();

            return response()->json([
                'modo' => 'busqueda',
                'entradas' => $entradas,
            ]);
        }

        $fecha = $request->query('fecha', now()->toDateString());

        $entradas = Entrada::with('usuario:id,nombre,apellido,rut,tipo')
            ->whereDate('fecha_hora_entrada', $fecha)
            ->latest('fecha_hora_entrada')
            ->get();

        return response()->json([
            'modo' => 'dia',
            'fecha' => $fecha,
            'entradas' => $entradas,
            // Horizon (el sistema legado) no distingue "quién sigue adentro": cada
            // ingreso se cierra en el mismo instante en que se registra (ver store()).
            // "Personas en sala" es, en la práctica, el total de ingresos del día — no
            // un conteo en tiempo real de quién no ha salido.
            'personasEnSala' => $entradas->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'via' => ['sometimes', 'in:manual,qr'],
        ]);

        $usuario = Usuario::where('rut', $data['rut'])->first();

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        if (! $usuario->activo) {
            return response()->json(['message' => 'El usuario se encuentra inactivo'], 403);
        }

        // Horizon gestiona toda la asistencia (usuarios, docentes, funcionarios) con el
        // código de barras del puesto de trabajo: se estampa siempre automáticamente, nunca
        // se tipea a mano. La salida se marca en el mismo instante que la entrada — Horizon
        // no registra un evento de salida por separado, así que no tiene sentido modelar un
        // estado "activo" que dependa de fecha_hora_salida (antes quedaba una entrada
        // "abierta" indefinidamente si nadie la cerraba a mano, incluso días después). Se usa
        // el mismo timestamp para ambos campos (no dos now() distintos) para que salida no
        // quede unos milisegundos después de entrada.
        $ahora = now();
        $entrada = Entrada::create([
            'usuario_id' => $usuario->id,
            'registrado_por_staff_id' => $request->user()->id,
            'via' => $data['via'] ?? 'manual',
            'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            'fecha_hora_entrada' => $ahora,
            'fecha_hora_salida' => $ahora,
        ]);

        $entrada->load('usuario:id,nombre,apellido,rut,tipo');

        return response()->json($entrada, 201);
    }

    public function storeExterno(Request $request)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'nombre' => ['nullable', 'string', 'max:255'],
        ]);

        // Visitantes externos no están en la base de datos institucional: se
        // registran directamente con el RUT (y nombre opcional) que declaran,
        // sin validar contra la tabla de usuarios.
        $ahora = now();
        $entrada = Entrada::create([
            'rut_externo' => $data['rut'],
            'nombre_externo' => $data['nombre'] ?? null,
            'registrado_por_staff_id' => $request->user()->id,
            'via' => 'manual',
            'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            'fecha_hora_entrada' => $ahora,
            'fecha_hora_salida' => $ahora,
        ]);

        return response()->json($entrada, 201);
    }

    public function storeConvenio(Request $request)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'nombre' => ['nullable', 'string', 'max:255'],
        ]);

        // Personas de convenio institucional: mismo flujo que un externo (no están en la
        // base de datos institucional), pero se marcan aparte para reportería.
        $ahora = now();
        $entrada = Entrada::create([
            'rut_externo' => $data['rut'],
            'nombre_externo' => $data['nombre'] ?? null,
            'es_convenio' => true,
            'registrado_por_staff_id' => $request->user()->id,
            'via' => 'manual',
            'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            'fecha_hora_entrada' => $ahora,
            'fecha_hora_salida' => $ahora,
        ]);

        return response()->json($entrada, 201);
    }

    public function storeVisita(Request $request)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'nombre' => ['nullable', 'string', 'max:255'],
        ]);

        // Igual que storeExterno(): no está en la base institucional, se registra tal
        // cual declara el RUT/nombre. Se distingue de "Externo" solo para reportería/UI
        // (badge "Visita" en vez de "Externo" en el historial).
        $ahora = now();
        $entrada = Entrada::create([
            'rut_externo' => $data['rut'],
            'nombre_externo' => $data['nombre'] ?? null,
            'es_visita' => true,
            'registrado_por_staff_id' => $request->user()->id,
            'via' => 'manual',
            'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            'fecha_hora_entrada' => $ahora,
            'fecha_hora_salida' => $ahora,
        ]);

        return response()->json($entrada, 201);
    }
}
