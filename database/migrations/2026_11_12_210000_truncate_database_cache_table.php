<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// La tabla `cache` (store «database») llegó a ~1,1 GB de los ~1,2 GB de la BD
// de producción: 8.361 filas, casi todas copias vencidas del listado de
// productos (~0,9 MB cada una, una por versión). Producción usa
// CACHE_STORE=redis desde finales de octubre y nada más escribe ni lee esa
// tabla; las únicas filas vigentes son claves `rememberForever` huérfanas
// (versiones de productos y motivos de movimiento) que redis ya reconstruye.
//
// Es solo caché: todo se regenera bajo demanda. TRUNCATE (y no DELETE) porque
// con innodb_file_per_table el espacio vuelve al disco al recrear el
// tablespace; un DELETE lo dejaría reservado dentro del .ibd.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cache', 'cache_locks'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
    }

    public function down(): void
    {
        // Sin reversa: son datos de caché y se regeneran solos.
    }
};
