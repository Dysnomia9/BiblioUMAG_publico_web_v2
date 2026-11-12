<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Papeles que puede tener una persona en una obra (MARC 700 $e): traductor, editor,
 * profesor patrocinante… Se administran en Administración → Catálogos. libro_autor.rol
 * guarda la clave; sin rol, la persona es autor.
 */
return new class extends Migration
{
    private const ROLES = [
        // clave, nombre, abreviatura (en «Pérez, Juan, trad.»), plural (fila de la ficha)
        ['coautor', 'Coautor', 'coaut.', 'Coautores'],
        ['traductor', 'Traductor', 'trad.', 'Traductores'],
        ['editor', 'Editor', 'ed.', 'Editores'],
        ['compilador', 'Compilador', 'comp.', 'Compiladores'],
        ['coordinador', 'Coordinador', 'coord.', 'Coordinadores'],
        ['director', 'Director', 'dir.', 'Directores'],
        ['revisor', 'Revisor', 'rev.', 'Revisores'],
        ['colaborador', 'Colaborador', 'colab.', 'Colaboradores'],
        ['ilustrador', 'Ilustrador', 'il.', 'Ilustradores'],
        ['prologuista', 'Prologuista', 'pról.', 'Prologuistas'],
        ['adaptador', 'Adaptador', 'adapt.', 'Adaptadores'],
        ['fotografo', 'Fotógrafo', 'fot.', 'Fotógrafos'],
        // De los trabajos de titulación (los traía Horizon en 700 $e)
        ['profesor_patrocinante', 'Profesor patrocinante', 'prof. patroc.', 'Profesores patrocinantes'],
        ['profesor_co_referente', 'Profesor co-referente', 'prof. co-ref.', 'Profesores co-referentes'],
        ['profesor_informante', 'Profesor informante', 'prof. inf.', 'Profesores informantes'],
        ['docente_supervisor', 'Docente supervisor', 'doc. superv.', 'Docentes supervisores'],
        ['docente_asignatura', 'Docente de asignatura', 'doc. asig.', 'Docentes de asignatura'],
        ['asesor_metodologico', 'Asesor metodológico', 'ases. metod.', 'Asesores metodológicos'],
        ['asesor_estadistico', 'Asesor estadístico', 'ases. estad.', 'Asesores estadísticos'],
        ['asesor', 'Asesor', 'ases.', 'Asesores'],
    ];

    public function up(): void
    {
        Schema::create('roles_autor', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 30)->unique();
            $table->string('nombre', 80)->unique();
            $table->string('abreviatura', 30);
            $table->string('plural', 80);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('roles_autor')->insert(array_map(fn ($r, $i) => [
            'clave' => $r[0], 'nombre' => $r[1], 'abreviatura' => $r[2], 'plural' => $r[3],
            'orden' => $i + 1, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
        ], self::ROLES, array_keys(self::ROLES)));
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_autor');
    }
};
