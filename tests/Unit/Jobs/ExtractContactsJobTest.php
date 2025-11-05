<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ExtractContactsJob;
use App\Jobs\ValidateContactEmailJob;
use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Models\Website;
use App\Models\Contact;
use App\Services\ContactExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ExtractContactsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_has_correct_configuration(): void
    {
        $website = Website::factory()->create();
        $job = new ExtractContactsJob($website);

        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(2, $job->tries);
    }

    public function test_handle_extracts_contacts_and_dispatches_jobs(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create([
            'content_snapshot' => '<html><body>test@example.com</body></html>',
        ]);

        $contact1 = Contact::factory()->create(['website_id' => $website->id]);
        $contact2 = Contact::factory()->create(['website_id' => $website->id]);
        $contacts = collect([$contact1, $contact2]);

        $extractorMock = Mockery::mock(ContactExtractionService::class);
        $extractorMock->shouldReceive('extractFromHtml')
            ->once()
            ->with($website->content_snapshot, $website->url, $website)
            ->andReturn($contacts);

        $job = new ExtractContactsJob($website);
        $job->handle($extractorMock);

        Queue::assertPushed(ValidateContactEmailJob::class, 2);
        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_handle_logs_warning_when_no_content_snapshot(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')
            ->once()
            ->with('No content snapshot available for contact extraction', Mockery::on(function ($context) {
                return isset($context['website_id']);
            }));

        $website = Website::factory()->create([
            'content_snapshot' => null,
        ]);

        $extractorMock = Mockery::mock(ContactExtractionService::class);
        $extractorMock->shouldNotReceive('extractFromHtml');

        $job = new ExtractContactsJob($website);
        $job->handle($extractorMock);

        Queue::assertNotPushed(ValidateContactEmailJob::class);
        Queue::assertNotPushed(EvaluateWebsiteRequirementsJob::class);
    }

    public function test_handle_dispatches_validation_jobs_for_each_contact(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create([
            'content_snapshot' => '<html>content</html>',
        ]);

        $contact1 = Contact::factory()->create();
        $contact2 = Contact::factory()->create();
        $contact3 = Contact::factory()->create();
        $contacts = collect([$contact1, $contact2, $contact3]);

        $extractorMock = Mockery::mock(ContactExtractionService::class);
        $extractorMock->shouldReceive('extractFromHtml')
            ->once()
            ->andReturn($contacts);

        $job = new ExtractContactsJob($website);
        $job->handle($extractorMock);

        Queue::assertPushed(ValidateContactEmailJob::class, 3);

        Queue::assertPushed(ValidateContactEmailJob::class, function ($job) use ($contact1) {
            return $job->contact->id === $contact1->id;
        });

        Queue::assertPushed(ValidateContactEmailJob::class, function ($job) use ($contact2) {
            return $job->contact->id === $contact2->id;
        });

        Queue::assertPushed(ValidateContactEmailJob::class, function ($job) use ($contact3) {
            return $job->contact->id === $contact3->id;
        });
    }

    public function test_handle_logs_contact_count(): void
    {
        Queue::fake();

        Log::shouldReceive('info')
            ->once()
            ->with('Starting contact extraction', Mockery::type('array'));

        Log::shouldReceive('info')
            ->once()
            ->with('Contact extraction completed', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['contacts_found'])
                    && $context['contacts_found'] === 2;
            }));

        $website = Website::factory()->create([
            'content_snapshot' => '<html>content</html>',
        ]);

        $contacts = collect([
            Contact::factory()->create(),
            Contact::factory()->create(),
        ]);

        $extractorMock = Mockery::mock(ContactExtractionService::class);
        $extractorMock->shouldReceive('extractFromHtml')
            ->once()
            ->andReturn($contacts);

        $job = new ExtractContactsJob($website);
        $job->handle($extractorMock);
    }

    public function test_handle_throws_exception_on_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Contact extraction failed', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Extraction error';
            }));

        $website = Website::factory()->create([
            'content_snapshot' => '<html>content</html>',
        ]);

        $extractorMock = Mockery::mock(ContactExtractionService::class);
        $extractorMock->shouldReceive('extractFromHtml')
            ->once()
            ->andThrow(new \Exception('Extraction error'));

        $job = new ExtractContactsJob($website);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Extraction error');

        $job->handle($extractorMock);
    }

    public function test_failed_method_logs_error(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Contact extraction job failed permanently', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $website = Website::factory()->create();
        $job = new ExtractContactsJob($website);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_serialized(): void
    {
        $website = Website::factory()->create();
        $job = new ExtractContactsJob($website);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ExtractContactsJob::class, $unserialized);
        $this->assertEquals($website->id, $unserialized->website->id);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $website = Website::factory()->create();
        ExtractContactsJob::dispatch($website);

        Queue::assertPushed(ExtractContactsJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_handle_with_empty_contacts_still_dispatches_evaluation(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create([
            'content_snapshot' => '<html>no contacts here</html>',
        ]);

        $extractorMock = Mockery::mock(ContactExtractionService::class);
        $extractorMock->shouldReceive('extractFromHtml')
            ->once()
            ->andReturn(collect([]));

        $job = new ExtractContactsJob($website);
        $job->handle($extractorMock);

        Queue::assertNotPushed(ValidateContactEmailJob::class);
        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class);
    }
}
