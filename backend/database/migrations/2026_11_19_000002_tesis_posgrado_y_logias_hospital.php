<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La Sala Moraga pasa a ser la Sala Tesis/Posgrado (código TPG), con su mismo id y todo su
 * historial; sigue siendo solo de la administración. Además se agregan las dos logias del
 * Hospital Clínico de Magallanes (HCM1 y HCM2), que se prestan como cualquier logia y en el
 * mesón van en su propia pestaña («HCM»).
 *
 * Solo crea lo que falta (por código): lo que ya se ajustó en Administración › Salas no se toca.
 */
return new class extends Migration
{
    /** Logias del hospital: código => nombre. */
    private const LOGIAS_HOSPITAL = [
        'HCM1' => 'Hospital Logia 1',
        'HCM2' => 'Hospital Logia 2',
    ];

    public function up(): void
    {
        $ahora = now();

        if (! DB::table('salas')->where('codigo', 'TPG')->exists()) {
            DB::table('salas')->where('codigo', 'MOR')->update([
                'codigo' => 'TPG', 'nombre' => 'Sala Tesis/Posgrado', 'updated_at' => $ahora,
            ]);
        }

        foreach (self::LOGIAS_HOSPITAL as $codigo => $nombre) {
            if (! DB::table('salas')->where('codigo', $codigo)->exists()) {
                DB::table('salas')->insert([
                    'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => 'logia', 'capacidad' => 4,
                    'solo_administracion' => false, 'solo_responsable' => false, 'activa' => true,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::LOGIAS_HOSPITAL) as $codigo) {
            DB::table('salas')->where('codigo', $codigo)
                ->whereNotExists(fn ($q) => $q->from('reservas')->whereColumn('reservas.sala_id', 'salas.id'))
                ->delete();
        }

        if (! DB::table('salas')->where('codigo', 'MOR')->exists()) {
            DB::table('salas')->where('codigo', 'TPG')->update(['codigo' => 'MOR', 'nombre' => 'Sala Moraga', 'updated_at' => now()]);
        }
    }
};
