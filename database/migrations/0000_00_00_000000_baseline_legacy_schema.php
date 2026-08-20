<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Marcador de que el esquema hasta este punto viene de
 * database/legacy-schema/schema.sql (ya incluye los patches de
 * database/legacy-schema/patches/, fusionados ahi) - no de una
 * migracion real ejecutada por Laravel. No crea ni altera nada:
 * `php artisan migrate:baseline` la marca como ya corrida en cada
 * ambiente sin ejecutar up(), para que las tablas que ya existen no se
 * intenten recrear.
 *
 * De aca en adelante, TODO cambio de esquema - tabla nueva o ALTER
 * sobre una tabla existente, incluida una que el legacy tambien usa
 * (ver docs/CUTOVER_TODO.md para el criterio de seguridad en ese caso
 * puntual) - es una migracion normal creada con `make:migration`, no
 * un patch de database/legacy-schema/patches/ ni un ALTER corrido a
 * mano por SSH.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intencionalmente vacio - el esquema ya existe (schema.sql).
    }

    public function down(): void
    {
        // Intencionalmente vacio - el baseline nunca se revierte.
    }
};
