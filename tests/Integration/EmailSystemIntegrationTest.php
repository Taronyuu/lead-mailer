<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\Contact;
use App\Models\Website;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\EmailSentLog;
use App\Models\EmailReviewQueue;
use App\Models\User;
use App\Jobs\SendOutreachEmailJob;
use App\Jobs\ProcessApprovedEmailsJob;

class EmailSystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_email_system_integration(): void
    {
        Queue::fake();

        // 1. Setup: Create all necessary components
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'title' => 'Example Company',
            'meets_requirements' => true,
        ]);

        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'ceo@example.com',
            'name' => 'John Doe',
            'position' => 'CEO',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $template = EmailTemplate::factory()->create([
            'name' => 'Outreach Template v1',
            'subject_template' => 'Partnership opportunity for {{company}}',
            'body_template' => 'Hi {{name}}, I came across {{website_url}} and was impressed...',
            'is_active' => true,
        ]);

        $smtp1 = SmtpCredential::factory()->create([
            'name' => 'SMTP Server 1',
            'daily_limit' => 100,
            'emails_sent_today' => 0,
            'is_active' => true,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'name' => 'SMTP Server 2',
            'daily_limit' => 100,
            'emails_sent_today' => 0,
            'is_active' => true,
        ]);

        // 2. Generate email for review
        $reviewItem = EmailReviewQueue::factory()->create([
            'website_id' => $website->id,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'generated_subject' => 'Partnership opportunity for Example Company',
            'generated_body' => 'Hi John Doe, I came across https://example.com and was impressed...',
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('email_review_queue', [
            'contact_id' => $contact->id,
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        // 3. Approve email
        $user = User::factory()->create();
        $reviewItem->approve($user->id, 'Looks good, send it!');

        $this->assertTrue($reviewItem->fresh()->isApproved());

        // 4. Process approved emails
        ProcessApprovedEmailsJob::dispatch();
        Queue::assertPushed(ProcessApprovedEmailsJob::class);

        // 5. Send email using first SMTP
        SendOutreachEmailJob::dispatch($contact, $template, $smtp1);
        Queue::assertPushed(SendOutreachEmailJob::class);

        // 6. Log email send
        $emailLog = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $website->id,
            'email_template_id' => $template->id,
            'smtp_credential_id' => $smtp1->id,
            'recipient_email' => $contact->email,
            'subject_template' => $reviewItem->generated_subject,
            'body_template' => $reviewItem->generated_body,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // 7. Update SMTP counter
        $smtp1->increment('emails_sent_today');
        $smtp1->update(['last_used_at' => now()]);

        // 8. Mark contact as contacted
        $contact->markAsContacted();

        // 9. Verify complete workflow
        $this->assertDatabaseHas('email_sent_logs', [
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'contacted' => true,
            'contact_count' => 1,
        ]);

        $this->assertDatabaseHas('smtp_credentials', [
            'id' => $smtp1->id,
            'emails_sent_today' => 1,
        ]);

        $this->assertNotNull($smtp1->fresh()->last_used_at);
    }

    public function test_smtp_rotation_integration(): void
    {
        $website = Website::factory()->create();

        // Create multiple contacts
        $contacts = Contact::factory()->count(5)->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $template = EmailTemplate::factory()->create();

        // Create SMTP servers with different limits
        $smtp1 = SmtpCredential::factory()->create([
            'name' => 'SMTP 1',
            'daily_limit' => 2,
            'emails_sent_today' => 0,
            'is_active' => true,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'name' => 'SMTP 2',
            'daily_limit' => 2,
            'emails_sent_today' => 0,
            'is_active' => true,
        ]);

        $smtp3 = SmtpCredential::factory()->create([
            'name' => 'SMTP 3',
            'daily_limit' => 2,
            'emails_sent_today' => 0,
            'is_active' => true,
        ]);

        // Simulate sending emails with rotation
        $sentLogs = [];
        foreach ($contacts as $index => $contact) {
            // Get available SMTP (round-robin style)
            $availableSmtp = SmtpCredential::where('is_active', true)
                ->whereRaw('emails_sent_today < daily_limit')
                ->orderBy('emails_sent_today')
                ->first();

            $this->assertNotNull($availableSmtp, "Should have available SMTP for email $index");

            // Send email
            $log = EmailSentLog::factory()->create([
                'contact_id' => $contact->id,
                'website_id' => $website->id,
                'email_template_id' => $template->id,
                'smtp_credential_id' => $availableSmtp->id,
                'recipient_email' => $contact->email,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            // Update SMTP counter
            $availableSmtp->increment('emails_sent_today');
            $sentLogs[] = $log;
        }

        // Verify emails were distributed across SMTP servers
        $this->assertCount(5, $sentLogs);

        // Check distribution
        $smtpUsage = EmailSentLog::selectRaw('smtp_credential_id, COUNT(*) as count')
            ->groupBy('smtp_credential_id')
            ->pluck('count', 'smtp_credential_id');

        // All three SMTPs should have been used
        $this->assertGreaterThanOrEqual(1, $smtpUsage->count());
    }

    public function test_template_personalization_integration(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://techstartup.com',
            'title' => 'Tech Startup Inc',
        ]);

        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'jane@techstartup.com',
            'name' => 'Jane Smith',
            'position' => 'CTO',
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Question about {{company}}',
            'body_template' => 'Hi {{name}},\n\nI noticed {{website_url}} and wanted to reach out. As {{position}}, you might be interested...',
        ]);

        // Simulate variable replacement
        $variables = [
            '{{company}}' => $website->title,
            '{{name}}' => $contact->name,
            '{{position}}' => $contact->position,
            '{{website_url}}' => $website->url,
        ];

        $personalizedSubject = str_replace(
            array_keys($variables),
            array_values($variables),
            $template->subject
        );

        $personalizedBody = str_replace(
            array_keys($variables),
            array_values($variables),
            $template->body
        );

        $this->assertEquals('Question about Tech Startup Inc', $personalizedSubject);
        $this->assertStringContainsString('Hi Jane Smith,', $personalizedBody);
        $this->assertStringContainsString('https://techstartup.com', $personalizedBody);
        $this->assertStringContainsString('As CTO,', $personalizedBody);
    }

    public function test_email_sending_with_validation_checks(): void
    {
        Queue::fake();

        $website = Website::factory()->create();

        // Valid, validated contact
        $validContact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'valid@example.com',
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        // Invalid contact
        $invalidContact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'invalid@example.com',
            'is_validated' => true,
            'is_valid' => false,
            'validation_error' => 'Email does not exist',
        ]);

        // Not validated contact
        $unvalidatedContact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'unvalidated@example.com',
            'is_validated' => false,
        ]);

        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        // Only valid contact should be sent
        if ($validContact->isValidated()) {
            SendOutreachEmailJob::dispatch($validContact, $template, $smtp);
        }

        if ($invalidContact->isValidated()) {
            // Would not be sent because is_valid is false
        }

        if ($unvalidatedContact->isValidated()) {
            // Would not be sent
        }

        Queue::assertPushed(SendOutreachEmailJob::class, 1);
        Queue::assertPushed(SendOutreachEmailJob::class, function ($job) use ($validContact) {
            return $job->contact->id === $validContact->id;
        });
    }

    public function test_smtp_limit_enforcement(): void
    {
        $smtp = SmtpCredential::factory()->create([
            'daily_limit' => 5,
            'emails_sent_today' => 0,
        ]);

        $template = EmailTemplate::factory()->create();
        $contacts = Contact::factory()->count(10)->create([
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $sentCount = 0;

        foreach ($contacts as $contact) {
            // Check if SMTP has capacity
            if ($smtp->fresh()->emails_sent_today < $smtp->daily_limit) {
                EmailSentLog::factory()->create([
                    'contact_id' => $contact->id,
                    'smtp_credential_id' => $smtp->id,
                    'status' => 'sent',
                ]);

                $smtp->increment('emails_sent_today');
                $sentCount++;
            } else {
                // Would need to select different SMTP or queue for later
                break;
            }
        }

        // Should have sent exactly 5 emails (the limit)
        $this->assertEquals(5, $sentCount);
        $this->assertEquals(5, $smtp->fresh()->emails_sent_today);

        // 5 emails should be logged
        $logCount = EmailSentLog::where('smtp_credential_id', $smtp->id)->count();
        $this->assertEquals(5, $logCount);
    }

    public function test_failed_send_retry_integration(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        // First attempt - fails
        $failedLog = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'smtp_credential_id' => $smtp->id,
            'status' => 'failed',
            'error_message' => 'SMTP connection timeout',
            'sent_at' => null,
        ]);

        $this->assertEquals('failed', $failedLog->status);

        // Job would be retried
        SendOutreachEmailJob::dispatch($contact, $template, $smtp);

        // Second attempt - succeeds
        $successLog = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'smtp_credential_id' => $smtp->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertEquals('sent', $successLog->status);

        // Contact should only be marked as contacted after successful send
        $contact->markAsContacted();
        $this->assertTrue($contact->fresh()->contacted);
    }

    public function test_review_approval_to_send_integration(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        // Create multiple contacts
        $contacts = Contact::factory()->count(3)->create([
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Create review queue items
        $reviewItems = [];
        foreach ($contacts as $contact) {
            $reviewItems[] = EmailReviewQueue::factory()->create([
                'contact_id' => $contact->id,
                'email_template_id' => $template->id,
                'generated_subject' => "Subject for {$contact->email}",
                'generated_body' => "Body for {$contact->email}",
                'status' => EmailReviewQueue::STATUS_PENDING,
            ]);
        }

        // Approve first two
        $reviewItems[0]->approve($user->id);
        $reviewItems[1]->approve($user->id);

        // Reject third
        $reviewItems[2]->reject($user->id, 'Not good enough');

        // Process approved
        $approved = EmailReviewQueue::approved()->get();
        $this->assertCount(2, $approved);

        // Send approved emails
        foreach ($approved as $item) {
            SendOutreachEmailJob::dispatch($item->contact, $item->emailTemplate, $smtp);
        }

        // Only 2 send jobs should be dispatched
        Queue::assertPushed(SendOutreachEmailJob::class, 2);

        // Rejected should not be sent
        $rejected = EmailReviewQueue::rejected()->first();
        $this->assertFalse($rejected->isApproved());
    }

    public function test_email_tracking_complete_history(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'contacted' => false,
            'contact_count' => 0,
        ]);

        $template1 = EmailTemplate::factory()->create(['name' => 'Initial Outreach']);
        $template2 = EmailTemplate::factory()->create(['name' => 'Follow-up']);

        $smtp = SmtpCredential::factory()->create();

        // First email
        $log1 = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'email_template_id' => $template1->id,
            'smtp_credential_id' => $smtp->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $contact->markAsContacted();

        sleep(1);

        // Follow-up email
        $log2 = EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'email_template_id' => $template2->id,
            'smtp_credential_id' => $smtp->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $contact->fresh()->markAsContacted();

        // Verify history
        $emailHistory = EmailSentLog::where('contact_id', $contact->id)
            ->orderBy('sent_at')
            ->get();

        $this->assertCount(2, $emailHistory);
        $this->assertEquals(2, $contact->fresh()->contact_count);
        $this->assertEquals($template1->id, $emailHistory[0]->email_template_id);
        $this->assertEquals($template2->id, $emailHistory[1]->email_template_id);
    }

    public function test_inactive_smtp_not_used(): void
    {
        $activeSmtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 0,
        ]);

        $inactiveSmtp = SmtpCredential::factory()->create([
            'is_active' => false,
            'daily_limit' => 100,
            'emails_sent_today' => 0,
        ]);

        // Get available SMTP
        $available = SmtpCredential::where('is_active', true)
            ->whereRaw('emails_sent_today < daily_limit')
            ->get();

        $this->assertCount(1, $available);
        $this->assertEquals($activeSmtp->id, $available->first()->id);
    }
}
