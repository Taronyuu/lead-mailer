<?php

namespace Tests\Unit\Commands;

use App\Models\BlacklistEntry;
use App\Services\BlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class BlacklistCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilePath = storage_path('app/test-blacklist.csv');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testFilePath)) {
            File::delete($this->testFilePath);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_returns_error_for_invalid_action(): void
    {
        $this->artisan('blacklist:manage', ['action' => 'invalid'])
            ->expectsOutput('Invalid action. Use: add, remove, list, import, or export')
            ->assertExitCode(1);
    }

    public function test_add_action_adds_email_to_blacklist(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $entry = BlacklistEntry::factory()->make(['id' => 1, 'value' => 'spam@example.com']);

        $serviceMock->shouldReceive('blacklistEmail')
            ->once()
            ->with('spam@example.com', 'Added via CLI', 'cli')
            ->andReturn($entry);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'add',
            'value' => 'spam@example.com',
            '--type' => 'email',
        ])
            ->expectsOutput('Added email to blacklist: spam@example.com (ID: 1)')
            ->assertExitCode(0);
    }

    public function test_add_action_adds_domain_to_blacklist(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $entry = BlacklistEntry::factory()->make(['id' => 1, 'value' => 'spam.com']);

        $serviceMock->shouldReceive('blacklistDomain')
            ->once()
            ->with('spam.com', 'Added via CLI', 'cli')
            ->andReturn($entry);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'add',
            'value' => 'spam.com',
            '--type' => 'domain',
        ])
            ->expectsOutput('Added domain to blacklist: spam.com (ID: 1)')
            ->assertExitCode(0);
    }

    public function test_add_action_requires_value_and_type(): void
    {
        $this->artisan('blacklist:manage', ['action' => 'add'])
            ->expectsOutput('Please provide both value and --type (email or domain)')
            ->assertExitCode(1);
    }

    public function test_add_action_validates_type(): void
    {
        $this->artisan('blacklist:manage', [
            'action' => 'add',
            'value' => 'test@example.com',
            '--type' => 'invalid',
        ])
            ->expectsOutput('Type must be either "email" or "domain"')
            ->assertExitCode(1);
    }

    public function test_add_action_uses_custom_reason(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $entry = BlacklistEntry::factory()->make(['id' => 1]);

        $serviceMock->shouldReceive('blacklistEmail')
            ->once()
            ->with('spam@example.com', 'Spam complaints', 'cli')
            ->andReturn($entry);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'add',
            'value' => 'spam@example.com',
            '--type' => 'email',
            '--reason' => 'Spam complaints',
        ])
            ->assertExitCode(0);
    }

    public function test_remove_action_removes_email_from_blacklist(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('removeEmailFromBlacklist')
            ->once()
            ->with('removed@example.com')
            ->andReturn(true);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'remove',
            'value' => 'removed@example.com',
            '--type' => 'email',
        ])
            ->expectsOutput('Removed email from blacklist: removed@example.com')
            ->assertExitCode(0);
    }

    public function test_remove_action_removes_domain_from_blacklist(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('removeDomainFromBlacklist')
            ->once()
            ->with('removed.com')
            ->andReturn(true);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'remove',
            'value' => 'removed.com',
            '--type' => 'domain',
        ])
            ->expectsOutput('Removed domain from blacklist: removed.com')
            ->assertExitCode(0);
    }

    public function test_remove_action_shows_warning_if_not_found(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('removeEmailFromBlacklist')
            ->once()
            ->andReturn(false);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'remove',
            'value' => 'notfound@example.com',
            '--type' => 'email',
        ])
            ->expectsOutput('Entry not found in blacklist: notfound@example.com')
            ->assertExitCode(0);
    }

    public function test_remove_action_requires_value_and_type(): void
    {
        $this->artisan('blacklist:manage', ['action' => 'remove'])
            ->expectsOutput('Please provide both value and --type (email or domain)')
            ->assertExitCode(1);
    }

    public function test_list_action_displays_blacklist_entries(): void
    {
        $entries = collect([
            (object) [
                'id' => 1,
                'type' => 'email',
                'value' => 'test@example.com',
                'reason' => 'Spam',
                'source' => 'manual',
                'created_at' => now(),
            ],
        ]);

        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('getActiveEntries')
            ->once()
            ->with(null)
            ->andReturn($entries);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', ['action' => 'list'])
            ->expectsOutput('Found 1 blacklist entries:')
            ->assertExitCode(0);
    }

    public function test_list_action_filters_by_type(): void
    {
        $entries = collect([
            (object) [
                'id' => 1,
                'type' => 'email',
                'value' => 'test@example.com',
                'reason' => 'Spam',
                'source' => 'manual',
                'created_at' => now(),
            ],
        ]);

        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('getActiveEntries')
            ->once()
            ->with('email')
            ->andReturn($entries);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'list',
            '--type' => 'email',
        ])
            ->assertExitCode(0);
    }

    public function test_list_action_shows_warning_when_empty(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('getActiveEntries')
            ->once()
            ->andReturn(collect([]));

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', ['action' => 'list'])
            ->expectsOutput('No blacklist entries found')
            ->assertExitCode(0);
    }

    public function test_import_action_imports_entries_from_file(): void
    {
        File::put($this->testFilePath, "spam@example.com\ntest@example.com");

        $result = [
            'imported' => 2,
            'skipped' => 0,
            'errors' => [],
        ];

        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('importFromCsv')
            ->once()
            ->with($this->testFilePath, 'email')
            ->andReturn($result);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'import',
            '--file' => $this->testFilePath,
            '--type' => 'email',
        ])
            ->expectsOutput('Import completed!')
            ->assertExitCode(0);
    }

    public function test_import_action_requires_file_and_type(): void
    {
        $this->artisan('blacklist:manage', ['action' => 'import'])
            ->expectsOutput('Please provide both --file and --type (email or domain)')
            ->assertExitCode(1);
    }

    public function test_import_action_fails_if_file_not_found(): void
    {
        $this->artisan('blacklist:manage', [
            'action' => 'import',
            '--file' => '/non/existent/file.csv',
            '--type' => 'email',
        ])
            ->expectsOutput('File not found: /non/existent/file.csv')
            ->assertExitCode(1);
    }

    public function test_import_action_displays_import_statistics(): void
    {
        File::put($this->testFilePath, "test@example.com");

        $result = [
            'imported' => 5,
            'skipped' => 2,
            'errors' => ['Error 1'],
        ];

        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('importFromCsv')
            ->once()
            ->andReturn($result);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'import',
            '--file' => $this->testFilePath,
            '--type' => 'email',
        ])
            ->expectsTable(['Metric', 'Count'], [
                ['Imported', 5],
                ['Skipped', 2],
                ['Errors', 1],
            ])
            ->assertExitCode(0);
    }

    public function test_import_action_displays_errors(): void
    {
        File::put($this->testFilePath, "test@example.com");

        $result = [
            'imported' => 1,
            'skipped' => 0,
            'errors' => ['Error importing entry 1', 'Error importing entry 2'],
        ];

        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('importFromCsv')
            ->once()
            ->andReturn($result);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'import',
            '--file' => $this->testFilePath,
            '--type' => 'email',
        ])
            ->expectsOutputToContain('Errors:')
            ->expectsOutputToContain('Error importing entry 1')
            ->expectsOutputToContain('Error importing entry 2')
            ->assertExitCode(0);
    }

    public function test_export_action_exports_entries_to_file(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('exportToCsv')
            ->once()
            ->with($this->testFilePath, null)
            ->andReturn(10);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'export',
            '--file' => $this->testFilePath,
        ])
            ->expectsOutput('Exported 10 entries successfully!')
            ->assertExitCode(0);
    }

    public function test_export_action_filters_by_type(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('exportToCsv')
            ->once()
            ->with($this->testFilePath, 'domain')
            ->andReturn(5);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'export',
            '--file' => $this->testFilePath,
            '--type' => 'domain',
        ])
            ->assertExitCode(0);
    }

    public function test_export_action_requires_file(): void
    {
        $this->artisan('blacklist:manage', ['action' => 'export'])
            ->expectsOutput('Please provide --file for export')
            ->assertExitCode(1);
    }

    public function test_export_action_shows_file_path_in_output(): void
    {
        $serviceMock = Mockery::mock(BlacklistService::class);
        $serviceMock->shouldReceive('exportToCsv')
            ->once()
            ->andReturn(0);

        $this->app->instance(BlacklistService::class, $serviceMock);

        $this->artisan('blacklist:manage', [
            'action' => 'export',
            '--file' => $this->testFilePath,
        ])
            ->expectsOutputToContain("Exporting blacklist entries to: {$this->testFilePath}")
            ->assertExitCode(0);
    }
}
