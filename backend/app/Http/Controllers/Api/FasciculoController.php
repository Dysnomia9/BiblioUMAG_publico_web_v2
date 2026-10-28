<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ejemplar;
use App\Models\Fasciculo;
use App\Models\Libro;
use App\Support\Bitacora;
use App\Support\FichaBibliografica;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fascículos de una revista (libro con la plantilla publicacion_seriada): cuáles
 * llegaron, cuáles se esperan y cuáles faltan, y registrar la recepción de uno.
 */
class FasciculoController extends Controller
{
    public function index(Libro $libro)
    {
        $fasciculos = $libro->fasciculos()
            ->with('ejemplar:id,codigo_barras,numero_copia,disponible,estado_proceso')
            // Los más recientes arriba; los que no tienen fecha, al final.
            ->orderByRaw('COALESCE(fecha_publicacion, fecha_esperada, fecha_recepcion) DESC NULLS LAST')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'fasciculos' => $fasciculos,
            'conteos' => collect(Fasciculo::ESTADOS)->map(fn ($nombre, $estado) => $fasciculos->where('estado', $estado)->count()),
        ]);
    }

    public function store(Request $request, Libro $libro)
    {
        if (FichaBibliografica::plantillaDe($libro->loadMissing('tipoMaterial')) !== 'publicacion_seriada') {
            return response()->json(['message' => 'Solo las publicaciones seriadas (revistas) tienen fascículos.'], 422);
        }

        $data = $request->validate($this->reglas($libro));
        if (($data['estado'] ?? 'esperado') === 'recibido') {
            $data['fecha_recepcion'] ??= now()->toDateString();
        }

        $fasciculo = $libro->fasciculos()->create($data);

        Bitacora::registrar(
            'libros.agregar_fasciculo',
            "Agregó el fascículo {$fasciculo->descripcion()} de «{$libro->titulo}» (".mb_strtolower(Fasciculo::ESTADOS[$fasciculo->estado ?? 'esperado']).')',
            entidad: $fasciculo,
        );

        return response()->json($fasciculo->fresh('ejemplar:id,codigo_barras,numero_copia,disponible,estado_proceso'), 201);
    }

    public function update(Request $request, Fasciculo $fasciculo)
    {
        $data = $request->validate(array_map(fn (array $reglas) => ['sometimes', ...$reglas], $this->reglas($fasciculo->libro)));

        $antes = $fasciculo->only(array_keys($data));
        $fasciculo->update($data);

        Bitacora::registrar(
            'libros.editar_fasciculo',
            "Editó el fascículo {$fasciculo->descripcion()} de «{$fasciculo->libro?->titulo}»",
            entidad: $fasciculo,
            datos: Bitacora::cambios($antes, $fasciculo->only(array_keys($data))),
        );

        return response()->json($fasciculo->fresh('ejemplar:id,codigo_barras,numero_copia,disponible,estado_proceso'));
    }

    /** Llegó el número: queda «recibido» con su fecha de recepción (hoy, si no se indica). */
    public function recibir(Request $request, Fasciculo $fasciculo)
    {
        $data = $request->validate([
            'fecha_recepcion' => ['nullable', 'date', 'before_or_equal:today'],
            'nota' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($fasciculo->estado === 'recibido') {
            return response()->json(['message' => 'Este fascículo ya está registrado como recibido.'], 409);
        }

        $fasciculo->update([
            'estado' => 'recibido',
            'fecha_recepcion' => $data['fecha_recepcion'] ?? now()->toDateString(),
            ...(filled($data['nota'] ?? null) ? ['nota' => $data['nota']] : []),
        ]);

        Bitacora::registrar(
            'libros.recibir_fasciculo',
            "Recibió el fascículo {$fasciculo->descripcion()} de «{$fasciculo->libro?->titulo}»",
            entidad: $fasciculo,
            datos: ['fecha_recepcion' => $fasciculo->fecha_recepcion?->toDateString()],
        );

        return response()->json($fasciculo->fresh('ejemplar:id,codigo_barras,numero_copia,disponible,estado_proceso'));
    }

    private function reglas(Libro $libro): array
    {
        return [
            // La copia prestable tiene que ser de esta misma revista.
            'ejemplar_id' => ['nullable', Rule::exists(Ejemplar::class, 'id')->where('libro_id', $libro->id)],
            'enumeracion' => ['nullable', 'string', 'max:255', 'required_without:cronologia'],
            'cronologia' => ['nullable', 'string', 'max:255'],
            'fecha_publicacion' => ['nullable', 'date'],
            'fecha_esperada' => ['nullable', 'date'],
            'fecha_recepcion' => ['nullable', 'date'],
            'estado' => ['nullable', Rule::in(array_keys(Fasciculo::ESTADOS))],
            'nota' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
