<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las salas quedan como en el plano de la biblioteca (frontend/src/utils/mapaSalas.ts), que es
 * lo único que tiene la cantidad real: logias 1 a 13 y 22 a 30, AGACI, Postgrado, Moraga,
 * Cooperación y los seminarios 1 y 2. Una logia que no está en el plano no existe: la 14 y la
 * 15 (de crear_salas_iniciales) se eliminan si nunca se reservaron y, si tienen historial, se
 * dan de baja (como en Administración › Salas; con una reserva activa se dejan, para no dejarla
 * sin sala). Moraga, Cooperación y los seminarios no se prestan a estudiantes: solo los
 * reserva la administración, a nombre de una persona, como el seminario de siempre.
 *
 * Solo crea lo que falta (por código): lo que ya se ajustó en Administración › Salas no se toca.
 */
return new class extends Migration
{
    /** Logias del plano que no venían en las salas iniciales. */
    private const LOGIAS_NUEVAS = [22, 23, 24, 25, 26, 27, 28, 29, 30];

    /** Salas de la administración del plano: código => [nombre, capacidad]. */
    private const SALAS_ADMINISTRACION = [
        'MOR' => ['Sala Moraga', 10],
        'COO' => ['Sala Cooperación', 20],
        'SEM2' => ['Seminario 2', 30],
    ];

    /** Logias que no están en el plano. */
    private const LOGIAS_QUE_NO_EXISTEN = ['L14', 'L15'];

    public function up(): void
    {
        $ahora = now();
        $nueva = fn (array $datos) => $datos + ['activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora];

        foreach (self::LOGIAS_NUEVAS as $numero) {
            $codigo = 'L'.str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
            if (! DB::table('salas')->where('codigo', $codigo)->exists()) {
                DB::table('salas')->insert($nueva([
                    'codigo' => $codigo, 'nombre' => "Logia {$numero}", 'tipo' => 'logia', 'capacidad' => 4,
                    'solo_administracion' => false, 'solo_responsable' => false,
                ]));
            }
        }

        foreach (self::SALAS_ADMINISTRACION as $codigo => [$nombre, $capacidad]) {
            if (DB::table('salas')->where('codigo', $codigo)->exists()) {
                DB::table('salas')->where('codigo', $codigo)->update(['solo_administracion' => true, 'updated_at' => $ahora]);
            } else {
                DB::table('salas')->insert($nueva([
                    'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => 'sala', 'capacidad' => $capacidad,
                    'solo_administracion' => true, 'solo_responsable' => true,
                ]));
            }
        }

        // Con un segundo seminario, el de siempre pasa a llamarse como en el plano (si nadie lo renombró).
        DB::table('salas')->where('codigo', 'SEM')->where('nombre', 'Sala de Seminarios')->update(['nombre' => 'Seminario 1', 'updated_at' => $ahora]);

        foreach (DB::table('salas')->whereIn('codigo', self::LOGIAS_QUE_NO_EXISTEN)->get(['id']) as $sala) {
            $reservas = DB::table('reservas')->where('sala_id', $sala->id);
            if (! (clone $reservas)->exists()) {
                DB::table('salas')->where('id', $sala->id)->delete();
            } elseif (! (clone $reservas)->where('estado', 'activa')->exists()) {
                DB::table('salas')->where('id', $sala->id)->update(['activa' => false, 'updated_at' => $ahora]);
            }
        }
    }

    public function down(): void
    {
        $sinHistorial = fn (string $codigo) => DB::table('salas')->where('codigo', $codigo)
            ->whereNotExists(fn ($q) => $q->from('reservas')->whereColumn('reservas.sala_id', 'salas.id'));

        foreach (self::LOGIAS_NUEVAS as $numero) {
            $sinHistorial('L'.str_pad((string) $numero, 2, '0', STR_PAD_LEFT))->delete();
        }
        foreach (array_keys(self::SALAS_ADMINISTRACION) as $codigo) {
            $sinHistorial($codigo)->delete();
        }
        DB::table('salas')->where('codigo', 'SEM')->where('nombre', 'Seminario 1')->update(['nombre' => 'Sala de Seminarios']);

        // Las logias 14 y 15: se reactivan si quedaron de baja, o se vuelven a crear.
        foreach (self::LOGIAS_QUE_NO_EXISTEN as $codigo) {
            if (DB::table('salas')->where('codigo', $codigo)->exists()) {
                DB::table('salas')->where('codigo', $codigo)->update(['activa' => true]);
            } else {
                $numero = (int) substr($codigo, 1);
                DB::table('salas')->insert([
                    'codigo' => $codigo, 'nombre' => "Logia {$numero}", 'tipo' => 'logia', 'capacidad' => $numero === 15 ? 4 : 3,
                    'solo_administracion' => false, 'solo_responsable' => false, 'activa' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
};
