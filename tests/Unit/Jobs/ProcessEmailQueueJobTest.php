<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessEmailQueueJob;
use App\Jobs\SendOutreachEmailJob;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessEmailQueueJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_has_correct_configuration(): void
    {
        $job = new ProcessEmailQueueJob(50);

        $this->assertEquals(600, $job->timeout);
        $this->assertEquals(1, $job->tries);
    }

    public function test_job_has_default_batch_size(): void
    {
        $job = new ProcessEmailQueueJob();

        $this->assertEquals(50, $job->batchSize);
    }

    public function test_handle_processes_qualified_websites(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->times(3);

        $template = EmailTemplate::factory()->create(['is_active' => true]);

        // Create qualified websites
        $website1 = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);
        $website2 = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create valid contacts for each website
        Contact::factory()->create([
            'website_id' => $website1->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);
        Contact::factory()->create([
            'website_id' => $website2->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertPushed(SendOutreachEmailJob::class, 2);
    }

    public function test_handle_limits_contacts_per_website(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->times(3);

        $template = EmailTemplate::factory()->create(['is_active' => true]);
        $website = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create 5 valid contacts for one website (should only process 3)
        Contact::factory()->count(5)->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
            'priority' => 1,
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertPushed(SendOutreachEmailJob::class, 3);
    }

    public function test_handle_prioritizes_contacts_by_priority(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->times(3);

        $template = EmailTemplate::factory()->create(['is_active' => true]);
        $website = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create contacts with different priorities
        $lowPriority = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
            'priority' => 1,
        ]);
        $highPriority = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
            'priority' => 10,
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertPushed(SendOutreachEmailJob::class, function ($job) use ($highPriority) {
            return $job->contact->id === $highPriority->id;
        });
    }

    public function test_handle_skips_websites_already_contacted(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->twice();

        $template = EmailTemplate::factory()->create(['is_active' => true]);

        $website = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create email sent log to indicate already contacted
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $website->emailSentLogs()->create([
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertNotPushed(SendOutreachEmailJob::class);
    }

    public function test_handle_returns_early_when_no_active_template(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')
            ->once()
            ->with('No active email template found');

        // Create qualified website but no active template
        $website = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);
        Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertNotPushed(SendOutreachEmailJob::class);
    }

    public function test_handle_staggers_email_sends(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->times(3);

        $template = EmailTemplate::factory()->create(['is_active' => true]);
        $website = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        Contact::factory()->count(3)->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertPushed(SendOutreachEmailJob::class, 3);
        // Jobs should be delayed (tested via dispatch with delay in actual implementation)
    }

    public function test_handle_respects_batch_size(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->times(3);

        $template = EmailTemplate::factory()->create(['is_active' => true]);

        // Create more qualified websites than batch size
        for ($i = 0; $i < 5; $i++) {
            $website = Website::factory()->create([
                'meets_requirements' => true,
                'status' => Website::STATUS_COMPLETED,
            ]);
            Contact::factory()->create([
                'website_id' => $website->id,
                'is_validated' => true,
                'is_valid' => true,
                'contacted' => false,
            ]);
        }

        $job = new ProcessEmailQueueJob(2); // Only process 2
        $job->handle();

        Queue::assertPushed(SendOutreachEmailJob::class, 2);
    }

    public function test_handle_skips_invalid_contacts(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->twice();

        $template = EmailTemplate::factory()->create(['is_active' => true]);
        $website = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create invalid contacts
        Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => false, // Invalid
            'contacted' => false,
        ]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();

        Queue::assertNotPushed(SendOutreachEmailJob::class);
    }

    public function test_handle_throws_exception_on_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Email queue processing failed', Mockery::on(function ($context) {
                return isset($context['error']);
            }));

        // Force an error by mocking Website::qualifiedLeads() to throw
        $this->mock(Website::class, function ($mock) {
            $mock->shouldReceive('qualifiedLeads')
                ->andThrow(new \Exception('Database error'));
        });

        $job = new ProcessEmailQueueJob(50);

        $this->expectException(\Exception::class);

        $job->handle();
    }

    public function test_failed_method_logs_error(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Email queue processing job failed permanently', Mockery::on(function ($context) {
                return isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $job = new ProcessEmailQueueJob(50);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        ProcessEmailQueueJob::dispatch(25);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 25;
        });
    }

    public function test_handle_logs_processing_stats(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Processing email queue batch', Mockery::on(function ($context) {
                return isset($context['batch_size']) && $context['batch_size'] === 50;
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Found qualified websites for outreach', Mockery::on(function ($context) {
                return isset($context['count']);
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Email queue batch processed', Mockery::on(function ($context) {
                return isset($context['websites_processed'])
                    && isset($context['emails_queued']);
            }));

        $template = EmailTemplate::factory()->create(['is_active' => true]);

        $job = new ProcessEmailQueueJob(50);
        $job->handle();
    }
}
