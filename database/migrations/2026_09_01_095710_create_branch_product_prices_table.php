<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precio distinto por sede, sobre el mismo producto.
 *
 * El caso real: un local en un centro comercial cobra mas caro que el punto
 * de fabrica. No es otro producto - es el mismo, con otro precio segun donde
 * se venda, y tiene que seguir siendo el mismo para el inventario, las
 * recetas, los reportes y la tienda.
 *
 * Por eso una tabla de overrides y no una columna price por sede ni un
 * producto duplicado: la fila SOLO existe donde el precio se aparta del
 * catalogo. Un negocio monosede nunca tiene una fila aqui, y un negocio con
 * cinco sedes que cambia el precio en una tiene exactamente una.
 *
 * Ausencia = "usa el precio del catalogo". Un precio de 0 SI es un precio
 * (producto de cortesia), asi que la ausencia se representa borrando la fila,
 * nunca guardando cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // Exactamente una de las dos, igual que en branch_stocks.
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('price', 14, 2);

            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->unique(['branch_id', 'product_variant_id']);
            $table->index(['business_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_prices');
    }
};
