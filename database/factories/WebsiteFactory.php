<?php

namespace Database\Factories;

use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    public function definition(): array
    {
        return [
            'url' => fake()->url(),
            'title' => fake()->optional()->company(),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
