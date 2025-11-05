<?php

namespace Tests\Unit\Commands;

use App\Models\Domain;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddDomainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_adds_domain_and_website_successfully(): void
    {
        $this->artisan('domain:add', ['domain' => 'example.com'])
            ->expectsOutput('Domain created: example.com (ID: 1)')
            ->expectsOutput('Website created: https://example.com (ID: 1)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', [
            'domain' => 'example.com',
            'status' => Domain::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('websites', [
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);
    }

    public function test_command_adds_domain_with_custom_url(): void
    {
        $this->artisan('domain:add', [
            'domain' => 'example.com',
            '--url' => 'https://www.example.com/custom',
        ])
            ->expectsOutput('Domain created: example.com (ID: 1)')
            ->expectsOutput('Website created: https://www.example.com/custom (ID: 1)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('websites', [
            'url' => 'https://www.example.com/custom',
        ]);
    }

    public function test_command_fails_if_domain_already_exists(): void
    {
        Domain::factory()->create(['domain' => 'existing.com']);

        $this->artisan('domain:add', ['domain' => 'existing.com'])
            ->expectsOutput('Domain existing.com already exists (ID: 1)')
            ->assertExitCode(1);

        $this->assertEquals(1, Domain::where('domain', 'existing.com')->count());
    }

    public function test_command_shows_next_steps(): void
    {
        $this->artisan('domain:add', ['domain' => 'example.com'])
            ->expectsOutputToContain('Next steps:')
            ->expectsOutputToContain('1. Run: php artisan website:crawl 1')
            ->expectsOutputToContain('2. Or queue it: php artisan queue:work')
            ->assertExitCode(0);
    }

    public function test_command_creates_website_with_correct_domain_id(): void
    {
        $this->artisan('domain:add', ['domain' => 'test.com'])
            ->assertExitCode(0);

        $domain = Domain::where('domain', 'test.com')->first();
        $website = Website::where('domain_id', $domain->id)->first();

        $this->assertNotNull($website);
        $this->assertEquals($domain->id, $website->domain_id);
        $this->assertEquals('https://test.com', $website->url);
    }

    public function test_command_uses_default_https_protocol(): void
    {
        $this->artisan('domain:add', ['domain' => 'secure.com'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('websites', [
            'url' => 'https://secure.com',
        ]);
    }

    public function test_command_accepts_domain_argument(): void
    {
        $this->artisan('domain:add', ['domain' => 'argtest.com'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', [
            'domain' => 'argtest.com',
        ]);
    }

    public function test_command_handles_special_characters_in_domain(): void
    {
        $this->artisan('domain:add', ['domain' => 'test-domain.co.uk'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('domains', [
            'domain' => 'test-domain.co.uk',
        ]);
    }

    public function test_command_sets_correct_initial_status(): void
    {
        $this->artisan('domain:add', ['domain' => 'status-test.com'])
            ->assertExitCode(0);

        $domain = Domain::where('domain', 'status-test.com')->first();
        $website = Website::where('domain_id', $domain->id)->first();

        $this->assertEquals(Domain::STATUS_PENDING, $domain->status);
        $this->assertEquals(Website::STATUS_PENDING, $website->status);
    }

    public function test_command_output_includes_domain_name(): void
    {
        $this->artisan('domain:add', ['domain' => 'output-test.com'])
            ->expectsOutputToContain('output-test.com')
            ->assertExitCode(0);
    }

    public function test_command_output_includes_generated_ids(): void
    {
        $this->artisan('domain:add', ['domain' => 'id-test.com'])
            ->expectsOutputToContain('(ID: 1)')
            ->assertExitCode(0);
    }

    public function test_command_creates_relationship_between_domain_and_website(): void
    {
        $this->artisan('domain:add', ['domain' => 'relation-test.com'])
            ->assertExitCode(0);

        $domain = Domain::where('domain', 'relation-test.com')->first();
        $this->assertEquals(1, $domain->websites()->count());
        $this->assertEquals('https://relation-test.com', $domain->websites()->first()->url);
    }

    public function test_command_fails_for_duplicate_domain_case_sensitive(): void
    {
        Domain::factory()->create(['domain' => 'duplicate.com']);

        $this->artisan('domain:add', ['domain' => 'duplicate.com'])
            ->assertExitCode(1);

        $this->assertEquals(1, Domain::count());
    }
}
