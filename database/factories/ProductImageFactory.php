<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory();

        return [
            'product_id' => $product,
            'product_variant_id' => null,
            'business_id' => fn (array $attributes) => Product::find($attributes['product_id'])?->business_id,
            'disk' => 'public',
            'path' => fn (array $attributes) => "products/{$attributes['business_id']}/{$attributes['product_id']}/".fake()->uuid().'.webp',
            'thumbnail_path' => fn (array $attributes) => str_replace('.webp', '_thumb.webp', $attributes['path']),
            'alt' => null,
            'sort_order' => 0,
        ];
    }
}
