<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Models\Staff;
use App\Support\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cuentas del personal (tabla staff) — solo para quien tiene 'personal.gestionar'. */
class PersonalController extends Controller
{
    public function index()
    {
        $cuentas = Staff::with('rolAsignado:clave,nombre,nivel,acceso_total')
            ->withMax('tokens as ultimo_acceso', 'last_used_at')
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->get();

        return response()->json($cuentas->map(fn (Staff $s) => $this->serializar($s)));
    }

    public function store(Request $request)
    {
        $this->correoEnMinuscula($request);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:staff,email'],
            'password' => ['required', 'string', Password::min(8)],
            'rol' => ['required', 'exists:roles,clave'],
        ], $this->mensajes());

        $staff = Staff::create($data + ['activo' => true]);

        Bitacora::registrar('personal.crear', "Creó la cuenta de {$staff->nombre} ({$staff->email}) con el rol {$staff->rol}", entidad: $staff);

        return response()->json($this->serializar($staff->load('rolAsignado')), 201);
    }

    public function update(Request $request, Staff $staff)
    {
        $this->correoEnMinuscula($request);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('staff', 'email')->ignore($staff->id)],
            'rol' => ['required', 'exists:roles,clave'],
        ], $this->mensajes());

        if ($this->dejariaSinAccesoTotal($staff, $data['rol'])) {
            return response()->json(['message' => 'Es la única cuenta activa con acceso total: asigna ese rol a otra persona antes de cambiarle el rol a esta.'], 409);
        }

        $antes = $staff->only(array_keys($data));
        $staff->update($data);

        Bitacora::registrar(
            'personal.editar',
            "Editó la cuenta de {$staff->nombre}",
            entidad: $staff,
            datos: Bitacora::cambios($antes, $data),
        );

        return response()->json($this->serializar($staff->load('rolAsignado')));
    }

    public function cambiarActivo(Request $request, Staff $staff)
    {
        $data = $request->validate(['activo' => ['required', 'boolean']]);

        if (! $data['activo']) {
            if ($staff->is($request->user())) {
                return response()->json(['message' => 'No puedes desactivar tu propia cuenta.'], 409);
            }
            if ($this->dejariaSinAccesoTotal($staff, null)) {
                return response()->json(['message' => 'Es la única cuenta activa con acceso total: no se puede desactivar.'], 409);
            }
        }

        $staff->update(['activo' => $data['activo']]);

        Bitacora::registrar(
            $data['activo'] ? 'personal.activar' : 'personal.desactivar',
            ($data['activo'] ? 'Reactivó' : 'Desactivó')." la cuenta de {$staff->nombre}",
            entidad: $staff,
        );

        // Desactivar corta de inmediato cualquier sesión abierta, no solo los
        // próximos logins (EnsureIsStaff igual rechaza tokens de cuentas inactivas).
        if (! $data['activo']) {
            $staff->tokens()->delete();
        }

        return response()->json($this->serializar($staff->load('rolAsignado')));
    }

    /**
     * Elimina la cuenta de alguien que dejó la biblioteca. No borra la fila: la marca
     * como eliminada (SoftDeletes), así la bitácora, los préstamos, las multas y demás
     * historial siguen diciendo quién hizo cada cosa. La cuenta desaparece de Personal
     * y Roles, no puede volver a entrar y su correo queda libre para una cuenta nueva.
     * Solo se elimina una cuenta ya desactivada, nunca la propia, y escribiendo
     * «confirmar».
     */
    public function eliminar(Request $request, Staff $staff)
    {
        $request->validate(
            ['confirmacion' => ['required', 'string', fn ($campo, $valor, $falla) => mb_strtolower(trim($valor)) === 'confirmar' ? null : $falla('Escribe «confirmar» para eliminar la cuenta.')]],
            ['confirmacion.required' => 'Escribe «confirmar» para eliminar la cuenta.'],
        );

        if ($staff->is($request->user())) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 409);
        }
        if ($staff->activo) {
            return response()->json(['message' => 'Desactiva la cuenta antes de eliminarla.'], 409);
        }

        $email = $staff->email;
        $staff->tokens()->delete();
        // El correo queda libre: si la persona vuelve, se le crea una cuenta nueva con él.
        $staff->update(['email' => mb_substr("eliminada-{$staff->id}.{$email}", 0, 255)]);
        $staff->delete();

        Bitacora::registrar(
            'personal.eliminar',
            "Eliminó la cuenta de {$staff->nombre} ({$email}); su historial se conserva",
            entidad: $staff,
            datos: ['email' => $email, 'rol' => $staff->rol],
        );

        return response()->json(null, 204);
    }

    public function cambiarPassword(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
        ], $this->mensajes());

        $staff->update(['password' => $data['password']]);

        Bitacora::registrar('personal.restablecer_password', "Restableció la contraseña de {$staff->nombre}", entidad: $staff);

        // Si la contraseña se cambió por sospecha de uso indebido, las sesiones ya
        // abiertas con la anterior también deben cerrarse.
        if (! $staff->is($request->user())) {
            $staff->tokens()->delete();
        }

        return response()->json(['message' => 'Contraseña actualizada']);
    }

    /** ¿Quitarle el acceso total a $staff (cambiándolo a $nuevoRol, o desactivándolo si es null) deja al sistema sin nadie con acceso total? */
    /**
     * Antes de validar, para que "Ana@umag.cl" choque con la cuenta "ana@umag.cl" que ya
     * existe en vez de pasar como un correo distinto (se guardan en minúscula).
     */
    private function correoEnMinuscula(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }

    private function dejariaSinAccesoTotal(Staff $staff, ?string $nuevoRol): bool
    {
        $clavesAccesoTotal = Rol::where('acceso_total', true)->pluck('clave');

        if (! $staff->activo || ! $clavesAccesoTotal->contains($staff->rol)) {
            return false;
        }
        if ($nuevoRol !== null && $clavesAccesoTotal->contains($nuevoRol)) {
            return false;
        }

        return ! Staff::where('activo', true)
            ->whereIn('rol', $clavesAccesoTotal)
            ->whereKeyNot($staff->id)
            ->exists();
    }

    private function serializar(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'nombre' => $staff->nombre,
            'email' => $staff->email,
            'rol' => $staff->rol,
            'rol_nombre' => $staff->rolAsignado?->nombre,
            'activo' => $staff->activo,
            'ultimo_acceso' => $staff->ultimo_acceso ?? null,
            'created_at' => $staff->created_at,
        ];
    }

    private function mensajes(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta del personal con ese correo.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'rol.exists' => 'El rol elegido no existe.',
        ];
    }
}
