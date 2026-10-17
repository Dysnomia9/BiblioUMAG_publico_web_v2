<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Support\Bitacora;
use App\Support\Permisos;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RolController extends Controller
{
    public function index()
    {
        $roles = Rol::withCount('staff')->orderBy('nivel')->orderBy('nombre')->get();

        return response()->json([
            'roles' => $roles->map(fn (Rol $rol) => $this->serializar($rol)),
            'catalogo' => Permisos::catalogo(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglas());

        $rol = Rol::create([
            'clave' => $this->claveUnica($data['nombre']),
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'nivel' => $data['nivel'],
            'permisos' => array_values(array_unique($data['permisos'] ?? [])),
            'acceso_total' => false,
        ]);

        Bitacora::registrar('personal.crear_rol', "Creó el rol «{$rol->nombre}»", entidad: $rol, datos: ['permisos' => $rol->permisos]);

        return response()->json($this->serializar($rol->loadCount('staff')), 201);
    }

    public function update(Request $request, Rol $rol)
    {
        $data = $request->validate($this->reglas($rol));

        // El rol con acceso total solo cambia de nombre/descripción: sus permisos son
        // "todos" por definición, y quitárselos podría dejar al sistema sin nadie que
        // pueda administrar cuentas.
        $antes = $rol->only(['nombre', 'descripcion', 'nivel', 'permisos']);

        if ($rol->acceso_total) {
            $rol->update(['nombre' => $data['nombre'], 'descripcion' => $data['descripcion'] ?? null]);
        } else {
            $rol->update([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'nivel' => $data['nivel'],
                'permisos' => array_values(array_unique($data['permisos'] ?? [])),
            ]);
        }

        $despues = $rol->only(['nombre', 'descripcion', 'nivel', 'permisos']);
        Bitacora::registrar('personal.editar_rol', "Editó el rol «{$rol->nombre}»", entidad: $rol, datos: [
            ...Bitacora::cambios(array_diff_key($antes, ['permisos' => 0]), array_diff_key($despues, ['permisos' => 0])),
            'permisos_agregados' => array_values(array_diff($despues['permisos'] ?? [], $antes['permisos'] ?? [])),
            'permisos_quitados' => array_values(array_diff($antes['permisos'] ?? [], $despues['permisos'] ?? [])),
        ]);

        return response()->json($this->serializar($rol->loadCount('staff')));
    }

    public function destroy(Rol $rol)
    {
        if ($rol->acceso_total) {
            return response()->json(['message' => 'El rol con acceso total no se puede eliminar.'], 409);
        }

        $cuentas = $rol->staff()->count();
        if ($cuentas > 0) {
            return response()->json([
                'message' => "Hay {$cuentas} ".($cuentas === 1 ? 'cuenta' : 'cuentas').' con este rol. Asígnales otro rol antes de eliminarlo.',
            ], 409);
        }

        // Las cuentas eliminadas siguen apuntando a su rol (la base no deja borrar un rol
        // en uso), pero ya no lo usan: pasan al de menos permisos para poder borrarlo.
        if ($rol->staff()->onlyTrashed()->exists()) {
            $rol->staff()->onlyTrashed()->update(['rol' => Rol::whereKeyNot($rol->id)->orderByDesc('nivel')->value('clave')]);
        }

        $rol->delete();

        Bitacora::registrar('personal.eliminar_rol', "Eliminó el rol «{$rol->nombre}»", datos: ['clave' => $rol->clave, 'permisos' => $rol->permisos]);

        return response()->json(null, 204);
    }

    private function reglas(?Rol $rol = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('roles', 'nombre')->ignore($rol?->id)],
            'descripcion' => ['nullable', 'string', 'max:500'],
            // El nivel 1 queda para el rol con acceso total.
            'nivel' => [$rol?->acceso_total ? 'nullable' : 'required', 'integer', 'min:2', 'max:9'],
            'permisos' => ['nullable', 'array'],
            'permisos.*' => ['string', Rule::in(Permisos::asignables())],
        ];
    }

    private function claveUnica(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre, '_'), 40, '') ?: 'rol';
        $clave = $base;
        $n = 2;
        while (Rol::where('clave', $clave)->exists()) {
            $clave = "{$base}_{$n}";
            $n++;
        }

        return $clave;
    }

    private function serializar(Rol $rol): array
    {
        return [
            'id' => $rol->id,
            'clave' => $rol->clave,
            'nombre' => $rol->nombre,
            'descripcion' => $rol->descripcion,
            'nivel' => $rol->nivel,
            'acceso_total' => $rol->acceso_total,
            'permisos' => $rol->permisosEfectivos(),
            'staff_count' => $rol->staff_count ?? 0,
        ];
    }
}
