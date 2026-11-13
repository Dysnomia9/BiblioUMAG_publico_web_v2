<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entidades relacionadas (710) que la migración desde Horizon no trajo completas. En Horizon
 * su autoridad tiene el nombre en un subcampo equivocado ($o en vez de $a) o vacía, y el ETL
 * lee solo $a, $b y $n. Se revisaron las 19 fichas con autoridades así: el 110 quedó bien en
 * todas; en el 710, a tres les faltaba una entidad y a tres el nombre sin «España.».
 *
 * Solo se cambia si la ficha sigue como la dejó la migración: si alguien ya la corrigió a
 * mano, no se toca.
 */
return new class extends Migration
{
    /** numero_ficha => [como quedó, como está en Horizon] */
    private const CORRECCIONES = [
        5203 => ['PNUMA', 'FAO; PNUMA'],
        38754 => ['Consejo Superior de Educación (Chile)', 'Comisión Nacional de Acreditación de Pregrado (Chile); Consejo Superior de Educación (Chile)'],
        60660 => ['Cámara Chilena de la Construcción; Chile. Dirección de Bibliotecas, Archivos y Museos',
            'Cámara Chilena de la Construcción; Pontificia Universidad Católica de Chile; Chile. Dirección de Bibliotecas, Archivos y Museos'],
        55055 => [self::IUCN_ANTES, self::IUCN_DESPUES],
        55056 => [self::IUCN_ANTES, self::IUCN_DESPUES],
        55057 => [self::IUCN_ANTES, self::IUCN_DESPUES],
    ];

    private const IUCN_ANTES = 'Unión Internacional para la Conservación de la Naturaleza y de los Recursos Naturales; Ministerio de Medio Ambiente y Medio Rural y Marino; Federación Europea de Productos Acuícolas';

    private const IUCN_DESPUES = 'Unión Internacional para la Conservación de la Naturaleza y de los Recursos Naturales; España. Ministerio de Medio Ambiente y Medio Rural y Marino; Federación Europea de Productos Acuícolas';

    public function up(): void
    {
        foreach (self::CORRECCIONES as $ficha => [$antes, $despues]) {
            DB::table('libros')->where('numero_ficha', $ficha)->where('entidad_secundaria', $antes)
                ->update(['entidad_secundaria' => $despues, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::CORRECCIONES as $ficha => [$antes, $despues]) {
            DB::table('libros')->where('numero_ficha', $ficha)->where('entidad_secundaria', $despues)->update(['entidad_secundaria' => $antes]);
        }
    }
};
