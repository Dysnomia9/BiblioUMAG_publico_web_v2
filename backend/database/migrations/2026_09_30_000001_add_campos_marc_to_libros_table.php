<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos MARC que pide la biblioteca (documento de etiquetas del 29-09-2026) y la
 * plantilla de cada tipo de material, que define qué etiquetas lleva la ficha y
 * cuáles ve el usuario (ver App\Support\FichaBibliografica).
 */
return new class extends Migration
{
    private const CAMPOS_TEXTO_CORTO = [
        'titulo_alternativo',   // 246
        'edicion',              // 250
        'lugar_publicacion',    // 260 / 264 $a
        'descripcion_fisica',   // 300
        'entidad_corporativa',  // 110
        'reunion',              // 111
        'entidad_secundaria',   // 710
        'designacion_volumen',  // 362
        'profesor_guia',        // 700 en trabajos de titulación
        'audiencia',            // 521
        'serie',                // 490
        'nota_idioma',          // 546
    ];

    private const CAMPOS_TEXTO_LARGO = [
        'materias_personales',  // 600, una por línea
        'materias_geograficas', // 651, una por línea
        'nota_tesis',           // 502
        'resumen',              // 520
        'nota_bibliografia',    // 504
        'nota_local',           // 590
    ];

    public function up(): void
    {
        Schema::table('libros', function (Blueprint $table) {
            foreach (self::CAMPOS_TEXTO_CORTO as $campo) {
                $table->string($campo)->nullable();
            }
            foreach (self::CAMPOS_TEXTO_LARGO as $campo) {
                $table->text($campo)->nullable();
            }
            $table->string('url_acceso', 2048)->nullable();  // 856
            $table->string('issn', 32)->nullable();          // 022
            $table->string('idioma', 100)->nullable();       // 041
            $table->string('frecuencia', 100)->nullable();   // 310
            $table->foreignId('catalogado_por_staff_id')->nullable()->constrained('staff')->nullOnDelete(); // 910
        });

        // El primer autor es el principal (100) y el resto, secundarios (700).
        Schema::table('libro_autor', function (Blueprint $table) {
            $table->unsignedSmallInteger('orden')->default(1);
        });
        DB::statement('UPDATE libro_autor JOIN (
            SELECT id, ROW_NUMBER() OVER (PARTITION BY libro_id ORDER BY id) AS n FROM libro_autor
        ) AS numerados ON libro_autor.id = numerados.id SET libro_autor.orden = numerados.n');

        Schema::table('tipos_material', function (Blueprint $table) {
            $table->string('plantilla', 30)->default('libro');
        });
        DB::statement("ALTER TABLE tipos_material ADD CONSTRAINT chk_tipos_material_plantilla CHECK (plantilla IN ('libro','trabajo_titulacion','juego_educativo','publicacion_seriada'))");

        DB::table('tipos_material')->whereLike('nombre', 'tesis%')->orWhereLike('nombre', 'trabajo%')->update(['plantilla' => 'trabajo_titulacion']);
        DB::table('tipos_material')->whereLike('nombre', 'revista%')->orWhereLike('nombre', '%seriada%')->update(['plantilla' => 'publicacion_seriada']);
        DB::table('tipos_material')->whereLike('nombre', 'juego%')->update(['plantilla' => 'juego_educativo']);

        if (! DB::table('tipos_material')->where('plantilla', 'juego_educativo')->exists()) {
            DB::table('tipos_material')->insert([
                'nombre' => 'Juego educativo',
                'plantilla' => 'juego_educativo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tipos_material DROP CONSTRAINT chk_tipos_material_plantilla');
        Schema::table('tipos_material', function (Blueprint $table) {
            $table->dropColumn('plantilla');
        });

        Schema::table('libro_autor', function (Blueprint $table) {
            $table->dropColumn('orden');
        });

        Schema::table('libros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalogado_por_staff_id');
            $table->dropColumn([
                ...self::CAMPOS_TEXTO_CORTO,
                ...self::CAMPOS_TEXTO_LARGO,
                'url_acceso', 'issn', 'idioma', 'frecuencia',
            ]);
        });
    }
};
