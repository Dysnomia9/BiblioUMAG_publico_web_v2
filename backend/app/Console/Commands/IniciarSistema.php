<?php

namespace App\Console\Commands;

use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\VaciarBase;
use Illuminate\Console\Command;

/**
 * Deja el sistema listo para cargar los datos reales: sin cuentas, usuarios, libros ni
 * movimientos de prueba, con lo que crean las migraciones (roles, salas, carreras,
 * categorías de recursos, tipos de material) y una sola cuenta, la del administrador
 * general, con una contraseña al azar que se muestra una vez. Si la base ya tiene
 * datos no hace nada, salvo que se pida --vaciar y se confirme escribiendo VACIAR.
 */
class IniciarSistema extends Command
{
    protected $signature = 'sistema:iniciar
        {email=admin@umag.cl : Correo de la cuenta de administrador general}
        {--nombre=Administrador general : Nombre de esa cuenta}
        {--vaciar : Antes borra TODOS los datos: personal, usuarios, libros, préstamos, multas, bitácora...}
        {--force : Con --vaciar, no pide escribir la confirmación (en producción no se acepta)}';

    protected $description = 'Deja el sistema vacío, solo con la cuenta de administrador general (contraseña al azar)';

    public function handle(VaciarBase $vaciarBase): int
    {
        $datos = array_filter([
            'cuentas del personal' => Staff::withTrashed()->count(),
            'usuarios' => Usuario::count(),
            'libros' => Libro::count(),
            'recursos de apoyo' => Equipo::count(),
        ]);
        $resumen = collect($datos)->map(fn ($cantidad, $que) => "{$cantidad} {$que}")->join(', ', ' y ');

        if ($datos && ! $this->option('vaciar')) {
            $this->error("La base ya tiene datos ({$resumen}). Para borrarlos y empezar de cero: php artisan sistema:iniciar --vaciar");

            return self::FAILURE;
        }

        if ($datos) {
            $this->warn("Se borrará TODO: {$resumen}, con sus préstamos, reservas, multas, entradas y bitácora. No se puede deshacer.");

            if (! $this->confirmaVaciar()) {
                $this->warn('No se borró nada.');

                return self::FAILURE;
            }

            $this->info('Vaciando la base: se vuelven a crear las tablas y los catálogos de fábrica...');
            $vaciarBase();
        }

        $resultado = $this->call('personal:crear-admin', [
            'email' => $this->argument('email'),
            '--nombre' => $this->option('nombre'),
        ]);

        if ($resultado === self::SUCCESS) {
            $this->newLine();
            $this->line('Siguientes pasos: entrar al panel con esa cuenta, cambiar la contraseña, cargar los usuarios');
            $this->line('(Administración › Estudiantes › Carga masiva) y después el catálogo de libros.');
        }

        return $resultado;
    }

    private function confirmaVaciar(): bool
    {
        if (! $this->option('force')) {
            return $this->ask('Para confirmar, escribe VACIAR') === 'VACIAR';
        }

        if ($this->laravel->isProduction()) {
            $this->error('En producción hay que confirmarlo escribiendo VACIAR: no se acepta --force.');

            return false;
        }

        return true;
    }
}
