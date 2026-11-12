<?php

namespace App\Support;

use App\Models\RolAutor;
use Illuminate\Support\Collection;

/**
 * Papeles que puede tener una persona en una obra (MARC 700 $e), administrados en
 * Administración → Catálogos (tabla roles_autor). libro_autor.rol guarda la clave; sin
 * rol, es autor. Se leen una vez por petición.
 */
final class RolesAutor
{
    private static ?Collection $cache = null;

    /** @return Collection<string, RolAutor> por clave */
    private static function todos(): Collection
    {
        return self::$cache ??= RolAutor::orderBy('orden')->orderBy('nombre')->get()->keyBy('clave');
    }

    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /** Las claves que se pueden asignar al catalogar (las activas). */
    public static function claves(): array
    {
        return self::todos()->filter->activo->keys()->all();
    }

    public static function abreviatura(?string $rol): ?string
    {
        return $rol ? self::todos()->get($rol)?->abreviatura : null;
    }

    /** Nombre de la fila de la ficha para ese papel; sin papel, «Autores secundarios». */
    public static function plural(?string $rol): string
    {
        return ($rol ? self::todos()->get($rol)?->plural : null) ?? 'Autores secundarios';
    }
}
