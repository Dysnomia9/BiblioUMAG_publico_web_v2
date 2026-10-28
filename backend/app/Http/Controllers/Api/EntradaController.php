<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entrada;
use App\Models\Usuario;
use App\Services\EntradaService;
use App\Support\Bitacora;
use App\Support\Documento;
use App\Support\HorarioBiblioteca;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            $query = Entrada::with('usuario:id,nombre,apellido,rut,tipo,tipo_documento,pais');

            if ($desde) {
                $query->whereDate('fecha_hora_entrada', '>=', $desde);
            }

            if ($hasta) {
                $query->whereDate('fecha_hora_entrada', '<=', $hasta);
            }

            if ($busqueda !== '') {
                // Nombre completo sin importar tildes, y RUT con o sin puntos ni guion. Un
                // pasaporte o DNI (letras y números) se busca igual, sin espacios ni guiones.
                $digitos = preg_replace('/[^0-9kK]/', '', $busqueda);
                $rutSinFormato = "regexp_replace(%s, '[^0-9kK]', '', 'g') ilike ?";
                $documento = Documento::normalizar($busqueda);
                $esDocumento = strlen($documento) >= 4 && preg_match('/\d/', $documento) && preg_match('/[A-JL-Z]/', $documento);
                $query->where(function ($q) use ($busqueda, $digitos, $rutSinFormato, $documento, $esDocumento) {
                    $q->where('rut_externo', 'ilike', "%{$busqueda}%")
                        ->orWhereCadaPalabra('nombre_externo', $busqueda)
                        ->when(strlen($digitos) >= 3, fn ($q) => $q->orWhereRaw(sprintf($rutSinFormato, 'rut_externo'), ["%{$digitos}%"]))
                        ->when($esDocumento, fn ($q) => $q->orWhereRaw(Documento::sql('rut_externo').' like ?', ["%{$documento}%"]))
                        ->orWhereHas('usuario', function ($u) use ($busqueda, $digitos, $rutSinFormato, $documento, $esDocumento) {
                            $u->where('rut', 'ilike', "%{$busqueda}%")
                                ->orWhereCadaPalabra("(nombre || ' ' || apellido)", $busqueda)
                                ->when(strlen($digitos) >= 3, fn ($u) => $u->orWhereRaw(sprintf($rutSinFormato, 'rut'), ["%{$digitos}%"]))
                                ->when($esDocumento, fn ($u) => $u->orWhereRaw(Documento::sql('rut').' like ?', ["%{$documento}%"]));
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
                ...$this->resumenDeHoy(),
            ]);
        }

        $fecha = $request->query('fecha', now()->toDateString());

        $entradas = Entrada::with('usuario:id,nombre,apellido,rut,tipo,tipo_documento,pais')
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
            ...$this->resumenDeHoy(),
        ]);
    }

    /**
     * El recuadro de hoy de la pantalla Entradas, se esté mirando el día que sea:
     * personas distintas que vinieron (contra el aforo), cuántas entradas hubo y cómo
     * se registraron. Más el horario, para el aviso «Abierta · cierra a las…».
     */
    private function resumenDeHoy(): array
    {
        $hoy = fn () => Entrada::whereDate('fecha_hora_entrada', now()->toDateString());

        return [
            'hoy' => [
                'personas' => (int) $hoy()->selectRaw("count(distinct coalesce(usuario_id::text, 'externo:' || rut_externo)) as total")->value('total'),
                'entradas' => $hoy()->count(),
                'qr' => $hoy()->where('via', 'qr')->count(),
                'portal' => $hoy()->where('via', 'portal')->count(),
                'manuales' => $hoy()->where('via', 'manual')->count(),
                // Marcadas solas al confirmar la llegada del grupo a una sala reservada.
                'sala' => $hoy()->where('via', 'sala')->count(),
            ],
            'aforo' => config('biblioteca.aforo'),
            'horario' => HorarioBiblioteca::semana(),
        ];
    }

    public function store(Request $request, EntradaService $entradas)
    {
        $data = $request->validate([
            'rut' => ['required', 'string'],
            'via' => ['sometimes', 'in:manual,qr'],
        ]);

        // RUT, pasaporte o DNI, escrito con o sin puntos y guion.
        $usuario = Usuario::buscarPorIdentificador($data['rut']);

        if (! $usuario) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        if (! $usuario->activo) {
            return response()->json([
                'message' => $usuario->estaVencida()
                    ? "La cuenta de este usuario venció el {$usuario->vigente_hasta->format('d/m/Y')}: debe renovarse"
                    : 'El usuario se encuentra inactivo',
                'inactividad' => $usuario->inactividad,
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->nombre,
                    'apellido' => $usuario->apellido,
                    'vigente_hasta' => $usuario->vigente_hasta?->toDateString(),
                    'inactividad' => $usuario->inactividad,
                ],
            ], 403);
        }

        // Horizon gestiona toda la asistencia (usuarios, docentes, funcionarios) con el
        // código de barras del puesto de trabajo: se estampa siempre automáticamente, nunca
        // se tipea a mano. La salida se marca en el mismo instante que la entrada — Horizon
        // no registra un evento de salida por separado, así que no tiene sentido modelar un
        // estado "activo" que dependa de fecha_hora_salida. Lo que sí se controla es que la
        // persona quede presente una sola vez por día (ver EntradaService).
        [$entrada, $creada] = $entradas->registrarUsuario($usuario, [
            'registrado_por_staff_id' => $request->user()->id,
            'via' => $data['via'] ?? 'manual',
            'codigo_barras' => config('horizon_barcodes.puesto_generico'),
        ]);

        $entrada->load('usuario:id,nombre,apellido,rut,tipo,tipo_documento,pais');

        if (! $creada) {
            return response()->json([
                'message' => $entradas->mensajeYaPresente($entrada, trim("{$usuario->nombre} {$usuario->apellido}")),
                'entrada' => $entrada,
            ], 409);
        }

        Bitacora::registrar(
            'entradas.registrar',
            ($entrada->via === 'qr' ? 'Registró la entrada con el lector QR' : 'Registró la entrada en el mesón'),
            entidad: $entrada,
            afectado: $usuario,
        );

        return response()->json($entrada, 201);
    }

    /** Visitantes externos: no están en la base institucional, se registran con el documento (y nombre opcional) que declaran. */
    public function storeExterno(Request $request, EntradaService $entradas)
    {
        return $this->registrarExterno($request, $entradas, [], 'de un visitante externo');
    }

    /**
     * Personas de convenio institucional: mismo flujo que un externo (no están en la
     * base de datos institucional), pero se marcan aparte para reportería.
     */
    public function storeConvenio(Request $request, EntradaService $entradas)
    {
        return $this->registrarExterno($request, $entradas, ['es_convenio' => true], 'de una persona de convenio');
    }

    /**
     * Igual que storeExterno(): no está en la base institucional, se registra tal
     * cual declara el documento/nombre. Se distingue de "Externo" solo para
     * reportería/UI (badge "Visita" en vez de "Externo" en el historial).
     */
    public function storeVisita(Request $request, EntradaService $entradas)
    {
        return $this->registrarExterno($request, $entradas, ['es_visita' => true], 'de una visita');
    }

    private function registrarExterno(Request $request, EntradaService $entradas, array $marca, string $deQuien)
    {
        $data = $request->validate([
            'rut' => ['required', 'string', 'max:30'],
            'nombre' => ['nullable', 'string', 'max:255'],
            // Extranjeros: pasaporte o el documento de su país. Sin tipo, se deduce del valor.
            'tipo_documento' => ['nullable', Rule::in(array_keys(Documento::TIPOS))],
            'pais' => ['nullable', 'string', 'max:100'],
        ]);

        $tipo = $data['tipo_documento'] ?? Documento::detectarTipo($data['rut']);
        if ($error = Documento::validar($data['rut'], $tipo)) {
            if (isset($data['tipo_documento'])) {
                return response()->json(['message' => $error, 'errors' => ['rut' => [$error]]], 422);
            }
            // Sin tipo declarado y sin forma reconocible: se guarda como lo declaró.
            $tipo = 'otro';
        }

        $documento = Documento::formatear($data['rut'], $tipo);
        $pais = $tipo === 'rut' ? null : ($data['pais'] ?? null);

        [$entrada, $creada] = $entradas->registrarExterno($documento, [
            ...$marca,
            'tipo_documento_externo' => $tipo,
            'pais_externo' => $pais,
            'nombre_externo' => $data['nombre'] ?? null,
            'registrado_por_staff_id' => $request->user()->id,
            'via' => 'manual',
            'codigo_barras' => config('horizon_barcodes.puesto_generico'),
        ]);

        $quien = $entrada->nombre_externo ?: Documento::describir($entrada->rut_externo, $entrada->tipo_documento_externo, $entrada->pais_externo);

        if (! $creada) {
            return response()->json(['message' => $entradas->mensajeYaPresente($entrada, $quien), 'entrada' => $entrada], 409);
        }

        Bitacora::registrar(
            'entradas.registrar_externo',
            "Registró la entrada {$deQuien}",
            entidad: $entrada,
            afectado: trim(($entrada->nombre_externo ? "{$entrada->nombre_externo} " : '').'('.Documento::describir($documento, $tipo, $pais).')'),
        );

        return response()->json($entrada, 201);
    }
}
