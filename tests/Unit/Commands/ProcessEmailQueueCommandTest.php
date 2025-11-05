<?php

namespace Tests\Unit\Commands;

use App\Jobs\ProcessApprovedEmailsJob;
use App\Jobs\ProcessEmailQueueJob;
use App\Services\ReviewQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessEmailQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_processes_email_queue_directly(): void
    {
        $this->artisan('email:process')
            ->expectsOutput('Processing email queue (batch size: 50)')
            ->expectsOutput('Email queue processed!')
            ->assertExitCode(0);
    }

    public function test_command_processes_approved_emails_directly(): void
    {
        $serviceMock = Mockery::mock(ReviewQueueService::class);
        $serviceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->with(50)
            ->andReturn([
                'processed' => 5,
                'sent' => 5,
                'failed' => 0,
                'errors' => [],
            ]);

        $this->app->instance(ReviewQueueService::class, $serviceMock);

        $this->artisan('email:process', ['--approved' => true])
            ->expectsOutput('Processing approved emails (batch size: 50)')
            ->expectsOutput('Approved emails processed!')
            ->assertExitCode(0);
    }

    public function test_command_queues_email_queue_job(): void
    {
        Queue::fake();

        $this->artisan('email:process', ['--queue' => true])
            ->expectsOutput('Processing email queue (batch size: 50)')
            ->expectsOutput('Job queued. Run: php artisan queue:work')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 50;
        });
    }

    public function test_command_queues_approved_emails_job(): void
    {
        Queue::fake();

        $this->artisan('email:process', [
            '--approved' => true,
            '--queue' => true,
        ])
            ->expectsOutput('Processing approved emails (batch size: 50)')
            ->expectsOutput('Job queued. Run: php artisan queue:work')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessApprovedEmailsJob::class, function ($job) {
            return $job->batchSize === 50;
        });
    }

    public function test_command_respects_custom_batch_size(): void
    {
        Queue::fake();

        $this->artisan('email:process', [
            '--batch-size' => 100,
            '--queue' => true,
        ])
            ->expectsOutput('Processing email queue (batch size: 100)')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 100;
        });
    }

    public function test_command_respects_batch_size_for_approved_emails(): void
    {
        Queue::fake();

        $this->artisan('email:process', [
            '--approved' => true,
            '--batch-size' => 25,
            '--queue' => true,
        ])
            ->expectsOutput('Processing approved emails (batch size: 25)')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessApprovedEmailsJob::class, function ($job) {
            return $job->batchSize === 25;
        });
    }

    public function test_command_runs_email_queue_job_directly(): void
    {
        Queue::fake();

        $this->artisan('email:process')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_command_runs_approved_emails_job_directly(): void
    {
        Queue::fake();

        $serviceMock = Mockery::mock(ReviewQueueService::class);
        $serviceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn([
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'errors' => [],
            ]);

        $this->app->instance(ReviewQueueService::class, $serviceMock);

        $this->artisan('email:process', ['--approved' => true])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_command_uses_default_batch_size_50(): void
    {
        Queue::fake();

        $this->artisan('email:process', ['--queue' => true])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 50;
        });
    }

    public function test_command_handles_string_batch_size(): void
    {
        Queue::fake();

        $this->artisan('email:process', [
            '--batch-size' => '75',
            '--queue' => true,
        ])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 75;
        });
    }

    public function test_command_differentiates_approved_and_normal_queue(): void
    {
        Queue::fake();

        // Normal queue
        $this->artisan('email:process', ['--queue' => true])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class);
        Queue::assertNotPushed(ProcessApprovedEmailsJob::class);

        Queue::fake(); // Reset

        // Approved queue
        $this->artisan('email:process', [
            '--approved' => true,
            '--queue' => true,
        ])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessApprovedEmailsJob::class);
        Queue::assertNotPushed(ProcessEmailQueueJob::class);
    }

    public function test_command_shows_correct_output_for_normal_queue(): void
    {
        $this->artisan('email:process')
            ->expectsOutputToContain('Processing email queue')
            ->doesntExpectOutputToContain('approved')
            ->assertExitCode(0);
    }

    public function test_command_shows_correct_output_for_approved_queue(): void
    {
        $serviceMock = Mockery::mock(ReviewQueueService::class);
        $serviceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn([
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'errors' => [],
            ]);

        $this->app->instance(ReviewQueueService::class, $serviceMock);

        $this->artisan('email:process', ['--approved' => true])
            ->expectsOutputToContain('approved emails')
            ->assertExitCode(0);
    }

    public function test_command_with_approved_option_calls_review_service(): void
    {
        $serviceMock = Mockery::mock(ReviewQueueService::class);
        $serviceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->with(50)
            ->andReturn([
                'processed' => 3,
                'sent' => 3,
                'failed' => 0,
                'errors' => [],
            ]);

        $this->app->instance(ReviewQueueService::class, $serviceMock);

        $this->artisan('email:process', ['--approved' => true])
            ->assertExitCode(0);
    }

    public function test_command_without_approved_option_does_not_call_review_service(): void
    {
        $serviceMock = Mockery::mock(ReviewQueueService::class);
        $serviceMock->shouldNotReceive('processApprovedQueue');

        $this->app->instance(ReviewQueueService::class, $serviceMock);

        $this->artisan('email:process')
            ->assertExitCode(0);
    }

    public function test_command_queue_option_prevents_direct_execution(): void
    {
        Queue::fake();

        $serviceMock = Mockery::mock(ReviewQueueService::class);
        $serviceMock->shouldNotReceive('processApprovedQueue');

        $this->app->instance(ReviewQueueService::class, $serviceMock);

        $this->artisan('email:process', [
            '--approved' => true,
            '--queue' => true,
        ])
            ->assertExitCode(0);
    }

    public function test_command_with_small_batch_size(): void
    {
        Queue::fake();

        $this->artisan('email:process', [
            '--batch-size' => 1,
            '--queue' => true,
        ])
            ->expectsOutput('Processing email queue (batch size: 1)')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 1;
        });
    }

    public function test_command_with_large_batch_size(): void
    {
        Queue::fake();

        $this->artisan('email:process', [
            '--batch-size' => 1000,
            '--queue' => true,
        ])
            ->expectsOutput('Processing email queue (batch size: 1000)')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessEmailQueueJob::class, function ($job) {
            return $job->batchSize === 1000;
        });
    }
}
