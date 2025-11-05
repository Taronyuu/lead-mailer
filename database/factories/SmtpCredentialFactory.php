<?php

namespace Database\Factories;

use App\Models\SmtpCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmtpCredentialFactory extends Factory
{
    protected $model = SmtpCredential::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company() . ' SMTP',
            'host' => 'smtp.' . fake()->domainName(),
            'port' => 587,
            'encryption' => 'tls',
            'username' => fake()->email(),
            'password' => fake()->password(),
            'from_address' => fake()->email(),
            'from_name' => fake()->company(),
            'is_active' => true,
            'daily_limit' => fake()->randomElement([10, 20, 50, 100]),
            'emails_sent_today' => 0,
            'last_reset_date' => today(),
            'last_used_at' => null,
            'success_count' => 0,
            'failure_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'emails_sent_today' => fake()->numberBetween(0, 5),
            'last_used_at' => now()->subHours(rand(1, 24)),
            'success_count' => fake()->numberBetween(10, 100),
            'failure_count' => fake()->numberBetween(0, 5),
        ]);
    }

    public function nearLimit(): static
    {
        return $this->state(fn (array $attributes) => [
            'emails_sent_today' => $attributes['daily_limit'] - 2,
            'last_used_at' => now(),
        ]);
    }

    public function atLimit(): static
    {
        return $this->state(fn (array $attributes) => [
            'emails_sent_today' => $attributes['daily_limit'],
            'last_used_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
