<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte `ejemplares.ubicacion` de texto libre a un catálogo administrable (como
 * autores/categorías/carreras/estados personalizados) — a diferencia de esos, se
 * gestiona solo desde Administración (sin combobox "crear al vuelo" en catalogación),
 * porque la ubicación física es algo que el admin quiere controlar de forma
 * centralizada, no que cada staff tipee libremente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        $ahora = now();
        DB::table('ubicaciones')->insert([
            'nombre' => 'Biblioteca Central',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->foreignId('ubicacion_id')->nullable()->after('ubicacion')
                ->constrained('ubicaciones')->nullOnDelete();
        });

        // Backfill: cada valor de texto libre distinto pasa a ser una fila del catálogo
        // (find-or-create por nombre), y el ejemplar queda apuntando a esa fila.
        DB::table('ejemplares')->whereNotNull('ubicacion')->orderBy('id')
            ->chunkById(50, function ($ejemplares) use ($ahora) {
                foreach ($ejemplares as $ejemplar) {
                    $nombre = trim($ejemplar->ubicacion);
                    if ($nombre === '') {
                        continue;
                    }

                    $ubicacionId = DB::table('ubicaciones')->where('nombre', $nombre)->value('id');
                    if (! $ubicacionId) {
                        $ubicacionId = DB::table('ubicaciones')->insertGetId([
                            'nombre' => $nombre,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                    }

                    DB::table('ejemplares')->where('id', $ejemplar->id)->update(['ubicacion_id' => $ubicacionId]);
                }
            });

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropColumn('ubicacion');
        });
    }

    public function down(): void
    {
        Schema::table('ejemplares', function (Blueprint $table) {
            $table->string('ubicacion')->nullable()->after('estado_personalizado_id');
        });

        DB::statement('
            UPDATE ejemplares SET ubicacion = ubicaciones.nombre
            FROM ubicaciones
            WHERE ubicaciones.id = ejemplares.ubicacion_id
        ');

        Schema::table('ejemplares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ubicacion_id');
        });

        Schema::dropIfExists('ubicaciones');
    }
};
