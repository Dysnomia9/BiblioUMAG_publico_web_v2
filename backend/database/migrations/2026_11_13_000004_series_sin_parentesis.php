<?php

use App\Support\PuntuacionIsbd;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El 490 se guarda sin paréntesis: los pone la ficha al mostrarlo. Las pocas que ya los
 * traían («(HARRY POTTER)», «(La Ley / Thomson Reuters» de Horizon) se verían dobles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('libros')->where(fn ($q) => $q->where('serie', 'like', '%(%')->orWhere('serie', 'like', '%)%'))
            ->orderBy('id')->get(['id', 'serie'])
            ->each(function ($libro) {
                $limpia = PuntuacionIsbd::serie($libro->serie);
                if ($limpia !== $libro->serie) {
                    DB::table('libros')->where('id', $libro->id)->update(['serie' => $limpia]);
                }
            });
    }

    public function down(): void
    {
        // Sin vuelta atrás: los paréntesis los pone la ficha.
    }
};
