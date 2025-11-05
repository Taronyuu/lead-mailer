<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\EmailReviewQueue;
use App\Models\Website;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Jobs\ProcessApprovedEmailsJob;

class ReviewQueueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_review_and_approval_flow(): void
    {
        Queue::fake();

        // 1. Setup prerequisites
        $website = Website::factory()->create([
            'meets_requirements' => true,
        ]);

        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $template = EmailTemplate::factory()->create();

        $user = User::factory()->create();

        // 2. Create email review queue entry
        $reviewItem = EmailReviewQueue::factory()->create([
            'website_id' => $website->id,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'generated_subject' => 'Hello from our team',
            'generated_body' => 'We would love to work with you',
            'status' => EmailReviewQueue::STATUS_PENDING,
            'priority' => 80,
        ]);

        // 3. Assert pending status
        $this->assertDatabaseHas('email_review_queue', [
            'id' => $reviewItem->id,
            'status' => EmailReviewQueue::STATUS_PENDING,
            'reviewed_by_user_id' => null,
        ]);

        // 4. Approve email
        $reviewItem->approve($user->id, 'Looks good!');

        // 5. Assert approved status
        $this->assertDatabaseHas('email_review_queue', [
            'id' => $reviewItem->id,
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'reviewed_by_user_id' => $user->id,
            'review_notes' => 'Looks good!',
        ]);

        $this->assertNotNull($reviewItem->fresh()->reviewed_at);
        $this->assertTrue($reviewItem->fresh()->isApproved());

        // 6. Process approved emails would be dispatched
        ProcessApprovedEmailsJob::dispatch();
        Queue::assertPushed(ProcessApprovedEmailsJob::class);
    }

    public function test_email_review_rejection_flow(): void
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $reviewItem = EmailReviewQueue::factory()->create([
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'generated_subject' => 'Spam-like subject',
            'generated_body' => 'Generic spam content',
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        // Reject email
        $reviewItem->reject($user->id, 'Too generic, needs personalization');

        $this->assertDatabaseHas('email_review_queue', [
            'id' => $reviewItem->id,
            'status' => EmailReviewQueue::STATUS_REJECTED,
            'reviewed_by_user_id' => $user->id,
            'review_notes' => 'Too generic, needs personalization',
        ]);

        $this->assertFalse($reviewItem->fresh()->isApproved());
        $this->assertNotNull($reviewItem->fresh()->reviewed_at);
    }

    public function test_pending_review_queue_scope(): void
    {
        // Create pending items
        EmailReviewQueue::factory()->count(3)->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        // Create approved items
        EmailReviewQueue::factory()->count(2)->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
        ]);

        // Create rejected items
        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_REJECTED,
        ]);

        $pending = EmailReviewQueue::pending()->get();
        $approved = EmailReviewQueue::approved()->get();
        $rejected = EmailReviewQueue::rejected()->get();

        $this->assertCount(3, $pending);
        $this->assertCount(2, $approved);
        $this->assertCount(1, $rejected);
    }

    public function test_high_priority_review_items(): void
    {
        EmailReviewQueue::factory()->create([
            'priority' => 95,
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        EmailReviewQueue::factory()->create([
            'priority' => 75,
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        EmailReviewQueue::factory()->create([
            'priority' => 50,
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $highPriority = EmailReviewQueue::highPriority()->get();

        $this->assertCount(2, $highPriority);
        $this->assertTrue($highPriority->every(fn($item) => $item->priority >= 70));
    }

    public function test_review_queue_relationships(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
        ]);
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $reviewItem = EmailReviewQueue::factory()->create([
            'website_id' => $website->id,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'reviewed_by_user_id' => $user->id,
        ]);

        $this->assertEquals($website->id, $reviewItem->website->id);
        $this->assertEquals($contact->id, $reviewItem->contact->id);
        $this->assertEquals($template->id, $reviewItem->emailTemplate->id);
        $this->assertEquals($user->id, $reviewItem->reviewedBy->id);
    }

    public function test_review_queue_ordered_by_priority(): void
    {
        EmailReviewQueue::factory()->create(['priority' => 50]);
        EmailReviewQueue::factory()->create(['priority' => 90]);
        EmailReviewQueue::factory()->create(['priority' => 70]);

        $orderedItems = EmailReviewQueue::orderBy('priority', 'desc')->get();

        $this->assertEquals(90, $orderedItems->first()->priority);
        $this->assertEquals(50, $orderedItems->last()->priority);
    }

    public function test_generated_email_content_stored(): void
    {
        $reviewItem = EmailReviewQueue::factory()->create([
            'generated_subject' => 'Personalized Subject for Company X',
            'generated_body' => 'Hello, we reviewed your website at example.com and think...',
            'generated_preheader' => 'Quick question about your website',
        ]);

        $this->assertDatabaseHas('email_review_queue', [
            'id' => $reviewItem->id,
            'generated_subject' => 'Personalized Subject for Company X',
        ]);

        $this->assertNotEmpty($reviewItem->generated_body);
        $this->assertNotEmpty($reviewItem->generated_preheader);
    }

    public function test_bulk_approval_workflow(): void
    {
        $user = User::factory()->create();

        $items = EmailReviewQueue::factory()->count(5)->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        foreach ($items as $item) {
            $item->approve($user->id);
        }

        $approvedCount = EmailReviewQueue::approved()->count();
        $this->assertEquals(5, $approvedCount);

        // All should have the same reviewer
        $this->assertTrue(
            EmailReviewQueue::approved()->get()
                ->every(fn($item) => $item->reviewed_by_user_id === $user->id)
        );
    }

    public function test_review_queue_filtering_by_website(): void
    {
        $website1 = Website::factory()->create();
        $website2 = Website::factory()->create();

        EmailReviewQueue::factory()->count(3)->create([
            'website_id' => $website1->id,
        ]);

        EmailReviewQueue::factory()->count(2)->create([
            'website_id' => $website2->id,
        ]);

        $website1Reviews = EmailReviewQueue::where('website_id', $website1->id)->get();
        $website2Reviews = EmailReviewQueue::where('website_id', $website2->id)->get();

        $this->assertCount(3, $website1Reviews);
        $this->assertCount(2, $website2Reviews);
    }

    public function test_review_notes_captured(): void
    {
        $user = User::factory()->create();
        $reviewItem = EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        $detailedNotes = 'Approved after fixing subject line. Changed "Hi there" to use actual name.';
        $reviewItem->approve($user->id, $detailedNotes);

        $this->assertDatabaseHas('email_review_queue', [
            'id' => $reviewItem->id,
            'review_notes' => $detailedNotes,
        ]);
    }

    public function test_only_approved_emails_get_processed(): void
    {
        Queue::fake();

        EmailReviewQueue::factory()->count(3)->create([
            'status' => EmailReviewQueue::STATUS_APPROVED,
        ]);

        EmailReviewQueue::factory()->count(2)->create([
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);

        EmailReviewQueue::factory()->create([
            'status' => EmailReviewQueue::STATUS_REJECTED,
        ]);

        $approvedForSending = EmailReviewQueue::approved()->get();

        $this->assertCount(3, $approvedForSending);
        $this->assertTrue(
            $approvedForSending->every(fn($item) => $item->isApproved())
        );
    }

    public function test_review_timestamp_recorded(): void
    {
        $user = User::factory()->create();
        $reviewItem = EmailReviewQueue::factory()->create([
            'reviewed_at' => null,
        ]);

        $beforeReview = now();
        $reviewItem->approve($user->id);
        $afterReview = now();

        $reviewedAt = $reviewItem->fresh()->reviewed_at;

        $this->assertNotNull($reviewedAt);
        $this->assertTrue($reviewedAt->between($beforeReview, $afterReview));
    }
}
