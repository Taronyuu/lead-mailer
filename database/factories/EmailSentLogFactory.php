<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailSentLogFactory extends Factory
{
    protected $model = EmailSentLog::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'contact_id' => Contact::factory(),
            'smtp_credential_id' => SmtpCredential::factory(),
            'email_template_id' => EmailTemplate::factory(),
            'recipient_email' => fake()->safeEmail(),
            'recipient_name' => fake()->name(),
            'subject' => fake()->sentence(),
            'body' => fake()->paragraphs(2, true),
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now(),
            'error_message' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmailSentLog::STATUS_FAILED,
            'error_message' => fake()->randomElement([
                'SMTP connection failed',
                'Invalid email address',
                'Recipient rejected',
                'Rate limit exceeded',
            ]),
        ]);
    }
}
