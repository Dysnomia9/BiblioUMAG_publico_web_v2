<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las multas dejan de ser columnas de `prestamos` y pasan a una tabla propia:
     * una multa puede no venir de un préstamo (deterioro, pérdida), un préstamo
     * puede tener más de una, y el registro institucional de multas que se va a
     * integrar más adelante es una tabla aparte — `origen` y `referencia_externa`
     * existen para poder importar esas filas sin mezclarlas con las generadas acá.
     */
    public function up(): void
    {
        Schema::create('multas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('prestamo_id')->nullable()->constrained('prestamos')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->unsignedInteger('monto');
            $table->string('estado', 20)->default('pendiente');
            $table->string('detalle', 500)->nullable();
            $table->unsignedInteger('dias_atraso')->nullable();
            $table->string('origen', 20)->default('sistema');
            $table->string('referencia_externa')->nullable()->unique();
            $table->foreignId('registrada_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('pagada_en')->nullable();
            $table->foreignId('pagada_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('anulada_en')->nullable();
            $table->foreignId('anulada_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();

            $table->index(['usuario_id', 'estado']);
            $table->index('estado');
        });

        DB::statement("ALTER TABLE multas ADD CONSTRAINT chk_multas_tipo CHECK (tipo IN ('atraso','deterioro','perdida','otro'))");
        DB::statement("ALTER TABLE multas ADD CONSTRAINT chk_multas_estado CHECK (estado IN ('pendiente','pagada','anulada'))");
        DB::statement("ALTER TABLE multas ADD CONSTRAINT chk_multas_origen CHECK (origen IN ('sistema','manual','importada'))");
        DB::statement('ALTER TABLE multas ADD CONSTRAINT chk_multas_monto CHECK (monto > 0)');

        DB::statement(<<<'SQL'
            INSERT INTO multas (
                usuario_id, prestamo_id, tipo, monto, estado, detalle, dias_atraso, origen,
                registrada_por_staff_id, pagada_en, pagada_por_staff_id, created_at, updated_at
            )
            SELECT
                usuario_id,
                id,
                'atraso',
                multa_monto,
                multa_estado,
                CONCAT('Devolución atrasada de "', libro_titulo, '"'),
                CASE
                    WHEN fecha_devolucion IS NOT NULL AND fecha_devolucion_real IS NOT NULL
                    THEN GREATEST(0, TIMESTAMPDIFF(DAY, fecha_devolucion, fecha_devolucion_real))
                END,
                'sistema',
                devuelto_por_staff_id,
                multa_pagada_en,
                multa_pagada_por_staff_id,
                COALESCE(fecha_devolucion_real, updated_at, NOW()),
                NOW()
            FROM prestamos
            WHERE multa_monto IS NOT NULL AND multa_monto > 0 AND multa_estado IS NOT NULL
        SQL);

        DB::statement('ALTER TABLE prestamos DROP CONSTRAINT chk_prestamos_multa_estado');

        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('multa_pagada_por_staff_id');
            $table->dropColumn(['multa_monto', 'multa_estado', 'multa_pagada_en', 'multa_pagada_por']);
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->unsignedInteger('multa_monto')->nullable();
            $table->string('multa_estado')->nullable();
            $table->timestamp('multa_pagada_en')->nullable();
            $table->string('multa_pagada_por')->nullable();
            $table->foreignId('multa_pagada_por_staff_id')->nullable()->constrained('staff')->nullOnDelete();
        });

        DB::statement("ALTER TABLE prestamos ADD CONSTRAINT chk_prestamos_multa_estado CHECK (multa_estado IS NULL OR multa_estado IN ('pendiente','pagada'))");

        // Solo vuelve lo que el esquema anterior podía representar: una multa de
        // atraso por préstamo, pendiente o pagada.
        DB::statement(<<<'SQL'
            UPDATE prestamos
            JOIN multas m ON m.prestamo_id = prestamos.id
            LEFT JOIN staff s ON s.id = m.pagada_por_staff_id
            SET
                prestamos.multa_monto = m.monto,
                prestamos.multa_estado = m.estado,
                prestamos.multa_pagada_en = m.pagada_en,
                prestamos.multa_pagada_por_staff_id = m.pagada_por_staff_id,
                prestamos.multa_pagada_por = s.nombre
            WHERE m.tipo = 'atraso' AND m.estado IN ('pendiente', 'pagada')
        SQL);

        Schema::dropIfExists('multas');
    }
};
