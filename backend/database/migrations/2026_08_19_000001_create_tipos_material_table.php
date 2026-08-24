<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte `libros.tipo_material` de enum fijo (CHECK constraint) a un catálogo
 * administrable (mismo patrón que `ubicaciones`) — a diferencia de autores/
 * categorías/carreras, se gestiona solo desde Administración, no con "escribe y
 * crea" en catalogación. down() asume que solo existen los 5 valores originales
 * (libro/revista/tesis/dvd/otro) — si se agregaron tipos nuevos desde
 * Administración, el rollback pierde esos valores al recrear el CHECK constraint,
 * mismo criterio de irreversibilidad ya documentado en el split Libro/Ejemplar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_material', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        $ahora = now();
        $valoresOriginales = ['Libro', 'Revista', 'Tesis', 'DVD', 'Otro'];
        foreach ($valoresOriginales as $nombre) {
            DB::table('tipos_material')->insert([
                'nombre' => $nombre,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('libros', function (Blueprint $table) {
            $table->foreignId('tipo_material_id')->nullable()->after('tipo_material')
                ->constrained('tipos_material')->nullOnDelete();
        });

        // Backfill: cada libro apunta al tipo de material equivalente a su valor de texto anterior.
        $mapaNombres = ['libro' => 'Libro', 'revista' => 'Revista', 'tesis' => 'Tesis', 'dvd' => 'DVD', 'otro' => 'Otro'];
        foreach ($mapaNombres as $valorTexto => $nombre) {
            $tipoId = DB::table('tipos_material')->where('nombre', $nombre)->value('id');
            DB::table('libros')->where('tipo_material', $valorTexto)->update(['tipo_material_id' => $tipoId]);
        }

        DB::statement('ALTER TABLE libros DROP CONSTRAINT IF EXISTS chk_libros_tipo_material');

        Schema::table('libros', function (Blueprint $table) {
            $table->dropColumn('tipo_material');
        });
    }

    public function down(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            $table->string('tipo_material')->default('libro')->after('anio_publicacion');
        });

        DB::statement('
            UPDATE libros SET tipo_material = LOWER(tipos_material.nombre)
            FROM tipos_material
            WHERE tipos_material.id = libros.tipo_material_id
        ');

        Schema::table('libros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_material_id');
        });

        DB::statement("ALTER TABLE libros ADD CONSTRAINT chk_libros_tipo_material CHECK (tipo_material IS NULL OR tipo_material IN ('libro','revista','tesis','dvd','otro'))");

        Schema::dropIfExists('tipos_material');
    }
};
