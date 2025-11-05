<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendOutreachEmailJob;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Services\EmailSendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendOutreachEmailJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_has_correct_configuration(): void
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $job = new SendOutreachEmailJob($contact, $template);

        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(2, $job->tries);
        $this->assertEquals(300, $job->backoff);
    }

    public function test_handle_sends_email_successfully(): void
    {
        Log::shouldReceive('info')->twice();

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->with($contact, $template, null)
            ->andReturn([
                'success' => true,
                'log_id' => 123,
                'smtp_id' => 456,
            ]);

        $job = new SendOutreachEmailJob($contact, $template);
        $job->handle($emailServiceMock);
    }

    public function test_handle_sends_email_with_smtp_credential(): void
    {
        Log::shouldReceive('info')->twice();

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->with($contact, $template, $smtp)
            ->andReturn([
                'success' => true,
                'log_id' => 123,
                'smtp_id' => $smtp->id,
            ]);

        $job = new SendOutreachEmailJob($contact, $template, $smtp);
        $job->handle($emailServiceMock);
    }

    public function test_handle_does_not_retry_on_blacklist_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()
            ->with('Outreach email send failed', Mockery::on(function ($context) {
                return isset($context['error'])
                    && str_contains($context['error'], 'Blacklisted');
            }));

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Blacklisted domain',
            ]);

        $job = new SendOutreachEmailJob($contact, $template);
        $job->handle($emailServiceMock);

        // Should not throw exception, so job doesn't retry
    }

    public function test_handle_does_not_retry_on_duplicate_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Duplicate prevention: already sent',
            ]);

        $job = new SendOutreachEmailJob($contact, $template);
        $job->handle($emailServiceMock);
    }

    public function test_handle_does_not_retry_on_outside_allowed_time_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Outside allowed hours',
            ]);

        $job = new SendOutreachEmailJob($contact, $template);
        $job->handle($emailServiceMock);
    }

    public function test_handle_throws_exception_on_retryable_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->once();

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'SMTP connection failed',
            ]);

        $job = new SendOutreachEmailJob($contact, $template);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SMTP connection failed');

        $job->handle($emailServiceMock);
    }

    public function test_handle_logs_start_and_success(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Sending outreach email', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['template_id'])
                    && isset($context['email']);
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Outreach email sent successfully', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['log_id'])
                    && isset($context['smtp_id'])
                    && $context['log_id'] === 123;
            }));

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => true,
                'log_id' => 123,
                'smtp_id' => 456,
            ]);

        $job = new SendOutreachEmailJob($contact, $template);
        $job->handle($emailServiceMock);
    }

    public function test_handle_throws_exception_on_service_exception(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Outreach email job failed', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Service error';
            }));

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $emailServiceMock = Mockery::mock(EmailSendingService::class);
        $emailServiceMock->shouldReceive('send')
            ->once()
            ->andThrow(new \Exception('Service error'));

        $job = new SendOutreachEmailJob($contact, $template);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Service error');

        $job->handle($emailServiceMock);
    }

    public function test_failed_method_logs_error(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Outreach email job failed permanently', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['template_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $job = new SendOutreachEmailJob($contact, $template);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_serialized(): void
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();
        $job = new SendOutreachEmailJob($contact, $template, $smtp);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(SendOutreachEmailJob::class, $unserialized);
        $this->assertEquals($contact->id, $unserialized->contact->id);
        $this->assertEquals($template->id, $unserialized->template->id);
        $this->assertEquals($smtp->id, $unserialized->smtp->id);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        SendOutreachEmailJob::dispatch($contact, $template);

        Queue::assertPushed(SendOutreachEmailJob::class, function ($job) use ($contact, $template) {
            return $job->contact->id === $contact->id
                && $job->template->id === $template->id;
        });
    }

    public function test_job_can_be_serialized_without_smtp(): void
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $job = new SendOutreachEmailJob($contact, $template, null);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(SendOutreachEmailJob::class, $unserialized);
        $this->assertNull($unserialized->smtp);
    }
}
