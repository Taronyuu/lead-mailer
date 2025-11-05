<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ValidateContactEmailJob;
use App\Models\Contact;
use App\Services\EmailValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ValidateContactEmailJobTest extends TestCase
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
        $job = new ValidateContactEmailJob($contact);

        $this->assertEquals(60, $job->timeout);
        $this->assertEquals(2, $job->tries);
    }

    public function test_handle_skips_already_validated_contact(): void
    {
        Log::shouldNotReceive('info');

        $contact = Contact::factory()->create([
            'is_validated' => true,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldNotReceive('validate');

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }

    public function test_handle_validates_contact_and_marks_as_valid(): void
    {
        Log::shouldReceive('info')->twice();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
            'is_validated' => false,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->with('test@example.com')
            ->andReturn([
                'valid' => true,
                'error' => null,
            ]);

        $contact = Mockery::mock($contact)->makePartial();
        $contact->shouldReceive('markAsValidated')
            ->once()
            ->with(true, null);

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }

    public function test_handle_validates_contact_and_marks_as_invalid(): void
    {
        Log::shouldReceive('info')->twice();

        $contact = Contact::factory()->create([
            'email' => 'invalid@example.com',
            'is_validated' => false,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->with('invalid@example.com')
            ->andReturn([
                'valid' => false,
                'error' => 'Invalid email format',
            ]);

        $contact = Mockery::mock($contact)->makePartial();
        $contact->shouldReceive('markAsValidated')
            ->once()
            ->with(false, 'Invalid email format');

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }

    public function test_handle_marks_as_invalid_on_exception(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Contact email validation failed', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Validation service error';
            }));

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
            'is_validated' => false,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->andThrow(new \Exception('Validation service error'));

        $contact = Mockery::mock($contact)->makePartial();
        $contact->shouldReceive('markAsValidated')
            ->once()
            ->with(false, 'Validation error: Validation service error');

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }

    public function test_handle_logs_validation_start_and_completion(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Validating contact email', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['email'])
                    && $context['email'] === 'test@example.com';
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Contact email validation completed', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['is_valid'])
                    && $context['is_valid'] === true;
            }));

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
            'is_validated' => false,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => true, 'error' => null]);

        $contact = Mockery::mock($contact)->makePartial();
        $contact->shouldReceive('markAsValidated')->once();

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }

    public function test_failed_method_marks_contact_as_invalid(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Contact validation job failed permanently', Mockery::on(function ($context) {
                return isset($context['contact_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $contact = Mockery::mock(Contact::class)->makePartial();
        $contact->id = 1;
        $contact->shouldReceive('markAsValidated')
            ->once()
            ->with(false, 'Validation job failed: Permanent failure');

        $job = new ValidateContactEmailJob($contact);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_serialized(): void
    {
        $contact = Contact::factory()->create();
        $job = new ValidateContactEmailJob($contact);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ValidateContactEmailJob::class, $unserialized);
        $this->assertEquals($contact->id, $unserialized->contact->id);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create();
        ValidateContactEmailJob::dispatch($contact);

        Queue::assertPushed(ValidateContactEmailJob::class, function ($job) use ($contact) {
            return $job->contact->id === $contact->id;
        });
    }

    public function test_handle_with_validation_error_message(): void
    {
        Log::shouldReceive('info')->twice();

        $contact = Contact::factory()->create([
            'email' => 'bounced@example.com',
            'is_validated' => false,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'error' => 'Email bounced',
            ]);

        $contact = Mockery::mock($contact)->makePartial();
        $contact->shouldReceive('markAsValidated')
            ->once()
            ->with(false, 'Email bounced');

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }

    public function test_handle_with_validation_result_without_error_key(): void
    {
        Log::shouldReceive('info')->twice();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
            'is_validated' => false,
        ]);

        $validatorMock = Mockery::mock(EmailValidationService::class);
        $validatorMock->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => true,
            ]);

        $contact = Mockery::mock($contact)->makePartial();
        $contact->shouldReceive('markAsValidated')
            ->once()
            ->with(true, null);

        $job = new ValidateContactEmailJob($contact);
        $job->handle($validatorMock);
    }
}
