<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\EmailReviewQueue;
use App\Models\Website;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\User;

class EmailReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_correct_table_name(): void
    {
        $queue = new EmailReviewQueue();

        $this->assertEquals('email_review_queue', $queue->getTable());
    }

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'website_id',
            'contact_id',
            'email_template_id',
            'generated_subject',
            'generated_body',
            'generated_preheader',
            'status',
            'reviewed_by_user_id',
            'reviewed_at',
            'review_notes',
            'priority',
        ];

        $queue = new EmailReviewQueue();

        $this->assertEquals($fillable, $queue->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $queue = EmailReviewQueue::factory()->create([
            'priority' => '80',
            'reviewed_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertIsInt($queue->priority);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $queue->reviewed_at);
    }

    public function test_it_does_not_use_soft_deletes(): void
    {
        $queue = EmailReviewQueue::factory()->create();

        $queue->delete();

        $this->assertDatabaseMissing('email_review_queue', ['id' => $queue->id]);
    }

    public function test_it_belongs_to_website(): void
    {
        $website = Website::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['website_id' => $website->id]);

        $this->assertInstanceOf(Website::class, $queue->website);
        $this->assertEquals($website->id, $queue->website->id);
    }

    public function test_it_belongs_to_contact(): void
    {
        $contact = Contact::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['contact_id' => $contact->id]);

        $this->assertInstanceOf(Contact::class, $queue->contact);
        $this->assertEquals($contact->id, $queue->contact->id);
    }

    public function test_it_belongs_recipient_email_template(): void
    {
        $template = EmailTemplate::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['email_template_id' => $template->id]);

        $this->assertInstanceOf(EmailTemplate::class, $queue->emailTemplate);
        $this->assertEquals($template->id, $queue->emailTemplate->id);
    }

    public function test_it_belongs_to_user_as_reviewed_by(): void
    {
        $user = User::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['reviewed_by_user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $queue->reviewedBy);
        $this->assertEquals($user->id, $queue->reviewedBy->id);
    }

    public function test_pending_scope_works(): void
    {
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $pending = EmailReviewQueue::pending()->get();

        $this->assertCount(2, $pending);
        $pending->each(fn($queue) => $this->assertEquals(EmailReviewQueue::STATUS_PENDING, $queue->status));
    }

    public function test_approved_scope_works(): void
    {
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);

        $approved = EmailReviewQueue::approved()->get();

        $this->assertCount(2, $approved);
        $approved->each(fn($queue) => $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $queue->status));
    }

    public function test_rejected_scope_works(): void
    {
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_REJECTED]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);
        EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_REJECTED]);

        $rejected = EmailReviewQueue::rejected()->get();

        $this->assertCount(2, $rejected);
        $rejected->each(fn($queue) => $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $queue->status));
    }

    public function test_high_priority_scope_works(): void
    {
        EmailReviewQueue::factory()->create(['priority' => 80]);
        EmailReviewQueue::factory()->create(['priority' => 50]);
        EmailReviewQueue::factory()->create(['priority' => 70]);
        EmailReviewQueue::factory()->create(['priority' => 69]);

        $highPriority = EmailReviewQueue::highPriority()->get();

        $this->assertCount(2, $highPriority);
        $highPriority->each(fn($queue) => $this->assertGreaterThanOrEqual(70, $queue->priority));
    }

    public function test_approve_updates_status_and_reviewer(): void
    {
        $user = User::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $queue->approve($user->id, 'Looks good!');

        $fresh = $queue->fresh();
        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $fresh->status);
        $this->assertEquals($user->id, $fresh->reviewed_by_user_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertEquals('Looks good!', $fresh->review_notes);
    }

    public function test_approve_without_notes(): void
    {
        $user = User::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $queue->approve($user->id);

        $fresh = $queue->fresh();
        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $fresh->status);
        $this->assertEquals($user->id, $fresh->reviewed_by_user_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertNull($fresh->review_notes);
    }

    public function test_reject_updates_status_and_reviewer(): void
    {
        $user = User::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $queue->reject($user->id, 'Needs improvement');

        $fresh = $queue->fresh();
        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $fresh->status);
        $this->assertEquals($user->id, $fresh->reviewed_by_user_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertEquals('Needs improvement', $fresh->review_notes);
    }

    public function test_reject_without_notes(): void
    {
        $user = User::factory()->create();
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $queue->reject($user->id);

        $fresh = $queue->fresh();
        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $fresh->status);
        $this->assertEquals($user->id, $fresh->reviewed_by_user_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertNull($fresh->review_notes);
    }

    public function test_is_approved_returns_true_when_approved(): void
    {
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);

        $this->assertTrue($queue->isApproved());
    }

    public function test_is_approved_returns_false_when_pending(): void
    {
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_PENDING]);

        $this->assertFalse($queue->isApproved());
    }

    public function test_is_approved_returns_false_when_rejected(): void
    {
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_REJECTED]);

        $this->assertFalse($queue->isApproved());
    }

    public function test_status_constants_are_defined(): void
    {
        $this->assertEquals('pending', EmailReviewQueue::STATUS_PENDING);
        $this->assertEquals('approved', EmailReviewQueue::STATUS_APPROVED);
        $this->assertEquals('rejected', EmailReviewQueue::STATUS_REJECTED);
    }

    public function test_factory_creates_valid_email_review_queue(): void
    {
        $queue = EmailReviewQueue::factory()->create();

        $this->assertInstanceOf(EmailReviewQueue::class, $queue);
        $this->assertNotNull($queue->generated_subject);
        $this->assertNotNull($queue->generated_body);
        $this->assertNotNull($queue->status);
        $this->assertDatabaseHas('email_review_queue', ['id' => $queue->id]);
    }

    public function test_factory_can_create_approved_queue(): void
    {
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_APPROVED]);

        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $queue->status);
    }

    public function test_factory_can_create_rejected_queue(): void
    {
        $queue = EmailReviewQueue::factory()->create(['status' => EmailReviewQueue::STATUS_REJECTED]);

        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $queue->status);
    }

    public function test_reviewed_by_can_be_null(): void
    {
        $queue = EmailReviewQueue::factory()->create([
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ]);

        $this->assertNull($queue->reviewed_by_user_id);
        $this->assertNull($queue->reviewed_at);
        $this->assertNull($queue->reviewedBy);
    }
}
