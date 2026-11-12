<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RolAutor;
use App\Support\Bitacora;
use App\Support\RolesAutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Papeles de autor (700 $e) en Administración → Catálogos: crearlos, renombrarlos,
 * desactivarlos (dejan de ofrecerse al catalogar, pero los libros que los tienen los
 * conservan) y eliminar los que ningún libro usa.
 */
class RolAutorController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(RolAutor::query()
            ->when(! $request->boolean('todos'), fn ($q) => $q->where('activo', true))
            ->addSelect(['en_uso' => DB::table('libro_autor')->selectRaw('count(*)')->whereColumn('libro_autor.rol', 'roles_autor.clave')])
            ->orderBy('orden')->orderBy('nombre')
            ->get()
            ->each(fn ($r) => $r->en_uso = (int) $r->en_uso));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->reglas());
        $clave = Str::of($data['nombre'])->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(30, '')->toString();
        if (RolAutor::where('clave', $clave)->exists()) {
            $clave = Str::limit($clave, 25, '').'_'.Str::lower(Str::random(4));
        }

        $rol = RolAutor::create([...$data, 'clave' => $clave, 'orden' => (RolAutor::max('orden') ?? 0) + 1]);
        RolesAutor::olvidar();
        Bitacora::registrar('catalogo.crear_rol_autor', "Creó el papel de autor «{$rol->nombre}»", entidad: $rol);

        return response()->json($rol, 201);
    }

    public function update(Request $request, RolAutor $rolAutor)
    {
        $data = $request->validate($this->reglas($rolAutor->id) + ['activo' => ['sometimes', 'boolean']]);
        $antes = $rolAutor->only(array_keys($data));
        $rolAutor->update($data);
        RolesAutor::olvidar();
        Bitacora::registrar('catalogo.editar_rol_autor', "Editó el papel de autor «{$rolAutor->nombre}»", entidad: $rolAutor,
            datos: Bitacora::cambios($antes, $data));

        return response()->json($rolAutor);
    }

    public function destroy(RolAutor $rolAutor)
    {
        $enUso = DB::table('libro_autor')->where('rol', $rolAutor->clave)->count();
        if ($enUso) {
            return response()->json(['message' => "«{$rolAutor->nombre}» está asignado en {$enUso} ".($enUso === 1 ? 'libro' : 'libros').': desactívalo en vez de eliminarlo.'], 409);
        }

        $nombre = $rolAutor->nombre;
        $rolAutor->delete();
        RolesAutor::olvidar();
        Bitacora::registrar('catalogo.eliminar_rol_autor', "Eliminó el papel de autor «{$nombre}», que no estaba en uso");

        return response()->noContent();
    }

    private function reglas(?int $ignorar = null): array
    {
        $parcial = $ignorar ? 'sometimes' : 'required';

        return [
            'nombre' => [$parcial, 'string', 'max:80', Rule::unique('roles_autor', 'nombre')->ignore($ignorar)],
            'abreviatura' => [$parcial, 'string', 'max:30'],
            'plural' => [$parcial, 'string', 'max:80'],
        ];
    }
}
