<?php

namespace App\Support;

use App\Models\Bitacora as Registro;
use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro absoluto de lo que hace cada persona: cada controlador que cambia algo
 * llama a registrar() con una descripción legible ("Prestó «Cálculo I» (copia 2)") y,
 * si sirve, los datos de antes y después. Quién actuó sale de la sesión (personal o,
 * en el portal, el propio usuario); sin sesión —comandos, datos de prueba— queda
 * como "Sistema".
 *
 * La acción se nombra "<módulo>.<verbo>" (prestamos.prestar, multas.anular...): el
 * módulo es lo que se filtra en la pantalla Registro absoluto.
 */
final class Bitacora
{
    public const MODULOS = [
        'entradas' => 'Entradas',
        'prestamos' => 'Préstamos y devoluciones',
        'reservas_libro' => 'Reservas de libros',
        'salas' => 'Salas',
        'multas' => 'Multas',
        'libros' => 'Libros y ejemplares',
        'equipos' => 'Recursos de apoyo',
        'usuarios' => 'Estudiantes y usuarios',
        'configuracion' => 'Configuración y catálogos',
        'personal' => 'Personal y roles',
        'sesion' => 'Sesiones y contraseñas',
    ];

    public static function registrar(
        string $accion,
        string $descripcion,
        ?Model $entidad = null,
        array $datos = [],
        Usuario|string|null $afectado = null,
        Staff|Usuario|null $actor = null,
    ): Registro {
        $actor ??= self::actorDeLaSesion();

        return Registro::create([
            'staff_id' => $actor instanceof Staff ? $actor->id : null,
            'usuario_id' => $actor instanceof Usuario ? $actor->id : null,
            'actor_nombre' => match (true) {
                $actor instanceof Staff => $actor->nombre,
                $actor instanceof Usuario => trim("{$actor->nombre} {$actor->apellido}").' (portal)',
                default => 'Sistema',
            },
            'modulo' => strstr($accion, '.', true) ?: $accion,
            'accion' => $accion,
            'descripcion' => $descripcion,
            'afectado' => $afectado instanceof Usuario ? $afectado->descripcion() : $afectado,
            'entidad_tipo' => $entidad ? class_basename($entidad) : null,
            'entidad_id' => $entidad?->getKey(),
            'datos' => $datos ?: null,
            'ip' => $actor ? request()->ip() : null,
        ]);
    }

    /**
     * Solo los campos que cambiaron, como [campo => [antes, después]]: lo que se
     * guarda en `datos` al editar algo.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function cambios(array $antes, array $despues): array
    {
        $cambios = [];
        foreach ($despues as $campo => $valor) {
            if (array_key_exists($campo, $antes) && $antes[$campo] != $valor) {
                $cambios[$campo] = [$antes[$campo], $valor];
            }
        }

        return $cambios;
    }

    public static function pesos(int $monto): string
    {
        return '$'.number_format($monto, 0, ',', '.');
    }

    private static function actorDeLaSesion(): Staff|Usuario|null
    {
        $usuario = request()->user();

        return $usuario instanceof Staff || $usuario instanceof Usuario ? $usuario : null;
    }
}
