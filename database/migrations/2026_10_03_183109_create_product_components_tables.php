<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Combos: un producto armado con otros productos e insumos en cantidades fijas
// (ej. Combo = 1 Coca-Cola + 100 g de papas). Todo son tablas nuevas. La venta
// guarda en sale_item_components lo que se descontó, para poder devolverlo
// aunque luego se edite o borre el combo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('component_product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('component_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });

        Schema::create('sale_item_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('component_product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->string('name', 120);
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->foreign('sale_item_id')->references('id')->on('sale_items')->cascadeOnDelete();
            $table->foreign('component_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_components');
        Schema::dropIfExists('product_components');
    }
};
