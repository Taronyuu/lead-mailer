<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\EmailReviewQueue;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use App\Services\EmailSendingService;
use App\Services\EmailTemplateService;
use App\Services\ReviewQueueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ReviewQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReviewQueueService $service;
    protected $templateServiceMock;
    protected $emailServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateServiceMock = Mockery::mock(EmailTemplateService::class);
        $this->emailServiceMock = Mockery::mock(EmailSendingService::class);

        $this->service = new ReviewQueueService(
            $this->templateServiceMock,
            $this->emailServiceMock
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_creates_review_queue_entry()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->templateServiceMock
            ->shouldReceive('render')
            ->once()
            ->with($template, $website, $contact)
            ->andReturn([
                'subject_template' => 'Generated Subject',
                'body_template' => 'Generated Body',
                'preheader' => 'Generated Preheader',
            ]);

        $entry = $this->service->createReviewEntry(
            $contact,
            $template,
            $smtp,
            75,
            'High priority contact'
        );

        $this->assertInstanceOf(EmailReviewQueue::class, $entry);
        $this->assertEquals($website->id, $entry->website_id);
        $this->assertEquals($contact->id, $entry->contact_id);
        $this->assertEquals($template->id, $entry->email_template_id);
        $this->assertEquals($smtp->id, $entry->smtp_credential_id);
        $this->assertEquals('Generated Subject', $entry->generated_subject);
        $this->assertEquals('Generated Body', $entry->generated_body);
        $this->assertEquals('Generated Preheader', $entry->generated_preheader);
        $this->assertEquals(EmailReviewQueue::STATUS_PENDING, $entry->status);
        $this->assertEquals(75, $entry->priority);
        $this->assertEquals('High priority contact', $entry->notes);
    }

    /** @test */
    public function it_creates_review_entry_without_smtp()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn([
                'subject_template' => 'Subject',
                'body_template' => 'Body',
                'preheader' => null,
            ]);

        $entry = $this->service->createReviewEntry($contact, $template);

        $this->assertNull($entry->smtp_credential_id);
        $this->assertEquals(50, $entry->priority); // Default priority
        $this->assertNull($entry->notes);
    }

    /** @test */
    public function it_approves_review_queue_entry()
    {
        Log::shouldReceive('info')->once();
        Carbon::setTestNow('2024-01-15 10:00:00');

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $result = $this->service->approve($entry, 'Looks good');

        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $result->status);
        $this->assertEquals('2024-01-15 10:00:00', $result->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertEquals('Looks good', $result->review_notes);
    }

    /** @test */
    public function it_approves_entry_without_reviewer_notes()
    {
        Log::shouldReceive('info')->once();

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $result = $this->service->approve($entry);

        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $result->status);
        $this->assertNull($result->review_notes);
    }

    /** @test */
    public function it_applies_modifications_when_approving()
    {
        Log::shouldReceive('info')->once();

        $entry = EmailReviewQueue::factory()->create([
            'generated_subject' => 'Original Subject',
            'generated_body' => 'Original Body',
            'generated_preheader' => 'Original Preheader',
        ]);

        $modifications = [
            'subject_template' => 'Modified Subject',
            'body_template' => 'Modified Body',
            'preheader' => 'Modified Preheader',
        ];

        $result = $this->service->approve($entry, 'Modified', $modifications);

        $this->assertEquals('Modified Subject', $result->generated_subject);
        $this->assertEquals('Modified Body', $result->generated_body);
        $this->assertEquals('Modified Preheader', $result->generated_preheader);
        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $result->status);
    }

    /** @test */
    public function it_applies_partial_modifications()
    {
        Log::shouldReceive('info')->once();

        $entry = EmailReviewQueue::factory()->create([
            'generated_subject' => 'Original Subject',
            'generated_body' => 'Original Body',
        ]);

        $modifications = [
            'subject_template' => 'Modified Subject',
            // body not modified
        ];

        $result = $this->service->approve($entry, null, $modifications);

        $this->assertEquals('Modified Subject', $result->generated_subject);
        $this->assertEquals('Original Body', $result->generated_body);
    }

    /** @test */
    public function it_rejects_review_queue_entry()
    {
        Log::shouldReceive('info')->once();
        Carbon::setTestNow('2024-01-15 10:00:00');

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $result = $this->service->reject($entry, 'Content needs improvement');

        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $result->status);
        $this->assertEquals('2024-01-15 10:00:00', $result->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertEquals('Content needs improvement', $result->review_notes);
    }

    /** @test */
    public function it_sends_approved_email_successfully()
    {
        Log::shouldReceive('info')->once();
        Carbon::setTestNow('2024-01-15 10:00:00');

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'smtp_credential_id' => $smtp->id,
        ]);

        $this->emailServiceMock
            ->shouldReceive('send')
            ->once()
            ->with($contact, $template, $smtp)
            ->andReturn([
                'success' => true,
                'log_id' => 123,
            ]);

        $result = $this->service->sendApproved($entry);

        $this->assertTrue($result['success']);
        $this->assertEquals($entry->id, $result['entry_id']);
        $this->assertEquals(123, $result['log_id']);
        $this->assertEquals(EmailReviewQueue::STATUS_SENT, $entry->fresh()->status);
        $this->assertEquals('2024-01-15 10:00:00', $entry->fresh()->sent_at->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_fails_to_send_non_approved_entry()
    {
        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $result = $this->service->sendApproved($entry);

        $this->assertFalse($result['success']);
        $this->assertEquals('Entry is not approved', $result['error']);
    }

    /** @test */
    public function it_fails_to_send_already_sent_entry()
    {
        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_SENT,
        ]);

        $result = $this->service->sendApproved($entry);

        $this->assertFalse($result['success']);
        $this->assertEquals('Entry has already been sent', $result['error']);
    }

    /** @test */
    public function it_handles_email_sending_failure()
    {
        Log::shouldReceive('error')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
        ]);

        $this->emailServiceMock
            ->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'SMTP connection failed',
            ]);

        $result = $this->service->sendApproved($entry);

        $this->assertFalse($result['success']);
        $this->assertEquals('SMTP connection failed', $result['error']);
        $this->assertEquals(EmailReviewQueue::STATUS_FAILED, $entry->fresh()->status);
    }

    /** @test */
    public function it_handles_exception_during_sending()
    {
        Log::shouldReceive('error')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
        ]);

        $this->emailServiceMock
            ->shouldReceive('send')
            ->once()
            ->andThrow(new \Exception('Unexpected error'));

        $result = $this->service->sendApproved($entry);

        $this->assertFalse($result['success']);
        $this->assertEquals('Unexpected error', $result['error']);
        $this->assertEquals(EmailReviewQueue::STATUS_FAILED, $entry->fresh()->status);
    }

    /** @test */
    public function it_bulk_approves_entries()
    {
        Log::shouldReceive('info')->times(3);

        $entry1 = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);
        $entry2 = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);
        $entry3 = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $result = $this->service->bulkApprove(
            [$entry1->id, $entry2->id, $entry3->id],
            'Bulk approved'
        );

        $this->assertEquals(3, $result['approved']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);

        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $entry1->fresh()->status);
        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $entry2->fresh()->status);
        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $entry3->fresh()->status);
    }

    /** @test */
    public function it_handles_failures_in_bulk_approve()
    {
        Log::shouldReceive('info')->once();

        $entry1 = EmailReviewQueue::factory()->create();

        $result = $this->service->bulkApprove(
            [$entry1->id, 99999], // 99999 doesn't exist
            'Bulk approved'
        );

        $this->assertEquals(1, $result['approved']);
        $this->assertEquals(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
    }

    /** @test */
    public function it_bulk_rejects_entries()
    {
        Log::shouldReceive('info')->times(2);

        $entry1 = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);
        $entry2 = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $result = $this->service->bulkReject(
            [$entry1->id, $entry2->id],
            'Bulk rejected'
        );

        $this->assertEquals(2, $result['rejected']);
        $this->assertEquals(0, $result['failed']);

        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $entry1->fresh()->status);
        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $entry2->fresh()->status);
    }

    /** @test */
    public function it_processes_approved_queue()
    {
        Log::shouldReceive('info')->times(2);

        $website = Website::factory()->create();
        $contact1 = Contact::factory()->create(['website_id' => $website->id]);
        $contact2 = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();

        $entry1 = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact1->id,
            'email_template_id' => $template->id,
            'priority' => 50,
        ]);

        $entry2 = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact2->id,
            'email_template_id' => $template->id,
            'priority' => 60,
        ]);

        // Should not be processed (not approved)
        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $this->emailServiceMock
            ->shouldReceive('send')
            ->twice()
            ->andReturn(['success' => true, 'log_id' => 1]);

        $result = $this->service->processApprovedQueue(10);

        $this->assertEquals(2, $result['processed']);
        $this->assertEquals(2, $result['sent']);
        $this->assertEquals(0, $result['failed']);
    }

    /** @test */
    public function it_processes_queue_with_limit()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();

        EmailReviewQueue::factory()->count(5)->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
        ]);

        $this->emailServiceMock
            ->shouldReceive('send')
            ->once()
            ->andReturn(['success' => true, 'log_id' => 1]);

        $result = $this->service->processApprovedQueue(1);

        $this->assertEquals(1, $result['processed']);
    }

    /** @test */
    public function it_processes_queue_by_priority_order()
    {
        Log::shouldReceive('info')->times(2);

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();

        $lowPriority = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'priority' => 10,
            'created_at' => now()->subHours(2),
        ]);

        $highPriority = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'priority' => 90,
            'created_at' => now()->subHours(1),
        ]);

        $callOrder = [];

        $this->emailServiceMock
            ->shouldReceive('send')
            ->twice()
            ->andReturnUsing(function ($contact, $template, $smtp) use (&$callOrder, $highPriority, $lowPriority) {
                // Track which entries are being processed
                $callOrder[] = true;
                return ['success' => true, 'log_id' => 1];
            });

        $this->service->processApprovedQueue(10);

        // High priority should be processed first
        $this->assertEquals(2, count($callOrder));
    }

    /** @test */
    public function it_gets_pending_entries()
    {
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING, 'priority' => 75]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING, 'priority' => 50]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);

        $entries = $this->service->getPendingEntries(10, 'priority', 'desc');

        $this->assertCount(2, $entries);
        $this->assertEquals(75, $entries->first()->priority);
    }

    /** @test */
    public function it_orders_pending_entries_by_created_at()
    {
        Carbon::setTestNow('2024-01-15 10:00:00');

        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
            'created_at' => now()->subHours(2),
        ]);

        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
            'created_at' => now()->subHours(1),
        ]);

        $entries = $this->service->getPendingEntries(10, 'created_at', 'asc');

        $this->assertCount(2, $entries);
        $this->assertTrue($entries->first()->created_at < $entries->last()->created_at);
    }

    /** @test */
    public function it_gets_approved_entries()
    {
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED, 'priority' => 80]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED, 'priority' => 60]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $entries = $this->service->getApprovedEntries(10);

        $this->assertCount(2, $entries);
        $this->assertEquals(80, $entries->first()->priority);
    }

    /** @test */
    public function it_gets_queue_statistics()
    {
        Carbon::setTestNow('2024-01-15 10:00:00');

        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING, 'priority' => 80]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING, 'priority' => 50]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_REJECTED]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_SENT]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_FAILED]);

        $stats = $this->service->getStatistics();

        $this->assertEquals(6, $stats['total_entries']);
        $this->assertEquals(2, $stats['pending']);
        $this->assertEquals(1, $stats['approved']);
        $this->assertEquals(1, $stats['rejected']);
        $this->assertEquals(1, $stats['sent']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertEquals(1, $stats['high_priority']);
        $this->assertNotNull($stats['oldest_pending']);
    }

    /** @test */
    public function it_auto_queues_high_priority_contact()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'priority' => 80,
        ]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => false]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'S', 'body_template' => 'B', 'preheader' => 'P']);

        $entry = $this->service->autoQueueForReview($contact, $template);

        $this->assertNotNull($entry);
        $this->assertEquals(75, $entry->priority);
        $this->assertStringContainsString('High priority contact', $entry->notes);
    }

    /** @test */
    public function it_auto_queues_first_contact_to_website()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'priority' => 50,
        ]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => false]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'S', 'body_template' => 'B', 'preheader' => 'P']);

        $entry = $this->service->autoQueueForReview($contact, $template);

        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual(60, $entry->priority);
        $this->assertStringContainsString('First contact to this website', $entry->notes);
    }

    /** @test */
    public function it_auto_queues_ai_generated_content()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => true]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'S', 'body_template' => 'B', 'preheader' => 'P']);

        $entry = $this->service->autoQueueForReview($contact, $template);

        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual(70, $entry->priority);
        $this->assertStringContainsString('AI-generated content requires review', $entry->notes);
    }

    /** @test */
    public function it_auto_queues_with_force_review_criteria()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id, 'priority' => 50]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => false]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'S', 'body_template' => 'B', 'preheader' => 'P']);

        $entry = $this->service->autoQueueForReview($contact, $template, [
            'force_review' => true,
            'priority' => 85,
            'notes' => 'Custom review required',
        ]);

        $this->assertNotNull($entry);
        $this->assertEquals(85, $entry->priority);
        $this->assertStringContainsString('Custom review required', $entry->notes);
    }

    /** @test */
    public function it_does_not_auto_queue_regular_contact()
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'priority' => 50,
        ]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => false]);

        $entry = $this->service->autoQueueForReview($contact, $template);

        $this->assertNull($entry);
    }

    /** @test */
    public function it_requeues_entry()
    {
        Log::shouldReceive('info')->once();

        $entry = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_REJECTED,
            'reviewed_at' => now(),
            'sent_at' => null,
        ]);

        $result = $this->service->requeue($entry);

        $this->assertEquals(EmailReviewQueue::STATUS_PENDING, $result->status);
        $this->assertNull($result->reviewed_at);
        $this->assertNull($result->sent_at);
    }

    /** @test */
    public function it_updates_entry_priority()
    {
        $entry = EmailReviewQueue::factory()->create(['priority' => 50]);

        $result = $this->service->updatePriority($entry, 90);

        $this->assertEquals(90, $result->priority);
    }

    /** @test */
    public function it_cleans_up_old_entries()
    {
        Log::shouldReceive('info')->once();
        Carbon::setTestNow('2024-06-01 10:00:00');

        // Old entries that should be deleted
        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_SENT,
            'created_at' => now()->subDays(100),
        ]);

        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_REJECTED,
            'created_at' => now()->subDays(95),
        ]);

        // Recent entry that should remain
        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_SENT,
            'created_at' => now()->subDays(30),
        ]);

        // Pending entry that should remain (regardless of age)
        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
            'created_at' => now()->subDays(100),
        ]);

        $deleted = $this->service->cleanupOldEntries(90);

        $this->assertEquals(2, $deleted);
        $this->assertEquals(2, EmailReviewQueue::count());
    }

    /** @test */
    public function it_uses_custom_days_for_cleanup()
    {
        Log::shouldReceive('info')->once();
        Carbon::setTestNow('2024-06-01 10:00:00');

        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_SENT,
            'created_at' => now()->subDays(40),
        ]);

        $deleted = $this->service->cleanupOldEntries(30);

        $this->assertEquals(1, $deleted);
    }

    /** @test */
    public function it_combines_multiple_auto_queue_notes()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'priority' => 80,
        ]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => true]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'S', 'body_template' => 'B', 'preheader' => 'P']);

        $entry = $this->service->autoQueueForReview($contact, $template);

        $this->assertNotNull($entry);
        $this->assertStringContainsString('High priority contact', $entry->notes);
        $this->assertStringContainsString('First contact to this website', $entry->notes);
        $this->assertStringContainsString('AI-generated content requires review', $entry->notes);
    }

    /** @test */
    public function it_uses_max_priority_when_multiple_criteria_match()
    {
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'priority' => 80, // Triggers priority 75
        ]);
        $template = EmailTemplate::factory()->create(['ai_enabled' => true]); // Triggers priority 70

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'S', 'body_template' => 'B', 'preheader' => 'P']);

        $entry = $this->service->autoQueueForReview($contact, $template);

        $this->assertEquals(75, $entry->priority); // Should use the highest
    }
}
