<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los recursos de apoyo (todo lo que se presta y no es un libro) dejan de ser una
     * lista fija —audífonos, notebook y cargador, escrita en CHECKs y en el código— y
     * pasan a un catálogo que se administra desde Administración → Catálogos, para
     * sumar bicicletas u otros recursos sin tocar el sistema.
     *
     * `clave` es lo que se guarda en equipos.tipo y prestamos.tipo_item; no cambia
     * aunque se le cambie el nombre a la categoría. La integridad sigue en la base:
     * equipos.tipo es una FK a la clave, y prestamos.tipo_item tiene que ser 'libro'
     * o una clave existente (FK sobre una columna generada que vale NULL para los
     * libros).
     */
    public function up(): void
    {
        Schema::create('categorias_recurso', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 100)->unique();
            $table->string('nombre_plural', 100);
            $table->string('icono', 30)->default('caja');
            $table->string('color', 20)->default('slate');
            // Cuántos de esta categoría puede tener una persona a la vez (null: sin límite).
            $table->unsignedSmallInteger('limite_por_persona')->nullable();
            // Lo que el personal le recuerda a quien se lo lleva (plazo, accesorios…).
            $table->string('indicaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('categorias_recurso')->insert([
            ['clave' => 'audifonos', 'nombre' => 'Audífonos', 'nombre_plural' => 'Audífonos', 'icono' => 'audifonos', 'color' => 'violet', 'limite_por_persona' => null, 'indicaciones' => 'Se devuelven al término de la estadía', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['clave' => 'notebook', 'nombre' => 'Notebook', 'nombre_plural' => 'Notebooks', 'icono' => 'notebook', 'color' => 'sky', 'limite_por_persona' => null, 'indicaciones' => 'Se devuelven al término de la estadía', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['clave' => 'cargador', 'nombre' => 'Cargador', 'nombre_plural' => 'Cargadores', 'icono' => 'cargador', 'color' => 'teal', 'limite_por_persona' => null, 'indicaciones' => 'Se devuelven al término de la estadía', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['clave' => 'bicicleta', 'nombre' => 'Bicicleta', 'nombre_plural' => 'Bicicletas', 'icono' => 'bicicleta', 'color' => 'emerald', 'limite_por_persona' => 1, 'indicaciones' => 'Se devuelve el mismo día, antes del cierre, con su casco y candado', 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        DB::statement('ALTER TABLE equipos DROP CONSTRAINT IF EXISTS chk_equipos_tipo');
        Schema::table('equipos', function (Blueprint $table) {
            $table->foreign('tipo')->references('clave')->on('categorias_recurso')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT IF EXISTS chk_prestamos_tipo_item');
        DB::statement("ALTER TABLE prestamos ADD COLUMN categoria_recurso_clave varchar(40) GENERATED ALWAYS AS (NULLIF(tipo_item, 'libro')) STORED");
        DB::statement('ALTER TABLE prestamos ADD CONSTRAINT prestamos_categoria_recurso_clave_foreign FOREIGN KEY (categoria_recurso_clave) REFERENCES categorias_recurso (clave) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX prestamos_categoria_recurso_clave_index ON prestamos (categoria_recurso_clave)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prestamos DROP COLUMN IF EXISTS categoria_recurso_clave');
        Schema::table('equipos', function (Blueprint $table) {
            $table->dropForeign(['tipo']);
        });

        // Volver al CHECK fijo solo es posible si no quedaron recursos de otras categorías.
        DB::statement("ALTER TABLE equipos ADD CONSTRAINT chk_equipos_tipo CHECK (tipo IN ('audifonos','notebook','cargador'))");
        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_tipo_item CHECK (tipo_item IN ('libro','audifonos','notebook','cargador'))");

        Schema::dropIfExists('categorias_recurso');
    }
};
