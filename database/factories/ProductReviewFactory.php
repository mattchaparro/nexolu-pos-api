<?php

namespace Database\Factories;

use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rating' => $this->faker->numberBetween(ProductReview::MIN_RATING, ProductReview::MAX_RATING),
            'comment' => $this->faker->sentence(),
            'author_name' => $this->faker->name(),
            'status' => ProductReview::STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => ProductReview::STATUS_APPROVED,
            'moderated_at' => now(),
        ]);
    }
}
