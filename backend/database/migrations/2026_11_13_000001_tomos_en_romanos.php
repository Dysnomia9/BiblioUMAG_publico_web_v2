<?php

use App\Support\TomoEjemplar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las copias de Horizon cuyo tomo o volumen va en romanos («t.I», «v.II», «t. II»,
 * «t.III-v.2»): el lector no los reconocía y quedaron sin tomo. Ahora sí (TomoEjemplar).
 * No cambia el texto de volumen ni la copia que ya tenían; los números de revista
 * («Vol XXVII (sept. 2013)») siguen sin ser tomos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ejemplares')
            ->whereNull('tomo')
            ->whereRaw("volumen ~* '^(t|tomo|v|vol|volumen)\\.?\\s*[ivxlc]+'")
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
