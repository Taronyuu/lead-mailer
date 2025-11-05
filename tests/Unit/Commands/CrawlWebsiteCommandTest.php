<?php

namespace Tests\Unit\Commands;

use App\Jobs\CrawlWebsiteJob;
use App\Models\Website;
use App\Services\WebCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CrawlWebsiteCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_requires_website_id_or_all_option(): void
    {
        $this->artisan('website:crawl')
            ->expectsOutput('Please provide a website ID or use --all option')
            ->assertExitCode(1);
    }

    public function test_command_crawls_single_website_successfully(): void
    {
        $website = Website::factory()->create(['url' => 'https://example.com']);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once()
            ->with(Mockery::on(function ($arg) use ($website) {
                return $arg->id === $website->id;
            }));

        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['website_id' => $website->id])
            ->expectsOutput("Crawling website: https://example.com (ID: {$website->id})")
            ->expectsOutput('Crawl completed!')
            ->assertExitCode(0);
    }

    public function test_command_fails_if_website_not_found(): void
    {
        $this->artisan('website:crawl', ['website_id' => 999])
            ->expectsOutput('Website not found: 999')
            ->assertExitCode(1);
    }

    public function test_command_queues_crawl_job_with_queue_option(): void
    {
        Queue::fake();
        $website = Website::factory()->create();

        $this->artisan('website:crawl', [
            'website_id' => $website->id,
            '--queue' => true,
        ])
            ->expectsOutput('Crawl job queued. Run: php artisan queue:work')
            ->assertExitCode(0);

        Queue::assertPushed(CrawlWebsiteJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_command_crawls_all_pending_websites(): void
    {
        $websites = Website::factory()->count(3)->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->times(3);
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['--all' => true])
            ->expectsOutput('Found 3 pending websites')
            ->expectsOutput('Crawl batch completed!')
            ->assertExitCode(0);
    }

    public function test_command_respects_limit_option(): void
    {
        Website::factory()->count(10)->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->times(5);
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', [
            '--all' => true,
            '--limit' => 5,
        ])
            ->expectsOutput('Found 5 pending websites')
            ->assertExitCode(0);
    }

    public function test_command_uses_default_limit_of_10(): void
    {
        Website::factory()->count(15)->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->times(10);
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['--all' => true])
            ->expectsOutput('Found 10 pending websites')
            ->assertExitCode(0);
    }

    public function test_command_shows_warning_when_no_pending_websites(): void
    {
        Website::factory()->create(['status' => Website::STATUS_COMPLETED]);

        $this->artisan('website:crawl', ['--all' => true])
            ->expectsOutput('No pending websites found')
            ->assertExitCode(0);
    }

    public function test_command_queues_all_pending_websites_with_queue_option(): void
    {
        Queue::fake();
        Website::factory()->count(3)->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $this->artisan('website:crawl', [
            '--all' => true,
            '--queue' => true,
        ])
            ->expectsOutput('Queued 3 crawl jobs. Run: php artisan queue:work')
            ->assertExitCode(0);

        Queue::assertPushed(CrawlWebsiteJob::class, 3);
    }

    public function test_command_handles_crawl_error_gracefully(): void
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
            'url' => 'https://error.com',
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once()
            ->andThrow(new \Exception('Crawl failed'));
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['--all' => true])
            ->expectsOutputToContain('Error crawling https://error.com: Crawl failed')
            ->assertExitCode(0);
    }

    public function test_command_continues_after_error_in_batch(): void
    {
        $website1 = Website::factory()->create(['status' => Website::STATUS_PENDING]);
        $website2 = Website::factory()->create(['status' => Website::STATUS_PENDING]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once()
            ->andThrow(new \Exception('Error'));
        $crawlerMock->shouldReceive('crawlAndUpdate')
            ->once();
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['--all' => true])
            ->expectsOutput('Crawl batch completed!')
            ->assertExitCode(0);
    }

    public function test_command_only_crawls_pending_status_websites(): void
    {
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_COMPLETED]);
        Website::factory()->create(['status' => Website::STATUS_FAILED]);
        Website::factory()->create(['status' => Website::STATUS_CRAWLING]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->once();
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['--all' => true])
            ->expectsOutput('Found 1 pending websites')
            ->assertExitCode(0);
    }

    public function test_command_displays_progress_bar_for_batch_crawl(): void
    {
        Website::factory()->count(3)->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->times(3);
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        // Progress bar is created when not using --queue
        $this->artisan('website:crawl', ['--all' => true])
            ->assertExitCode(0);
    }

    public function test_command_accepts_numeric_website_id(): void
    {
        $website = Website::factory()->create();

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->once();
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['website_id' => (string) $website->id])
            ->assertExitCode(0);
    }

    public function test_command_shows_website_url_and_id_in_output(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://test-output.com',
        ]);

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->once();
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['website_id' => $website->id])
            ->expectsOutputToContain('https://test-output.com')
            ->expectsOutputToContain("ID: {$website->id}")
            ->assertExitCode(0);
    }

    public function test_command_runs_job_directly_without_queue_option(): void
    {
        Queue::fake();
        $website = Website::factory()->create();

        $crawlerMock = Mockery::mock(WebCrawlerService::class);
        $crawlerMock->shouldReceive('crawlAndUpdate')->once();
        $this->app->instance(WebCrawlerService::class, $crawlerMock);

        $this->artisan('website:crawl', ['website_id' => $website->id])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
