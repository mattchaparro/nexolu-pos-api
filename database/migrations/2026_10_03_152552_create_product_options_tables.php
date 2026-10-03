<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Opciones de elección por producto (salsas, toppings): grupos con mínimo y
// máximo de elecciones. Todo son tablas nuevas; sale_items no se altera (el
// legacy lo sigue leyendo), la elección queda en sale_item_options.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('product_id');
            $table->string('name', 80);
            $table->unsignedSmallInteger('min_choices')->default(0);
            $table->unsignedSmallInteger('max_choices')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('product_option_group_id');
            $table->string('name', 80);
            $table->decimal('extra_price', 12, 2)->default(0);
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('ingredient_quantity', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_option_group_id')->references('id')->on('product_option_groups')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->nullOnDelete();
        });

        // Foto de lo elegido: nombres y precios copiados, para que la comanda y
        // el recibo no cambien si luego se edita o borra la opción.
        Schema::create('sale_item_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('product_option_id')->nullable();
            $table->string('group_name', 80);
            $table->string('name', 80);
            $table->decimal('extra_price', 12, 2)->default(0);
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('ingredient_quantity', 12, 3)->nullable();
            $table->timestamps();

            $table->foreign('sale_item_id')->references('id')->on('sale_items')->cascadeOnDelete();
            $table->foreign('product_option_id')->references('id')->on('product_options')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_options');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_option_groups');
    }
};
