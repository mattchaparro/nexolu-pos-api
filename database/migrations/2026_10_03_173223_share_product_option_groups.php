<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Los grupos de opciones pasan a ser de la biblioteca del negocio y se enlazan
// a N productos por una tabla pivote (las salsas se crean una vez y sirven a
// x6, x12...). Los grupos que ya existían quedan enlazados a su producto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_group_product', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_option_group_id');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['product_id', 'product_option_group_id'], 'pogp_primary');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_option_group_id', 'pogp_group_foreign')->references('id')->on('product_option_groups')->cascadeOnDelete();
        });

        DB::statement('INSERT INTO product_option_group_product (product_id, product_option_group_id, sort_order)
            SELECT product_id, id, sort_order FROM product_option_groups');

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->after('business_id');
        });

        DB::statement('UPDATE product_option_groups g
            JOIN (SELECT product_option_group_id, MIN(product_id) AS product_id FROM product_option_group_product GROUP BY product_option_group_id) p
              ON p.product_option_group_id = g.id
            SET g.product_id = p.product_id');
        DB::statement('DELETE FROM product_option_groups WHERE product_id IS NULL');

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::dropIfExists('product_option_group_product');
    }
};
