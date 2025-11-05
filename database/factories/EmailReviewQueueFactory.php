<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\EmailReviewQueue;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailReviewQueueFactory extends Factory
{
    protected $model = EmailReviewQueue::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'contact_id' => Contact::factory(),
            'email_template_id' => EmailTemplate::factory(),
            'generated_subject' => fake()->sentence(),
            'generated_body' => fake()->paragraphs(2, true),
            'generated_preheader' => fake()->sentence(),
            'status' => EmailReviewQueue::STATUS_PENDING,
            'priority' => 50,
            'reviewed_at' => null,
            'review_notes' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'reviewed_at' => now(),
            'review_notes' => 'Approved for sending',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmailReviewQueue::STATUS_REJECTED,
            'reviewed_at' => now(),
            'review_notes' => fake()->randomElement([
                'Content not appropriate',
                'Duplicate recipient',
                'Invalid contact information',
            ]),
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => fake()->numberBetween(75, 100),
        ]);
    }
}
