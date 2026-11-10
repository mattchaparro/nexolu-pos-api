<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Un grupo repetible deja elegir la misma opción varias veces (doble porción
// de licor) y en Vender se muestra con contador en lugar de marcar/desmarcar.
// Todos los grupos existentes quedan sin repetir (las salsas siguen igual),
// salvo «Tragos» de Las Banquitas, que pidió porciones repetidas: queda
// repetible y con hasta 3 porciones por granizado.
return new class extends Migration
{
    private const LAS_BANQUITAS_ID = 24;

    public function up(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->boolean('allow_repeat')->default(false)->after('max_choices');
        });

        DB::table('product_option_groups')
            ->where('business_id', self::LAS_BANQUITAS_ID)
            ->where('name', 'Tragos')
            ->update(['allow_repeat' => true, 'max_choices' => DB::raw('GREATEST(max_choices, 3)')]);
    }

    public function down(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropColumn('allow_repeat');
        });
    }
};
