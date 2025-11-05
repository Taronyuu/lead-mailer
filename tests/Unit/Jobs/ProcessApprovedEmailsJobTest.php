<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessApprovedEmailsJob;
use App\Services\ReviewQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessApprovedEmailsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_has_correct_configuration(): void
    {
        $job = new ProcessApprovedEmailsJob(10);

        $this->assertEquals(600, $job->timeout);
        $this->assertEquals(1, $job->tries);
    }

    public function test_job_has_default_batch_size(): void
    {
        $job = new ProcessApprovedEmailsJob();

        $this->assertEquals(10, $job->batchSize);
    }

    public function test_handle_processes_approved_emails_successfully(): void
    {
        Log::shouldReceive('info')->twice();

        $results = [
            'processed' => 5,
            'sent' => 5,
            'failed' => 0,
            'errors' => [],
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->with(10)
            ->andReturn($results);

        $job = new ProcessApprovedEmailsJob(10);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_logs_processing_stats(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Processing approved emails batch', Mockery::on(function ($context) {
                return isset($context['batch_size']) && $context['batch_size'] === 10;
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Approved emails batch processed', Mockery::on(function ($context) {
                return isset($context['processed'])
                    && isset($context['sent'])
                    && isset($context['failed'])
                    && $context['processed'] === 5
                    && $context['sent'] === 5
                    && $context['failed'] === 0;
            }));

        $results = [
            'processed' => 5,
            'sent' => 5,
            'failed' => 0,
            'errors' => [],
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn($results);

        $job = new ProcessApprovedEmailsJob(10);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_logs_warning_when_some_emails_fail(): void
    {
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')
            ->once()
            ->with('Some approved emails failed to send', Mockery::on(function ($context) {
                return isset($context['errors']) && count($context['errors']) === 2;
            }));

        $results = [
            'processed' => 5,
            'sent' => 3,
            'failed' => 2,
            'errors' => [
                'Error sending to contact 1',
                'Error sending to contact 2',
            ],
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn($results);

        $job = new ProcessApprovedEmailsJob(10);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_does_not_log_warning_when_all_succeed(): void
    {
        Log::shouldReceive('info')->twice();
        Log::shouldNotReceive('warning');

        $results = [
            'processed' => 5,
            'sent' => 5,
            'failed' => 0,
            'errors' => [],
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn($results);

        $job = new ProcessApprovedEmailsJob(10);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_respects_batch_size(): void
    {
        Log::shouldReceive('info')->twice();

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->with(25)
            ->andReturn([
                'processed' => 25,
                'sent' => 25,
                'failed' => 0,
                'errors' => [],
            ]);

        $job = new ProcessApprovedEmailsJob(25);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_throws_exception_on_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Approved emails processing failed', Mockery::on(function ($context) {
                return isset($context['error'])
                    && $context['error'] === 'Service error';
            }));

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andThrow(new \Exception('Service error'));

        $job = new ProcessApprovedEmailsJob(10);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Service error');

        $job->handle($reviewServiceMock);
    }

    public function test_failed_method_logs_error(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Approved emails processing job failed permanently', Mockery::on(function ($context) {
                return isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $job = new ProcessApprovedEmailsJob(10);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_serialized(): void
    {
        $job = new ProcessApprovedEmailsJob(15);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ProcessApprovedEmailsJob::class, $unserialized);
        $this->assertEquals(15, $unserialized->batchSize);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        ProcessApprovedEmailsJob::dispatch(20);

        Queue::assertPushed(ProcessApprovedEmailsJob::class, function ($job) {
            return $job->batchSize === 20;
        });
    }

    public function test_handle_with_zero_processed(): void
    {
        Log::shouldReceive('info')->twice();

        $results = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn($results);

        $job = new ProcessApprovedEmailsJob(10);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_with_partial_failures(): void
    {
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once();

        $results = [
            'processed' => 10,
            'sent' => 7,
            'failed' => 3,
            'errors' => [
                'Contact 1: SMTP error',
                'Contact 2: Blacklisted',
                'Contact 3: Invalid email',
            ],
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->andReturn($results);

        $job = new ProcessApprovedEmailsJob(10);
        $job->handle($reviewServiceMock);
    }

    public function test_handle_with_custom_batch_size(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Processing approved emails batch', Mockery::on(function ($context) {
                return $context['batch_size'] === 100;
            }));

        Log::shouldReceive('info')->once();

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('processApprovedQueue')
            ->once()
            ->with(100)
            ->andReturn([
                'processed' => 100,
                'sent' => 100,
                'failed' => 0,
                'errors' => [],
            ]);

        $job = new ProcessApprovedEmailsJob(100);
        $job->handle($reviewServiceMock);
    }
}
