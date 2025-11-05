<?php

namespace Database\Factories;

use App\Models\BlacklistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlacklistEntryFactory extends Factory
{
    protected $model = BlacklistEntry::class;

    public function definition(): array
    {
        $type = fake()->randomElement([BlacklistEntry::TYPE_EMAIL, BlacklistEntry::TYPE_DOMAIN]);

        return [
            'type' => $type,
            'value' => $type === BlacklistEntry::TYPE_EMAIL
                ? fake()->safeEmail()
                : fake()->domainName(),
            'reason' => fake()->sentence(),
            'source' => fake()->randomElement(['manual', 'import', 'auto']),
        ];
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => fake()->safeEmail(),
        ]);
    }

    public function domain(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => fake()->domainName(),
        ]);
    }
}
