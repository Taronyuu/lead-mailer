<?php

namespace Tests\Unit\Commands;

use App\Models\BlacklistEntry;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailReviewQueue;
use App\Models\EmailSentLog;
use App\Models\SmtpCredential;
use App\Models\Website;
use App\Services\BlacklistService;
use App\Services\ReviewQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SystemStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_displays_system_statistics(): void
    {
        $this->setupTestData();

        $this->artisan('system:stats')
            ->expectsOutput('=== System Statistics ===')
            ->assertExitCode(0);
    }

    public function test_command_displays_domain_statistics(): void
    {
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsOutputToContain('DOMAINS')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 2],
                ['Pending', 1],
                ['Active', 1],
                ['Processed', 0],
                ['Failed', 0],
                ['Blocked', 0],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_website_statistics(): void
    {
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_COMPLETED, 'meets_requirements' => true]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsOutputToContain('WEBSITES')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 2],
                ['Pending Crawl', 1],
                ['Crawling', 0],
                ['Completed', 1],
                ['Failed', 0],
                ['Qualified Leads', 1],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_contact_statistics(): void
    {
        Contact::factory()->create(['is_validated' => false, 'contacted' => false]);
        Contact::factory()->create(['is_validated' => true, 'is_valid' => true, 'contacted' => false]);
        Contact::factory()->create(['is_validated' => true, 'is_valid' => true, 'contacted' => true]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsOutputToContain('CONTACTS')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 3],
                ['Validated', 2],
                ['Valid', 2],
                ['Contacted', 1],
                ['Pending Contact', 1],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_email_statistics(): void
    {
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_SENT, 'sent_at' => now()]);
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_FAILED, 'sent_at' => now()->subDays(2)]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsOutputToContain('EMAILS')
            ->expectsTable(['Metric', 'Count'], [
                ['Total Sent', 2],
                ['Successful', 1],
                ['Failed', 1],
                ['Sent Today', 1],
                ['Sent This Week', 2],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_review_queue_statistics(): void
    {
        $reviewStats = [
            'total_entries' => 10,
            'pending' => 5,
            'approved' => 3,
            'rejected' => 1,
            'sent' => 1,
            'failed' => 0,
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('getStatistics')
            ->once()
            ->andReturn($reviewStats);

        $blacklistServiceMock = Mockery::mock(BlacklistService::class);
        $blacklistServiceMock->shouldReceive('getStatistics')
            ->once()
            ->andReturn([
                'total_entries' => 0,
                'active_entries' => 0,
                'email_entries' => 0,
                'domain_entries' => 0,
                'auto_entries' => 0,
            ]);

        $this->app->instance(ReviewQueueService::class, $reviewServiceMock);
        $this->app->instance(BlacklistService::class, $blacklistServiceMock);

        $this->artisan('system:stats')
            ->expectsOutputToContain('REVIEW QUEUE')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 10],
                ['Pending', 5],
                ['Approved', 3],
                ['Rejected', 1],
                ['Sent', 1],
                ['Failed', 0],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_blacklist_statistics(): void
    {
        $blacklistStats = [
            'total_entries' => 15,
            'active_entries' => 12,
            'email_entries' => 8,
            'domain_entries' => 4,
            'auto_entries' => 5,
        ];

        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('getStatistics')
            ->once()
            ->andReturn([
                'total_entries' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'sent' => 0,
                'failed' => 0,
            ]);

        $blacklistServiceMock = Mockery::mock(BlacklistService::class);
        $blacklistServiceMock->shouldReceive('getStatistics')
            ->once()
            ->andReturn($blacklistStats);

        $this->app->instance(ReviewQueueService::class, $reviewServiceMock);
        $this->app->instance(BlacklistService::class, $blacklistServiceMock);

        $this->artisan('system:stats')
            ->expectsOutputToContain('BLACKLIST')
            ->expectsTable(['Type', 'Count'], [
                ['Total Entries', 15],
                ['Active', 12],
                ['Email Entries', 8],
                ['Domain Entries', 4],
                ['Auto-Added', 5],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_smtp_statistics(): void
    {
        SmtpCredential::factory()->create(['is_active' => true]);
        SmtpCredential::factory()->create(['is_active' => false]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsOutputToContain('SMTP ACCOUNTS')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 2],
                ['Active', 1],
                ['Available', 1],
            ])
            ->assertExitCode(0);
    }

    public function test_command_outputs_json_format(): void
    {
        $this->setupTestData();

        $output = $this->artisan('system:stats', ['--json' => true])
            ->assertExitCode(0)
            ->run();

        // The command should output valid JSON
        $this->assertJson($this->artisan('system:stats', ['--json' => true])->run());
    }

    public function test_command_json_output_contains_all_sections(): void
    {
        $this->setupTestData();

        $this->artisan('system:stats', ['--json' => true])
            ->expectsOutputToContain('"domains"')
            ->expectsOutputToContain('"websites"')
            ->expectsOutputToContain('"contacts"')
            ->expectsOutputToContain('"emails"')
            ->expectsOutputToContain('"review_queue"')
            ->expectsOutputToContain('"blacklist"')
            ->expectsOutputToContain('"smtp"')
            ->assertExitCode(0);
    }

    public function test_command_calls_service_methods(): void
    {
        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('getStatistics')
            ->once()
            ->andReturn([
                'total_entries' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'sent' => 0,
                'failed' => 0,
            ]);

        $blacklistServiceMock = Mockery::mock(BlacklistService::class);
        $blacklistServiceMock->shouldReceive('getStatistics')
            ->once()
            ->andReturn([
                'total_entries' => 0,
                'active_entries' => 0,
                'email_entries' => 0,
                'domain_entries' => 0,
                'auto_entries' => 0,
            ]);

        $this->app->instance(ReviewQueueService::class, $reviewServiceMock);
        $this->app->instance(BlacklistService::class, $blacklistServiceMock);

        $this->artisan('system:stats')
            ->assertExitCode(0);
    }

    public function test_command_with_no_data(): void
    {
        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 0],
                ['Pending', 0],
                ['Active', 0],
                ['Processed', 0],
                ['Failed', 0],
                ['Blocked', 0],
            ])
            ->assertExitCode(0);
    }

    public function test_command_counts_qualified_leads_correctly(): void
    {
        Website::factory()->create(['meets_requirements' => true]);
        Website::factory()->create(['meets_requirements' => false]);
        Website::factory()->create(['meets_requirements' => true]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsOutputToContain('Qualified Leads')
            ->assertExitCode(0);

        $this->assertEquals(2, Website::where('meets_requirements', true)->count());
    }

    public function test_command_counts_emails_sent_today(): void
    {
        EmailSentLog::factory()->create(['sent_at' => today()]);
        EmailSentLog::factory()->create(['sent_at' => today()->subDay()]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->assertExitCode(0);

        $this->assertEquals(1, EmailSentLog::whereDate('sent_at', today())->count());
    }

    public function test_command_displays_all_domain_statuses(): void
    {
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);
        Domain::factory()->create(['status' => Domain::STATUS_PROCESSED]);
        Domain::factory()->create(['status' => Domain::STATUS_FAILED]);
        Domain::factory()->create(['status' => Domain::STATUS_BLOCKED]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 5],
                ['Pending', 1],
                ['Active', 1],
                ['Processed', 1],
                ['Failed', 1],
                ['Blocked', 1],
            ])
            ->assertExitCode(0);
    }

    public function test_command_displays_all_website_statuses(): void
    {
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_CRAWLING]);
        Website::factory()->create(['status' => Website::STATUS_COMPLETED]);
        Website::factory()->create(['status' => Website::STATUS_FAILED]);

        $this->mockServices();

        $this->artisan('system:stats')
            ->expectsTable(['Status', 'Count'], [
                ['Total', 4],
                ['Pending Crawl', 1],
                ['Crawling', 1],
                ['Completed', 1],
                ['Failed', 1],
                ['Qualified Leads', 0],
            ])
            ->assertExitCode(0);
    }

    protected function setupTestData(): void
    {
        Domain::factory()->create();
        Website::factory()->create();
        Contact::factory()->create();

        $this->mockServices();
    }

    protected function mockServices(): void
    {
        $reviewServiceMock = Mockery::mock(ReviewQueueService::class);
        $reviewServiceMock->shouldReceive('getStatistics')
            ->andReturn([
                'total_entries' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'sent' => 0,
                'failed' => 0,
            ]);

        $blacklistServiceMock = Mockery::mock(BlacklistService::class);
        $blacklistServiceMock->shouldReceive('getStatistics')
            ->andReturn([
                'total_entries' => 0,
                'active_entries' => 0,
                'email_entries' => 0,
                'domain_entries' => 0,
                'auto_entries' => 0,
            ]);

        $this->app->instance(ReviewQueueService::class, $reviewServiceMock);
        $this->app->instance(BlacklistService::class, $blacklistServiceMock);
    }
}
