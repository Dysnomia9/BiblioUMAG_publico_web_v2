<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reemplaza el binario admin/staff por roles con permisos granulares
     * (catálogo en App\Support\Permisos). staff.rol sigue guardando una clave de
     * texto — ahora con FK a roles.clave — para no romper lo que ya la usaba.
     *
     * Las cuentas 'staff' existentes pasan a 'asistente' (el rol con menos
     * permisos): es preferible que un administrador suba a alguien de rol a que
     * una cuenta quede con más acceso del que tenía sin que nadie lo decidiera.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion', 500)->nullable();
            // 1 = más alto en la jerarquía. Solo ordena y comunica; los permisos
            // reales son los de la columna permisos.
            $table->unsignedSmallInteger('nivel')->default(3);
            $table->jsonb('permisos')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('acceso_total')->default(false);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('roles')->insert([
            [
                'clave' => 'admin',
                'nombre' => 'Administrador/a',
                'descripcion' => 'Acceso total: cuentas del personal, roles, configuración institucional y todos los módulos.',
                'nivel' => 1,
                'permisos' => '[]',
                'acceso_total' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'bibliotecario',
                'nombre' => 'Bibliotecario/a',
                'descripcion' => 'Procesos técnicos y supervisión: catalogación, estados de libros, equipos, multas, historiales y reportes.',
                'nivel' => 2,
                'permisos' => json_encode([
                    'entradas.registrar', 'prestamos.gestionar', 'salas.gestionar', 'salas.hora_pasada', 'usuarios.ver',
                    'multas.ver', 'multas.cobrar', 'multas.crear', 'multas.anular',
                    'libros.catalogar', 'libros.estado', 'libros.cambio_masivo', 'equipos.gestionar',
                    'listados.ver', 'historiales.ver', 'reportes.ver',
                    'codigo_qr.ver', 'codigo_qr.regenerar',
                ]),
                'acceso_total' => false,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'asistente',
                'nombre' => 'Atención de público',
                'descripcion' => 'Registra entradas, presta y recibe libros y equipos, y reserva salas.',
                'nivel' => 3,
                'permisos' => json_encode([
                    'entradas.registrar', 'prestamos.gestionar', 'salas.gestionar', 'usuarios.ver',
                    'multas.ver', 'listados.ver', 'codigo_qr.ver',
                ]),
                'acceso_total' => false,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);

        DB::table('staff')->where('rol', '!=', 'admin')->update(['rol' => 'asistente']);

        Schema::table('staff', function (Blueprint $table) {
            $table->string('rol', 50)->default('asistente')->change();
            $table->foreign('rol')->references('clave')->on('roles')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['rol']);
            $table->string('rol')->default('staff')->change();
        });

        DB::table('staff')->where('rol', '!=', 'admin')->update(['rol' => 'staff']);

        Schema::dropIfExists('roles');
    }
};
