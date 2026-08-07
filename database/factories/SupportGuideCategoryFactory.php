<?php

namespace Database\Factories;

use App\Models\SupportGuideCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportGuideCategory>
 */
class SupportGuideCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'icon' => 'menu_book',
            'sort_order' => 0,
            'is_active' => true,
            'visible_to' => 'all',
            'show_in_superadmin_help' => true,
        ];
    }
}
