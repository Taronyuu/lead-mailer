<?php

namespace Database\Factories;

use App\Models\WebsiteRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteRequirementFactory extends Factory
{
    protected $model = WebsiteRequirement::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'criteria' => [
                'min_pages' => 5,
                'platforms' => ['wordpress', 'shopify', 'woocommerce'],
                'required_keywords' => ['shop', 'store', 'product'],
            ],
            'is_active' => true,
            'priority' => 50,
        ];
    }

    public function ecommerce(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'E-commerce Websites',
            'description' => 'Websites selling products online',
            'criteria' => [
                'min_pages' => 10,
                'platforms' => ['shopify', 'woocommerce', 'magento'],
                'required_keywords' => ['shop', 'cart', 'product', 'buy'],
                'min_word_count' => 500,
            ],
            'priority' => 80,
        ]);
    }

    public function wordpress(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'WordPress Sites',
            'description' => 'Websites built with WordPress',
            'criteria' => [
                'platforms' => ['wordpress'],
                'min_pages' => 5,
            ],
            'priority' => 60,
        ]);
    }
}
