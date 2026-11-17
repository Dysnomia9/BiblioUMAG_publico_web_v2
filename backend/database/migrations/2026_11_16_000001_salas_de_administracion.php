<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $nombresAula = ['aula tecnologia', 'aula de tecnologia', 'aula tecnologica'];
        $aulaExiste = false;
        // Conserva ids, capacidad y reservas: solo cambia dónde se ofrecen estas salas.
        foreach (DB::table('salas')->get(['id', 'codigo', 'nombre']) as $sala) {
            $nombre = preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim($sala->nombre))));
            $esAula = $sala->codigo === 'ATEC' || in_array($nombre, $nombresAula, true);
            $aulaExiste = $aulaExiste || $esAula;
            if ($esAula || $sala->codigo === 'L09' || in_array($nombre, [
                'logia 9', 'logia 09', 'sala 9', 'sala 09',
            ], true)) {
                DB::table('salas')->where('id', $sala->id)->update(['solo_administracion' => true, 'updated_at' => now()]);
            }
        }
        if (! $aulaExiste) {
            DB::table('salas')->insert([
                'nombre' => 'Aula Tecnología', 'codigo' => 'ATEC', 'tipo' => 'sala', 'capacidad' => 20,
                'solo_administracion' => true, 'solo_responsable' => true, 'reserva_exclusiva' => false,
                'activa' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No vuelve a ofrecer salas a estudiantes ni revierte ajustes manuales de acceso.
    }
};
