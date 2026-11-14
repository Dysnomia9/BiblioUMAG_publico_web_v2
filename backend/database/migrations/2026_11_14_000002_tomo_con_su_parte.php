<?php

use App\Support\TomoEjemplar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las copias de Horizon con la parte después del tomo o volumen («t.XXXIII-part.1»,
 * «t.xxv-parte 2», «t.2-p.1-c.3», «t.18 p.1»): el lector no las reconocía y quedaron sin
 * tomo. Ahora sí (TomoEjemplar): tomo «t.XXXIII-p.1», que se ve «Tomo XXXIII · Parte 1».
 * Solo las que siguen sin tomo (las corregidas a mano no se tocan); no cambia el texto de
 * volumen ni la copia que ya tenían. Las revistas («Vol 13 part 1 (jan. 2002)») siguen sin
 * ser tomos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ejemplares')
            ->whereNull('tomo')
            ->whereRaw("volumen ~* '^(t|tomo|v|vol|volumen)\\.?\\s*[0-9ivxlc].*(p|pt|part|parte)\\M'")
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
