<?php

namespace Database\Factories;

use App\Models\AiUnansweredQuestion;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiUnansweredQuestion> */
class AiUnansweredQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'pregunta' => $this->faker->sentence(),
            'respuesta' => $this->faker->sentence(),
            'revisada' => false,
        ];
    }
}
