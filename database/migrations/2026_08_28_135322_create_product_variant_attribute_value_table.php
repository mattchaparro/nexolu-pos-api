<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot variante <-> valor de atributo elegido. product_attribute_id esta
 * denormalizado (se deduce via product_attribute_value_id) para poder
 * garantizar con un unique que una variante no tenga dos valores del
 * MISMO atributo (ej. Talla=S y Talla=M a la vez). La unicidad de la
 * combinacion COMPLETA (que no existan dos variantes con exactamente el
 * mismo set de valores) no es expresable como constraint SQL simple - se
 * valida en ProductService::extractVariants().
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nombres de constraint explicitos y cortos: los autogenerados por
        // Laravel a partir del nombre completo de esta tabla + columna
        // superan el limite de 64 caracteres de MySQL para identificadores
        // (ver 'product_variant_attribute_value_product_attribute_value_id_foreign').
        Schema::create('product_variant_attribute_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained(indexName: 'pvav_variant_id_foreign')->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained(indexName: 'pvav_attribute_id_foreign')->cascadeOnDelete();
            $table->foreignId('product_attribute_value_id')->constrained(indexName: 'pvav_attribute_value_id_foreign')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_variant_id', 'product_attribute_id'], 'pvav_variant_attribute_unique');
            $table->index('product_attribute_value_id', 'pvav_attribute_value_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_value');
    }
};
