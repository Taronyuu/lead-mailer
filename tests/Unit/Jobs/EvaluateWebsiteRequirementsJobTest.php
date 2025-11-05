<?php

namespace Tests\Unit\Jobs;

use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Models\Website;
use App\Services\RequirementsMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EvaluateWebsiteRequirementsJobTest extends TestCase
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
        $job = new EvaluateWebsiteRequirementsJob($website);

        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(2, $job->tries);
    }

    public function test_handle_evaluates_website_requirements(): void
    {
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create([
            'meets_requirements' => null,
        ]);

        $evaluationResults = [
            ['requirement_id' => 1, 'matches' => true],
            ['requirement_id' => 2, 'matches' => false],
        ];

        $matcherMock = Mockery::mock(RequirementsMatcherService::class);
        $matcherMock->shouldReceive('evaluateWebsite')
            ->once()
            ->with($website)
            ->andReturn($evaluationResults);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('refresh')
            ->once()
            ->andReturnSelf();
        $website->meets_requirements = true;

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle($matcherMock);
    }

    public function test_handle_logs_evaluation_start_and_completion(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Evaluating website requirements', Mockery::on(function ($context) {
                return isset($context['website_id']) && isset($context['url']);
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Website requirements evaluation completed', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['meets_requirements'])
                    && isset($context['evaluation_count'])
                    && $context['evaluation_count'] === 3;
            }));

        $website = Website::factory()->create();

        $evaluationResults = [
            ['requirement_id' => 1, 'matches' => true],
            ['requirement_id' => 2, 'matches' => true],
            ['requirement_id' => 3, 'matches' => true],
        ];

        $matcherMock = Mockery::mock(RequirementsMatcherService::class);
        $matcherMock->shouldReceive('evaluateWebsite')
            ->once()
            ->andReturn($evaluationResults);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('refresh')->once()->andReturnSelf();
        $website->meets_requirements = true;

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle($matcherMock);
    }

    public function test_handle_refreshes_website_after_evaluation(): void
    {
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();

        $matcherMock = Mockery::mock(RequirementsMatcherService::class);
        $matcherMock->shouldReceive('evaluateWebsite')
            ->once()
            ->andReturn([]);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('refresh')
            ->once()
            ->andReturnSelf();

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle($matcherMock);
    }

    public function test_handle_throws_exception_on_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Website requirements evaluation failed', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Evaluation error';
            }));

        $website = Website::factory()->create();

        $matcherMock = Mockery::mock(RequirementsMatcherService::class);
        $matcherMock->shouldReceive('evaluateWebsite')
            ->once()
            ->andThrow(new \Exception('Evaluation error'));

        $job = new EvaluateWebsiteRequirementsJob($website);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Evaluation error');

        $job->handle($matcherMock);
    }

    public function test_failed_method_logs_error(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Website requirements evaluation job failed permanently', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $website = Website::factory()->create();
        $job = new EvaluateWebsiteRequirementsJob($website);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_serialized(): void
    {
        $website = Website::factory()->create();
        $job = new EvaluateWebsiteRequirementsJob($website);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(EvaluateWebsiteRequirementsJob::class, $unserialized);
        $this->assertEquals($website->id, $unserialized->website->id);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $website = Website::factory()->create();
        EvaluateWebsiteRequirementsJob::dispatch($website);

        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_handle_with_empty_evaluation_results(): void
    {
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();

        $matcherMock = Mockery::mock(RequirementsMatcherService::class);
        $matcherMock->shouldReceive('evaluateWebsite')
            ->once()
            ->andReturn([]);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('refresh')->once()->andReturnSelf();
        $website->meets_requirements = false;

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle($matcherMock);
    }

    public function test_handle_with_multiple_requirements(): void
    {
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();

        $evaluationResults = [
            ['requirement_id' => 1, 'matches' => true],
            ['requirement_id' => 2, 'matches' => false],
            ['requirement_id' => 3, 'matches' => true],
            ['requirement_id' => 4, 'matches' => true],
            ['requirement_id' => 5, 'matches' => false],
        ];

        $matcherMock = Mockery::mock(RequirementsMatcherService::class);
        $matcherMock->shouldReceive('evaluateWebsite')
            ->once()
            ->andReturn($evaluationResults);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('refresh')->once()->andReturnSelf();
        $website->meets_requirements = false;

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle($matcherMock);
    }
}
