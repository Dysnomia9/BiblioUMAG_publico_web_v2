<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Multa;
use App\Models\Prestamo;
use App\Models\Usuario;
use App\Services\MultaService;
use App\Support\Bitacora;
use App\Support\Busqueda;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MultaController extends Controller
{
    private const RELACIONES = [
        'usuario:id,nombre,apellido,rut,email',
        'prestamo:id,libro_titulo,tipo_item,fecha_prestamo,fecha_devolucion,fecha_devolucion_real',
        'registradaPor:id,nombre',
        'pagadaPor:id,nombre',
        'anuladaPor:id,nombre',
    ];

    public function __construct(private MultaService $multaService)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'estado' => ['nullable', Rule::in(['pendiente', 'pagada', 'anulada'])],
            'tipo' => ['nullable', Rule::in(array_keys(Multa::TIPOS))],
            'usuario_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = Multa::with(self::RELACIONES);

        if ($usuarioId = $request->query('usuario_id')) {
            $query->where('usuario_id', $usuarioId);
        }
        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }
        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }
        if ($desde = $request->query('desde')) {
            $query->whereDate('created_at', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $query->whereDate('created_at', '<=', $hasta);
        }
        if ($busqueda = trim((string) $request->query('q'))) {
            $query->whereHas('usuario', fn ($u) => $u->where(function ($w) use ($busqueda) {
                $w->where('rut', 'ilike', "%{$busqueda}%")
                    ->orWhereRaw(Busqueda::sinTildes("(nombre || ' ' || apellido)").' ilike ?', [Busqueda::patron($busqueda)]);
            }));
        }

        return response()->json($query->latest()->latest('id')->limit(1000)->get());
    }

    public function resumen()
    {
        $usuarios = $this->multaService->pendientesPorUsuario();

        return response()->json([
            'total_usuarios' => $usuarios->count(),
            'total_multas' => $usuarios->sum('cantidad_multas'),
            'monto_total' => $usuarios->sum('monto_total'),
            'usuarios' => $usuarios->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id' => ['required', 'exists:usuarios,id'],
            'tipo' => ['required', Rule::in(Multa::TIPOS_MANUALES)],
            'monto' => ['required', 'integer', 'min:1', 'max:10000000'],
            'detalle' => ['required', 'string', 'min:5', 'max:500'],
            'prestamo_id' => ['nullable', 'exists:prestamos,id'],
        ], [
            'tipo.in' => 'Las multas por atraso se generan solas al devolver; aquí solo se cursan por deterioro, pérdida u otro motivo.',
            'detalle.min' => 'Describe el motivo de la multa (al menos 5 caracteres).',
        ]);

        $usuario = Usuario::findOrFail($data['usuario_id']);

        if (! empty($data['prestamo_id']) && Prestamo::whereKey($data['prestamo_id'])->value('usuario_id') !== $usuario->id) {
            return response()->json(['message' => 'El préstamo indicado no pertenece a este usuario'], 422);
        }

        $multa = $this->multaService->crearManual($usuario, $data, $request->user());

        Bitacora::registrar(
            'multas.cursar',
            'Cursó una multa por '.(Multa::TIPOS[$multa->tipo] ?? $multa->tipo).' de '.Bitacora::pesos($multa->monto).": {$multa->detalle}",
            entidad: $multa,
            datos: ['tipo' => $multa->tipo, 'monto' => $multa->monto, 'prestamo_id' => $multa->prestamo_id],
            afectado: $usuario,
        );

        return response()->json($multa->load(self::RELACIONES), 201);
    }

    public function pagar(Request $request, Multa $multa)
    {
        if ($multa->estado !== 'pendiente') {
            return response()->json(['message' => "Esta multa no está pendiente (estado: {$multa->estado})"], 409);
        }

        $this->multaService->registrarPago($multa, $request->user());

        Bitacora::registrar(
            'multas.pagar',
            'Registró el pago de una multa de '.Bitacora::pesos($multa->monto)." ({$multa->detalle})",
            entidad: $multa,
            datos: ['estado' => ['pendiente', 'pagada']],
            afectado: $multa->usuario,
        );

        return response()->json($multa->load(self::RELACIONES));
    }

    public function anular(Request $request, Multa $multa)
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Indica por qué se anula la multa — queda registrado.',
            'motivo.min' => 'Indica por qué se anula la multa (al menos 5 caracteres).',
        ]);

        if ($multa->estado !== 'pendiente') {
            return response()->json(['message' => "Solo se puede anular una multa pendiente (estado actual: {$multa->estado})"], 409);
        }

        $this->multaService->anular($multa, $data['motivo'], $request->user());

        Bitacora::registrar(
            'multas.anular',
            'Anuló una multa de '.Bitacora::pesos($multa->monto).". Motivo: {$data['motivo']}",
            entidad: $multa,
            datos: ['estado' => ['pendiente', 'anulada'], 'motivo' => $data['motivo']],
            afectado: $multa->usuario,
        );

        return response()->json($multa->load(self::RELACIONES));
    }
}
