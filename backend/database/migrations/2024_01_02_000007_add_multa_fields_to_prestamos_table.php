<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->unsignedInteger('multa_monto')->nullable()->after('devuelto_por');
            $table->string('multa_estado')->nullable()->after('multa_monto');
            $table->timestamp('multa_pagada_en')->nullable()->after('multa_estado');
            $table->string('multa_pagada_por')->nullable()->after('multa_pagada_en');
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn(['multa_monto', 'multa_estado', 'multa_pagada_en', 'multa_pagada_por']);
        });
    }
};
