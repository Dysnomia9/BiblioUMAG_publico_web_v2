<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * En la versión PostgreSQL, acá se creaban índices de trigramas (pg_trgm) para las
     * búsquedas de texto parcial («calculo» en medio de un título). MySQL no tiene un
     * equivalente para `LIKE '%texto%'`: sus índices B-tree solo sirven para «empieza
     * con», y FULLTEXT busca palabras, no trozos de palabra.
     *
     * No hace falta: en MySQL la comparación sin tildes ni mayúsculas la hace la
     * collation utf8mb4_unicode_ci (sin la función translate() por fila que era lo
     * lento en PostgreSQL), y las búsquedas de libros buscan cada campo por separado
     * (App\Support\BusquedaLibros). Medido con 20.000 títulos: ver README.
     *
     * Se deja la migración (vacía) para que la lista de migraciones sea la misma en
     * las dos ramas.
     */
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
