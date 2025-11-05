<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'phone' => fake()->optional()->phoneNumber(),
            'position' => fake()->optional()->randomElement(['CEO', 'Founder', 'Marketing Manager', 'Owner', 'Director']),
            'source_type' => fake()->randomElement([
                Contact::SOURCE_CONTACT_PAGE,
                Contact::SOURCE_ABOUT_PAGE,
                Contact::SOURCE_TEAM_PAGE,
                Contact::SOURCE_FOOTER,
                Contact::SOURCE_HEADER,
            ]),
            'source_url' => fake()->url(),
            'priority' => 50,
            'is_validated' => false,
            'is_valid' => false,
            'validation_error' => null,
            'validated_at' => null,
            'contacted' => false,
            'first_contacted_at' => null,
            'last_contacted_at' => null,
            'contact_count' => 0,
        ];
    }

    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_validated' => true,
            'is_valid' => true,
            'validated_at' => now(),
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_validated' => true,
            'is_valid' => false,
            'validation_error' => 'Invalid MX records',
            'validated_at' => now(),
        ]);
    }

    public function contacted(): static
    {
        return $this->validated()->state(fn (array $attributes) => [
            'contacted' => true,
            'first_contacted_at' => now()->subDays(rand(1, 30)),
            'last_contacted_at' => now()->subDays(rand(1, 30)),
            'contact_count' => fake()->numberBetween(1, 3),
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => fake()->numberBetween(75, 100),
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'position' => 'CEO',
        ]);
    }
}
