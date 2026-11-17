<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fichas_interbibliotecarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('libro_id')->constrained('libros')->restrictOnDelete();
            $table->string('biblioteca');
            $table->date('fecha_ingreso');
            $table->date('fecha_limite')->index();
            $table->text('nota')->nullable();
            $table->foreignId('registrado_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE fichas_interbibliotecarias ADD CONSTRAINT chk_fichas_interbibliotecarias_fechas CHECK (fecha_limite >= fecha_ingreso)');
        Schema::table('prestamos_interbibliotecarios', function (Blueprint $table) {
            $table->foreignId('ficha_interbibliotecaria_id')->nullable()->constrained('fichas_interbibliotecarias')->restrictOnDelete();
        });

        // Conserva los ingresos existentes y agrupa las copias del mismo título, biblioteca y plazo.
        DB::statement('INSERT INTO fichas_interbibliotecarias (libro_id, biblioteca, fecha_ingreso, fecha_limite, nota, registrado_por_staff_id, created_at, updated_at)
            SELECT e.libro_id, p.biblioteca, p.fecha_ingreso, p.fecha_limite, p.nota,
                MIN(p.registrado_por_staff_id), MIN(p.created_at), MAX(p.updated_at)
            FROM prestamos_interbibliotecarios p JOIN ejemplares e ON e.id = p.ejemplar_id
            GROUP BY e.libro_id, p.biblioteca, p.fecha_ingreso, p.fecha_limite, p.nota');
        DB::statement('UPDATE prestamos_interbibliotecarios p SET ficha_interbibliotecaria_id = f.id
            FROM fichas_interbibliotecarias f, ejemplares e
            WHERE e.id = p.ejemplar_id AND f.libro_id = e.libro_id AND f.biblioteca = p.biblioteca
                AND f.fecha_ingreso = p.fecha_ingreso AND f.fecha_limite = p.fecha_limite
                AND f.nota IS NOT DISTINCT FROM p.nota');
    }

    public function down(): void
    {
        Schema::table('prestamos_interbibliotecarios', fn (Blueprint $table) => $table->dropConstrainedForeignId('ficha_interbibliotecaria_id'));
        Schema::dropIfExists('fichas_interbibliotecarias');
    }
};
