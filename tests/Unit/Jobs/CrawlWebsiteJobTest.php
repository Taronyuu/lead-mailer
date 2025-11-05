<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CrawlWebsiteJob;
use App\Jobs\ExtractContactsJob;
use App\Models\Website;
use App\Services\WebCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CrawlWebsiteJobTest extends TestCase
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
        $job = new CrawlWebsiteJob($website);

        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->backoff);
    }

    public function test_handle_successfully_crawls_website(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once()
            ->with($website);

        // Mock fresh() to return completed status
        $completedWebsite = Website::factory()->make([
            'id' => $website->id,
            'status' => Website::STATUS_COMPLETED,
        ]);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('fresh')
            ->twice()
            ->andReturn($completedWebsite);

        $job = new CrawlWebsiteJob($website);
        $job->handle($crawlerMock);

        Queue::assertPushed(ExtractContactsJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_handle_does_not_dispatch_extract_job_when_crawl_fails(): void
    {
        Queue::fake();
        Log::shouldReceive('info')->once();

        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once()
            ->with($website);

        // Mock fresh() to return failed status
        $failedWebsite = Website::factory()->make([
            'id' => $website->id,
            'status' => Website::STATUS_FAILED,
        ]);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('fresh')
            ->twice()
            ->andReturn($failedWebsite);

        $job = new CrawlWebsiteJob($website);
        $job->handle($crawlerMock);

        Queue::assertNotPushed(ExtractContactsJob::class);
    }

    public function test_handle_logs_and_throws_exception_on_error(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once()
            ->with('Website crawl failed', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Crawl error';
            }));

        $website = Website::factory()->create();

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once()
            ->andThrow(new \Exception('Crawl error'));

        $job = new CrawlWebsiteJob($website);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Crawl error');

        $job->handle($crawlerMock);
    }

    public function test_failed_method_updates_website_and_logs_error(): void
    {
        Log::shouldReceive('error')->once()
            ->with('Website crawl job failed permanently', Mockery::on(function ($context) {
                return isset($context['website_id'])
                    && isset($context['error'])
                    && $context['error'] === 'Permanent failure';
            }));

        $website = Mockery::mock(Website::class)->makePartial();
        $website->id = 1;
        $website->shouldReceive('failCrawl')
            ->once()
            ->with('Permanent failure');

        $job = new CrawlWebsiteJob($website);
        $exception = new \Exception('Permanent failure');

        $job->failed($exception);
    }

    public function test_job_can_be_serialized(): void
    {
        $website = Website::factory()->create();
        $job = new CrawlWebsiteJob($website);

        $serialized = serialize($job);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(CrawlWebsiteJob::class, $unserialized);
        $this->assertEquals($website->id, $unserialized->website->id);
    }

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $website = Website::factory()->create();
        CrawlWebsiteJob::dispatch($website);

        Queue::assertPushed(CrawlWebsiteJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_handle_logs_start_and_completion(): void
    {
        Queue::fake();

        Log::shouldReceive('info')
            ->once()
            ->with('Starting website crawl', Mockery::on(function ($context) {
                return isset($context['website_id']) && isset($context['url']);
            }));

        Log::shouldReceive('info')
            ->once()
            ->with('Website crawl completed', Mockery::on(function ($context) {
                return isset($context['website_id']) && isset($context['status']);
            }));

        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->once();

        $completedWebsite = Website::factory()->make([
            'id' => $website->id,
            'status' => Website::STATUS_COMPLETED,
        ]);

        $website = Mockery::mock($website)->makePartial();
        $website->shouldReceive('fresh')
            ->twice()
            ->andReturn($completedWebsite);

        $job = new CrawlWebsiteJob($website);
        $job->handle($crawlerMock);
    }
}
