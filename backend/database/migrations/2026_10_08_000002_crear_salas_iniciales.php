<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las salas de la biblioteca: 15 logias de estudio y 3 salas con nombre propio. No hay
 * pantalla para crearlas, y en producción no corre mockup:datos, así que sin esto una
 * instalación nueva partiría sin salas que reservar. Solo se crean si la tabla está
 * vacía: una base que ya tiene sus salas no se toca.
 *
 * Las logias quedan sin código de barras: los reales de Horizon se cargan en
 * config/horizon_barcodes.php y se aplican con `php artisan horizon:codigos-logia`.
 * mockup:datos (SeedMockupData::seedSalas()) reutiliza estas mismas salas por nombre.
 */
return new class extends Migration
{
    private const CAPACIDADES_LOGIAS = [2, 3, 4, 2, 3, 4, 2, 3, 4, 2, 3, 4, 2, 3, 4];

    private const SALAS_ESPECIALES = [
        ['nombre' => 'Sala de Seminarios', 'capacidad' => 30],
        ['nombre' => 'Sala de Postgrado', 'capacidad' => 20],
        ['nombre' => 'Sala AGACI', 'capacidad' => 10],
    ];

    public function up(): void
    {
        if (DB::table('salas')->exists()) {
            return;
        }

        $ahora = now();
        $salas = [];

        foreach (self::CAPACIDADES_LOGIAS as $i => $capacidad) {
            $salas[] = ['nombre' => 'Logia '.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'capacidad' => $capacidad, 'tipo' => 'logia'];
        }
        foreach (self::SALAS_ESPECIALES as $sala) {
            $salas[] = $sala + ['tipo' => 'sala'];
        }

        DB::table('salas')->insert(array_map(fn (array $sala) => $sala + [
            'codigo_barras' => null,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $salas));
    }

    public function down(): void
    {
        // Solo si nunca se usaron: con reservas, las salas son parte del historial.
        $nombres = array_merge(
            array_map(fn ($i) => 'Logia '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 15)),
            array_column(self::SALAS_ESPECIALES, 'nombre'),
        );

        DB::table('salas')
            ->whereIn('nombre', $nombres)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reservas')->whereColumn('reservas.sala_id', 'salas.id'))
            ->delete();
    }
};
