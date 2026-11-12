<?php

use App\Support\TomoEjemplar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las copias de Horizon con tomo y volumen juntos («t.1-v.II», «t.2-v.I-c.1», «v.1-t.2»):
 * el ETL no las reconocía y quedaron sin tomo, con su texto en volumen. Ahora ese par es su
 * tomo (ver TomoEjemplar), para numerar sus copias y mostrarlas como «Tomo 1 · Volumen II».
 * No cambia el texto de volumen ni la copia que ya tenían.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ejemplares')
            ->whereNull('tomo')
            ->whereRaw("volumen ~* '^(t|tomo|v|vol|volumen)\\.?\\s*[0-9]+[a-z]?\\s*[-,;/ ]\\s*(t|tomo|v|vol|volumen)'")
            ->orderBy('id')
            ->get(['id', 'volumen', 'copia_tomo'])
            ->each(function ($copia) {
                [$tomo, $copiaDelTomo] = TomoEjemplar::desdeEtiqueta($copia->volumen);
                if ($tomo !== null) {
                    DB::table('ejemplares')->where('id', $copia->id)->update([
                        'tomo' => $tomo,
                        'copia_tomo' => $copia->copia_tomo ?? $copiaDelTomo,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: el tomo así es el que corresponde.
    }
};
