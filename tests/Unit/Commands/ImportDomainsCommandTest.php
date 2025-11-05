<?php

namespace Tests\Unit\Commands;

use App\Jobs\CrawlWebsiteJob;
use App\Models\Domain;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilePath = storage_path('app/test-domains.txt');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testFilePath)) {
            File::delete($this->testFilePath);
        }
        parent::tearDown();
    }

    public function test_command_imports_domains_from_text_file(): void
    {
        File::put($this->testFilePath, "example.com\ntest.com\nanother.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->expectsOutput('Import completed!')
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'another.com']);
    }

    public function test_command_imports_domains_from_csv_file(): void
    {
        File::put($this->testFilePath, "example.com,Description\ntest.com,Test Site\n");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
    }

    public function test_command_skips_empty_lines(): void
    {
        File::put($this->testFilePath, "example.com\n\n\ntest.com\n\n");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertEquals(2, Domain::count());
    }

    public function test_command_skips_comment_lines(): void
    {
        File::put($this->testFilePath, "# This is a comment\nexample.com\n# Another comment\ntest.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertEquals(2, Domain::count());
    }

    public function test_command_cleans_http_prefix(): void
    {
        File::put($this->testFilePath, "http://example.com\nhttps://test.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
    }

    public function test_command_cleans_www_prefix(): void
    {
        File::put($this->testFilePath, "www.example.com\nhttps://www.test.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
    }

    public function test_command_cleans_trailing_slashes(): void
    {
        File::put($this->testFilePath, "example.com/\ntest.com//");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
    }

    public function test_command_skips_existing_domains(): void
    {
        Domain::factory()->create(['domain' => 'existing.com']);
        File::put($this->testFilePath, "existing.com\nnew.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertEquals(1, Domain::where('domain', 'existing.com')->count());
        $this->assertEquals(2, Domain::count());
    }

    public function test_command_displays_import_statistics(): void
    {
        File::put($this->testFilePath, "new1.com\nnew2.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->expectsTable(['Metric', 'Count'], [
                ['Imported', 2],
                ['Skipped', 0],
                ['Errors', 0],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_skipped_count(): void
    {
        Domain::factory()->create(['domain' => 'existing.com']);
        File::put($this->testFilePath, "existing.com\nnew.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->expectsTable(['Metric', 'Count'], [
                ['Imported', 1],
                ['Skipped', 1],
                ['Errors', 0],
            ])
            ->assertExitCode(0);
    }

    public function test_command_fails_if_file_not_found(): void
    {
        $this->artisan('domain:import', ['file' => '/non/existent/file.txt'])
            ->expectsOutput('File not found: /non/existent/file.txt')
            ->assertExitCode(1);
    }

    public function test_command_creates_websites_for_imported_domains(): void
    {
        File::put($this->testFilePath, "example.com\ntest.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('websites', ['url' => 'https://example.com']);
        $this->assertDatabaseHas('websites', ['url' => 'https://test.com']);
    }

    public function test_command_queues_crawl_jobs_with_queue_option(): void
    {
        Queue::fake();
        File::put($this->testFilePath, "example.com\ntest.com");

        $this->artisan('domain:import', [
            'file' => $this->testFilePath,
            '--queue' => true,
        ])
            ->expectsOutputToContain('Queued 2 crawl jobs')
            ->assertExitCode(0);

        Queue::assertPushed(CrawlWebsiteJob::class, 2);
    }

    public function test_command_does_not_queue_without_queue_option(): void
    {
        Queue::fake();
        File::put($this->testFilePath, "example.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        Queue::assertNotPushed(CrawlWebsiteJob::class);
    }

    public function test_command_handles_mixed_format_file(): void
    {
        File::put($this->testFilePath, "example.com\nhttp://www.test.com/\n# comment\n\nplain.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertEquals(3, Domain::count());
        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'plain.com']);
    }

    public function test_command_sets_pending_status_for_new_domains(): void
    {
        File::put($this->testFilePath, "example.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $domain = Domain::where('domain', 'example.com')->first();
        $this->assertEquals(Domain::STATUS_PENDING, $domain->status);
    }

    public function test_command_sets_pending_status_for_new_websites(): void
    {
        File::put($this->testFilePath, "example.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $website = Website::where('url', 'https://example.com')->first();
        $this->assertEquals(Website::STATUS_PENDING, $website->status);
    }

    public function test_command_shows_file_path_in_output(): void
    {
        File::put($this->testFilePath, "example.com");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->expectsOutputToContain('Importing domains from:')
            ->expectsOutputToContain($this->testFilePath)
            ->assertExitCode(0);
    }

    public function test_command_handles_whitespace_in_domains(): void
    {
        File::put($this->testFilePath, "  example.com  \n test.com \t");

        $this->artisan('domain:import', ['file' => $this->testFilePath])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('domains', ['domain' => 'test.com']);
    }

    public function test_command_queues_only_new_domains(): void
    {
        Queue::fake();
        Domain::factory()->create(['domain' => 'existing.com']);
        File::put($this->testFilePath, "existing.com\nnew.com");

        $this->artisan('domain:import', [
            'file' => $this->testFilePath,
            '--queue' => true,
        ])
            ->expectsOutputToContain('Queued 1 crawl jobs')
            ->assertExitCode(0);

        Queue::assertPushed(CrawlWebsiteJob::class, 1);
    }
}
