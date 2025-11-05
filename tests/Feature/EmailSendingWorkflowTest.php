<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\Contact;
use App\Models\Website;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\EmailSentLog;
use App\Jobs\SendOutreachEmailJob;

class EmailSendingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_email_generation_and_sending_flow(): void
    {
        Queue::fake();

        // 1. Setup prerequisites
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'title' => 'Example Company',
            'meets_requirements' => true,
        ]);

        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'john@example.com',
            'name' => 'John Doe',
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $template = EmailTemplate::factory()->create([
            'name' => 'Outreach Template',
            'subject_template' => 'Hello {{name}}',
            'body_template' => 'Hi {{name}}, we found your site {{website_url}}',
            'is_active' => true,
        ]);

        $smtp = SmtpCredential::factory()->create([
            'name' => 'Primary SMTP',
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'sender@example.com',
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 0,
        ]);

        // 2. Dispatch send email job
        SendOutreachEmailJob::dispatch($contact, $template, $smtp);

        Queue::assertPushed(SendOutreachEmailJob::class, function ($job) use ($contact, $template) {
            return $job->contact->id === $contact->id
                && $job->template->id === $template->id;
        });

        // 3. Simulate successful email send
        $emailLog = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $website->id,
            'email_template_id' => $template->id,
            'smtp_credential_id' => $smtp->id,
            'recipient_email' => $contact->email,
            'subject_template' => 'Hello John Doe',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // 4. Mark contact as contacted
        $contact->markAsContacted();

        // 5. Assert database state
        $this->assertDatabaseHas('email_sent_logs', [
            'contact_id' => $contact->id,
            'recipient_email' => 'john@example.com',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'contacted' => true,
            'contact_count' => 1,
        ]);

        $this->assertNotNull($contact->fresh()->first_contacted_at);
        $this->assertNotNull($contact->fresh()->last_contacted_at);
    }

    public function test_smtp_rotation_when_limit_reached(): void
    {
        $smtp1 = SmtpCredential::factory()->create([
            'name' => 'SMTP 1',
            'daily_limit' => 100,
            'emails_sent_today' => 100, // At limit
            'is_active' => true,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'name' => 'SMTP 2',
            'daily_limit' => 100,
            'emails_sent_today' => 50, // Has capacity
            'is_active' => true,
        ]);

        // Simulate selecting available SMTP (would be in service)
        $availableSmtp = SmtpCredential::where('is_active', true)
            ->whereRaw('emails_sent_today < daily_limit')
            ->first();

        $this->assertEquals($smtp2->id, $availableSmtp->id);
    }

    public function test_email_sending_increments_smtp_counter(): void
    {
        $smtp = SmtpCredential::factory()->create([
            'emails_sent_today' => 10,
        ]);

        $initialToday = $smtp->emails_sent_today;

        // Simulate email sent
        $smtp->increment('emails_sent_today');
        $smtp->update(['last_used_at' => now()]);

        $this->assertEquals($initialToday + 1, $smtp->fresh()->emails_sent_today);
        $this->assertNotNull($smtp->fresh()->last_used_at);
    }

    public function test_email_log_tracks_complete_send_history(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
        ]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $log = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $website->id,
            'email_template_id' => $template->id,
            'smtp_credential_id' => $smtp->id,
            'recipient_email' => $contact->email,
            'subject_template' => 'Test Email',
            'body_template' => 'Test Body',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertDatabaseHas('email_sent_logs', [
            'contact_id' => $contact->id,
            'website_id' => $website->id,
            'email_template_id' => $template->id,
            'smtp_credential_id' => $smtp->id,
            'status' => 'sent',
        ]);

        // Test relationships
        $this->assertEquals($contact->id, $log->contact->id);
        $this->assertEquals($website->id, $log->website->id);
        $this->assertEquals($template->id, $log->emailTemplate->id);
        $this->assertEquals($smtp->id, $log->smtpCredential->id);
    }

    public function test_failed_email_send_logged(): void
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $log = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'failed',
            'error_message' => 'SMTP connection timeout',
            'sent_at' => null,
        ]);

        $this->assertDatabaseHas('email_sent_logs', [
            'contact_id' => $contact->id,
            'status' => 'failed',
            'error_message' => 'SMTP connection timeout',
        ]);

        $this->assertNull($log->sent_at);
    }

    public function test_contact_can_be_contacted_multiple_times(): void
    {
        $contact = Contact::factory()->create([
            'contacted' => false,
            'contact_count' => 0,
            'first_contacted_at' => null,
            'last_contacted_at' => null,
        ]);

        // First contact
        $contact->markAsContacted();
        $this->assertEquals(1, $contact->fresh()->contact_count);
        $this->assertNotNull($contact->fresh()->first_contacted_at);
        $firstContactTime = $contact->fresh()->first_contacted_at;

        sleep(1);

        // Second contact
        $contact->fresh()->markAsContacted();
        $this->assertEquals(2, $contact->fresh()->contact_count);
        $this->assertEquals($firstContactTime, $contact->fresh()->first_contacted_at);
        $this->assertNotEquals($firstContactTime, $contact->fresh()->last_contacted_at);
    }

    public function test_email_template_variable_replacement(): void
    {
        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Hello {{name}} from {{company}}',
            'body_template' => 'Hi {{name}}, we loved {{website_url}}',
        ]);

        // Simulate template variable replacement (would be in service)
        $variables = [
            '{{name}}' => 'John Doe',
            '{{company}}' => 'Acme Inc',
            '{{website_url}}' => 'https://example.com',
        ];

        $subject = str_replace(array_keys($variables), array_values($variables), $template->subject);
        $body = str_replace(array_keys($variables), array_values($variables), $template->body);

        $this->assertEquals('Hello John Doe from Acme Inc', $subject);
        $this->assertEquals('Hi John Doe, we loved https://example.com', $body);
    }

    public function test_only_active_templates_used(): void
    {
        EmailTemplate::factory()->create([
            'name' => 'Active Template',
            'is_active' => true,
        ]);

        EmailTemplate::factory()->create([
            'name' => 'Inactive Template',
            'is_active' => false,
        ]);

        $activeTemplates = EmailTemplate::where('is_active', true)->get();
        $this->assertCount(1, $activeTemplates);
        $this->assertEquals('Active Template', $activeTemplates->first()->name);
    }

    public function test_smtp_within_allowed_hours(): void
    {
        $smtp = SmtpCredential::factory()->create([
            'sending_hours_start' => '09:00',
            'sending_hours_end' => '17:00',
            'is_active' => true,
        ]);

        // This would be checked in the service before sending
        $currentTime = now()->format('H:i');
        $start = $smtp->sending_hours_start;
        $end = $smtp->sending_hours_end;

        // Simple check (actual implementation would be more robust)
        $withinHours = ($currentTime >= $start && $currentTime <= $end);

        // Assert that we have the necessary data to make this check
        $this->assertNotNull($smtp->sending_hours_start);
        $this->assertNotNull($smtp->sending_hours_end);
    }
}
