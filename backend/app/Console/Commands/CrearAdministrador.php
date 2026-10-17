<?php

namespace App\Console\Commands;

use App\Models\Rol;
use App\Models\Staff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Crea la primera cuenta con acceso total en una base sin datos de prueba
 * (producción), o recupera el acceso si nadie más puede entrar a "Personal y
 * roles". Si el correo ya existe, esa cuenta pasa a tener acceso total y se
 * reactiva. Las demás cuentas se crean después desde la pantalla.
 */
class CrearAdministrador extends Command
{
    protected $signature = 'personal:crear-admin
        {email : Correo con el que la persona entrará al panel}
        {--nombre= : Nombre completo (obligatorio si la cuenta no existe)}
        {--password= : Contraseña de al menos 8 caracteres; si se omite, se genera una al azar}';

    protected $description = 'Crea (o promueve) una cuenta del personal con acceso total';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $password = $this->option('password');
        $generada = $password === null;
        $password ??= Str::password(14, symbols: false);

        $validador = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'max:255'], 'password' => ['string', 'min:8']],
        );
        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $rol = Rol::where('acceso_total', true)->orderBy('nivel')->first();
        if (! $rol) {
            $this->error('No hay ningún rol con acceso total. Corre las migraciones primero (php artisan migrate).');

            return self::FAILURE;
        }

        $staff = Staff::where('email', $email)->first();
        if (! $staff && ! $this->option('nombre')) {
            $this->error('La cuenta no existe: indica el nombre con --nombre="Nombre Apellido".');

            return self::FAILURE;
        }

        $staff ??= new Staff(['email' => $email]);
        $staff->fill(array_filter([
            'nombre' => $this->option('nombre'),
            'rol' => $rol->clave,
            'activo' => true,
        ], fn ($valor) => $valor !== null));
        $staff->password = $password;
        $staff->save();

        // Una contraseña nueva invalida las sesiones abiertas con la anterior.
        $staff->tokens()->delete();

        $this->info("Listo: {$staff->email} tiene el rol «{$rol->nombre}».");
        if ($generada) {
            $this->line("Contraseña generada: {$password}");
            $this->line('Anótala ahora (no se vuelve a mostrar) y cámbiala desde el ícono de llave al entrar.');
        }

        return self::SUCCESS;
    }
}
