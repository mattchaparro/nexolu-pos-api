<?php

namespace Database\Factories;

use App\Models\SupportGuideArticle;
use App\Models\SupportGuideCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportGuideArticle>
 */
class SupportGuideArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'support_guide_category_id' => SupportGuideCategory::factory(),
            'slug' => Str::slug($title),
            'title' => $title,
            'summary' => fake()->sentence(),
            'body' => fake()->paragraphs(3, true),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
