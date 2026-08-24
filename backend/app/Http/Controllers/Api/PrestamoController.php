<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ejemplar;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Notifications\MultaGeneradaNotification;
use App\Services\MultaService;
use App\Services\ReservaLibroService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrestamoController extends Controller
{
    public function __construct(
        private MultaService $multaService,
        private ReservaLibroService $reservaLibroService,
    ) {
    }

    public function index(Request $request)
    {
        $query = Prestamo::with('usuario:id,nombre,apellido,rut');

        if ($usuarioId = $request->query('usuario_id')) {
            $query->where('usuario_id', $usuarioId);
        }

        if ($estado = $request->query('estado')) {
            $query->where('estado', $estado);
        }

        if ($tipoItem = $request->query('tipo_item')) {
            $query->where('tipo_item', $tipoItem);
        }

        return response()->json(
            $query->latest('fecha_prestamo')->get()
        );
    }

    public function store(Request $request)
    {
        // Tanto los libros del catálogo como los equipos tecnológicos (audífonos,
        // notebooks, cargadores) se prestan escaneando un código de barras real —
        // los libros además llevan fecha de préstamo/devolución acordadas en el
        // calendario, los equipos no tienen fecha de vencimiento fija.
        $request->merge(['tipo_item' => $request->input('tipo_item', 'libro')]);

        $data = $request->validate([
            'usuario_id' => ['required', 'exists:usuarios,id'],
            'tipo_item' => ['required', 'in:libro,audifonos,notebook,cargador'],
            'codigo_barras' => ['required', 'string'],
            'fecha_prestamo' => ['required_if:tipo_item,libro', 'nullable', 'date'],
            'fecha_devolucion' => ['required_if:tipo_item,libro', 'nullable', 'date', 'after_or_equal:fecha_prestamo'],
        ]);

        $staff = $request->user();
        $esLibro = $data['tipo_item'] === 'libro';

        // Todo el ciclo lectura→decisión→escritura va en una transacción con
        // lockForUpdate(): sin esto, dos requests concurrentes sobre el mismo
        // codigo_barras pueden pasar ambas el chequeo "disponible" antes de que
        // la primera escriba, resultando en doble préstamo del mismo ejemplar/equipo.
        [$response, $status] = DB::transaction(function () use ($data, $esLibro, $staff) {
            $ejemplar = null;
            $equipo = null;

            if ($esLibro) {
                // Un usuario no puede tener dos libros prestados a la vez — debe devolver
                // el que tiene antes de llevarse otro. A diferencia de las multas (aviso
                // no bloqueante, ver Deuda técnica), esta regla es un bloqueo duro. Solo
                // aplica a libros: los equipos (audífonos/notebook/cargador) no tienen
                // este límite.
                $yaTieneLibro = Prestamo::where('usuario_id', $data['usuario_id'])
                    ->where('tipo_item', 'libro')
                    ->where('estado', '!=', 'devuelto')
                    ->exists();

                if ($yaTieneLibro) {
                    return [['message' => 'Este usuario ya tiene un libro prestado sin devolver — debe devolverlo antes de llevarse otro.'], 409];
                }

                $ejemplar = Ejemplar::where('codigo_barras', $data['codigo_barras'])->lockForUpdate()->first();

                if (! $ejemplar) {
                    return [['message' => 'Código de barras no encontrado en el sistema'], 404];
                }

                if (! $ejemplar->disponible) {
                    return [['message' => 'Este libro ya está reservado/prestado por otra persona'], 409];
                }

                if ($ejemplar->estado_proceso !== 'en_estante') {
                    return [['message' => "Este libro no está disponible para préstamo (estado: {$ejemplar->estado_proceso})"], 409];
                }

                $ejemplar->loadMissing('libro');
            } else {
                $equipo = Equipo::where('codigo_barras', $data['codigo_barras'])->where('tipo', $data['tipo_item'])->lockForUpdate()->first();

                if (! $equipo) {
                    return [['message' => 'Código de barras no encontrado en el sistema'], 404];
                }

                if (! $equipo->activo) {
                    return [['message' => 'Este equipo fue dado de baja'], 409];
                }

                if (! $equipo->disponible) {
                    return [['message' => 'Este equipo ya está prestado a otra persona'], 409];
                }
            }

            $prestamo = Prestamo::create([
                'usuario_id' => $data['usuario_id'],
                'ejemplar_id' => $ejemplar?->id,
                'equipo_id' => $equipo?->id,
                'libro_titulo' => $esLibro ? $ejemplar->tituloConCopia() : $equipo->codigo_inventario,
                'tipo_item' => $data['tipo_item'],
                'codigo_barras' => $esLibro ? $ejemplar->codigo_barras : $equipo->codigo_barras,
                'fecha_prestamo' => $esLibro ? $data['fecha_prestamo'] : now(),
                'fecha_devolucion' => $esLibro ? $data['fecha_devolucion'] : null,
                'estado' => 'activo',
                'prestado_por' => $staff->nombre,
                'prestado_por_staff_id' => $staff->id,
            ]);

            $ejemplar?->update(['disponible' => false]);
            $equipo?->update(['disponible' => false]);

            $prestamo->load('usuario:id,nombre,apellido,rut');

            return [$prestamo, 201];
        });

        return response()->json($response, $status);
    }

    public function devolver(Request $request, Prestamo $prestamo)
    {
        $staff = $request->user();
        $ahora = now();

        DB::transaction(function () use ($prestamo, $staff, $ahora) {
            $multa = $this->multaService->calcular($prestamo, $ahora);

            $prestamo->update([
                'fecha_devolucion_real' => $ahora,
                'estado' => 'devuelto',
                'devuelto_por' => $staff->nombre,
                'devuelto_por_staff_id' => $staff->id,
                'multa_monto' => $multa,
                'multa_estado' => $multa ? 'pendiente' : null,
            ]);

            if ($prestamo->ejemplar_id) {
                // liberarLibro() no siempre deja la copia "disponible": si hay alguien en
                // la cola de espera, la promueve a 'pendiente' en vez de liberarla del todo.
                $ejemplar = Ejemplar::whereKey($prestamo->ejemplar_id)->lockForUpdate()->first();
                if ($ejemplar) {
                    $this->reservaLibroService->liberarLibro($ejemplar);
                }
            }

            if ($prestamo->equipo_id) {
                Equipo::whereKey($prestamo->equipo_id)->lockForUpdate()->first()?->update(['disponible' => true]);
            }
        });

        // Sin SMTP configurado (ver .env.example), esto solo queda escrito en
        // storage/logs/laravel.log — no falla ni intenta salir a la red.
        if ($prestamo->multa_estado === 'pendiente') {
            $prestamo->loadMissing('usuario');
            if ($prestamo->usuario?->email) {
                $prestamo->usuario->notify(new MultaGeneradaNotification($prestamo));
            }
        }

        return response()->json($prestamo);
    }

    public function pagarMulta(Request $request, Prestamo $prestamo)
    {
        if ($prestamo->multa_estado !== 'pendiente') {
            return response()->json(['message' => 'Este préstamo no tiene una multa pendiente'], 409);
        }

        $staff = $request->user();

        $prestamo->update([
            'multa_estado' => 'pagada',
            'multa_pagada_en' => now(),
            'multa_pagada_por' => $staff->nombre,
            'multa_pagada_por_staff_id' => $staff->id,
        ]);

        return response()->json($prestamo);
    }
}
