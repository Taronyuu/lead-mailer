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
            'is_active' => false,
            'title' => fake()->optional()->company(),
            'description' => fake()->optional()->sentence(),
            'detected_platform' => null,
            'page_count' => 0,
            'word_count' => 0,
            'content_snapshot' => null,
            'meets_requirements' => false,
            'requirement_match_details' => null,
            'crawled_at' => null,
            'crawl_started_at' => null,
            'crawl_attempts' => 0,
            'crawl_error' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'title' => fake()->company(),
            'description' => fake()->sentence(),
            'detected_platform' => fake()->randomElement(['wordpress', 'shopify', 'woocommerce', 'wix', 'custom']),
            'page_count' => fake()->numberBetween(5, 50),
            'word_count' => fake()->numberBetween(500, 5000),
            'content_snapshot' => fake()->paragraphs(3, true),
            'crawled_at' => now(),
            'crawl_started_at' => now()->subMinutes(10),
        ]);
    }

    public function qualified(): static
    {
        return $this->active()->state(fn (array $attributes) => [
            'meets_requirements' => true,
            'requirement_match_details' => [
                'matched_rule' => 'ecommerce_sites',
                'score' => fake()->numberBetween(70, 100),
                'criteria_met' => ['has_products', 'active_business'],
            ],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'crawl_attempts' => 3,
            'crawl_error' => 'Connection timeout',
        ]);
    }
}
