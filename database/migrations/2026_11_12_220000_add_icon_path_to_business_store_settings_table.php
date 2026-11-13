<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Un segundo logo. `logo_path` es el logo de la marca, normalmente horizontal
// con el nombre escrito (cabecera de la tienda). `icon_path` es el ícono
// cuadrado: va en el círculo de la página de enlaces, donde un logo horizontal
// se recortaba. Si falta, cada pantalla cae al otro (ver LinkPageService y
// StorefrontSettingsResource).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->string('icon_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('business_store_settings', function (Blueprint $table) {
            $table->dropColumn('icon_path');
        });
    }
};
