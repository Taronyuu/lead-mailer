<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'subject_template' => 'Partnership Opportunity with {{website_url}}',
            'body_template' => "Hi {{contact_name}},\n\nI noticed your website {{website_url}} and wanted to reach out about a potential partnership opportunity.\n\nBest regards,\n{{from_name}}",
            'preheader' => 'Exciting partnership opportunity',
            'is_active' => true,
            'usage_count' => 0,
            'available_variables' => [
                'website_url',
                'contact_name',
                'from_name',
                'platform',
            ],
            'ai_enabled' => false,
            'ai_instructions' => null,
            'ai_tone' => EmailTemplate::TONE_PROFESSIONAL,
            'ai_max_tokens' => 500,
            'metadata' => null,
        ];
    }

    public function withAI(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_enabled' => true,
            'ai_instructions' => 'Create a personalized outreach email for a potential business partnership',
            'ai_tone' => fake()->randomElement([
                EmailTemplate::TONE_PROFESSIONAL,
                EmailTemplate::TONE_FRIENDLY,
                EmailTemplate::TONE_CASUAL,
            ]),
            'ai_max_tokens' => 500,
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'usage_count' => fake()->numberBetween(10, 100),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
