<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nota libre de una cuenta abierta: quien esta en la mesa ("Juan y su
     * familia", "los de la camiseta roja").
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('notes', 255)->nullable()->after('customer_identification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
