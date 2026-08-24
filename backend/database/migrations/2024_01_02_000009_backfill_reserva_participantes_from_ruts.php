<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $reservas = DB::table('reservas')->whereNotNull('ruts')->select('id', 'ruts')->get();

        if ($reservas->isEmpty()) {
            return;
        }

        $ruts = $reservas->flatMap(fn ($r) => json_decode($r->ruts, true) ?? [])->unique()->values();
        $idPorRut = DB::table('usuarios')->whereIn('rut', $ruts)->pluck('id', 'rut');

        $filas = [];
        $ahora = now();

        foreach ($reservas as $reserva) {
            foreach (json_decode($reserva->ruts, true) ?? [] as $rut) {
                // Defensivo: SeedMockupData/ReservaFactory pueden escribir `ruts` sin pasar
                // por la validación HTTP (`exists:usuarios,rut`), así que un rut podría no
                // resolver a un usuario real. Se ignora en vez de fallar la migración.
                if (! isset($idPorRut[$rut])) {
                    continue;
                }

                $filas[] = [
                    'reserva_id' => $reserva->id,
                    'usuario_id' => $idPorRut[$rut],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 500) as $chunk) {
            DB::table('reserva_participantes')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('reserva_participantes')->truncate();
    }
};
