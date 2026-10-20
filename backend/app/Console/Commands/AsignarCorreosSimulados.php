<?php

namespace App\Console\Commands;

use App\Models\CredencialSimulada;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\CorreoSimulado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Le da a cada cuenta del personal y a cada usuario su correo simulado del login v2
 * (iniciales @umag.cl) con la clave de prueba de config/login_v2.php. Quien ya tiene
 * uno lo conserva; se puede correr de nuevo cuando se agregan personas.
 */
class AsignarCorreosSimulados extends Command
{
    protected $signature = 'login-v2:correos';

    protected $description = 'Asigna correos simulados (iniciales@umag.cl) y una clave de prueba para el login v2';

    public function handle(): int
    {
        $tomados = CredencialSimulada::pluck('correo')->flip()->all();
        $tiene = CredencialSimulada::get(['cuenta_type', 'cuenta_id'])->map(fn ($c) => "{$c->cuenta_type}:{$c->cuenta_id}")->flip()->all();
        // Un solo hash para todas: la clave es la misma y así no se calcula cientos de veces.
        $clave = Hash::make(config('login_v2.clave'));

        // El personal guarda el nombre completo en un campo; los usuarios, nombre y apellido aparte.
        $cuentas = Staff::orderBy('id')->get()
            ->map(function (Staff $s) {
                $partes = explode(' ', trim($s->nombre), 2);

                return [$s, $partes[0], $partes[1] ?? ''];
            })
            ->concat(Usuario::orderBy('id')->get()->map(fn (Usuario $u) => [$u, $u->nombre, $u->apellido]));

        $nuevas = [];
        foreach ($cuentas as [$cuenta, $nombre, $apellido]) {
            if (isset($tiene[$cuenta->getMorphClass().':'.$cuenta->getKey()])) {
                continue;
            }

            $correo = CorreoSimulado::libre($nombre, $apellido, fn (string $c) => isset($tomados[$c]));
            $tomados[$correo] = true;
            $credencial = new CredencialSimulada(['correo' => $correo, 'clave' => $clave]);
            $credencial->cuenta()->associate($cuenta)->save();
            $nuevas[] = [$correo, trim("{$nombre} {$apellido}"), $cuenta instanceof Staff ? 'Personal' : 'Usuario'];
        }

        if ($nuevas) {
            $this->table(['Correo simulado', 'Persona', 'Cuenta'], array_slice($nuevas, 0, 15));
            if (count($nuevas) > 15) {
                $this->line('  … y '.(count($nuevas) - 15).' más.');
            }
        }
        $this->info(count($nuevas).' correos simulados nuevos. Clave de prueba de todos: '.config('login_v2.clave'));
        if (! config('login_v2.correos_simulados')) {
            $this->warn('El ingreso con estos correos está apagado en este servidor (LOGIN_V2_CORREOS_SIMULADOS).');
        }

        return self::SUCCESS;
    }
}
