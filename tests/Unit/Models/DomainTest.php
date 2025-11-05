<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Domain;
use App\Models\Website;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'domain',
            'tld',
            'status',
            'last_checked_at',
            'check_count',
            'notes',
        ];

        $domain = new Domain();

        $this->assertEquals($fillable, $domain->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $domain = Domain::factory()->create([
            'status' => '1',
            'check_count' => '5',
            'last_checked_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertIsInt($domain->status);
        $this->assertIsInt($domain->check_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $domain->last_checked_at);
    }

    public function test_it_uses_soft_deletes(): void
    {
        $domain = Domain::factory()->create();

        $domain->delete();

        $this->assertSoftDeleted('domains', ['id' => $domain->id]);
        $this->assertNotNull($domain->fresh()->deleted_at);
    }

    public function test_it_extracts_tld_on_create(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'tld' => null,
        ]);

        $this->assertEquals('com', $domain->tld);
    }

    public function test_it_does_not_override_tld_if_provided(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'tld' => 'custom',
        ]);

        $this->assertEquals('custom', $domain->tld);
    }

    public function test_extract_tld_method_works_correctly(): void
    {
        $this->assertEquals('com', Domain::extractTld('example.com'));
        $this->assertEquals('uk', Domain::extractTld('example.co.uk'));
        $this->assertEquals('org', Domain::extractTld('test.org'));
        $this->assertEquals('io', Domain::extractTld('github.io'));
    }

    public function test_it_has_websites_relationship(): void
    {
        $domain = Domain::factory()->create();
        $websites = Website::factory()->count(3)->create(['domain_id' => $domain->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $domain->websites);
        $this->assertCount(3, $domain->websites);
        $this->assertInstanceOf(Website::class, $domain->websites->first());
    }

    public function test_pending_scope_works(): void
    {
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $pending = Domain::pending()->get();

        $this->assertCount(2, $pending);
        $pending->each(fn($domain) => $this->assertEquals(Domain::STATUS_PENDING, $domain->status));
    }

    public function test_active_scope_works(): void
    {
        Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);

        $active = Domain::active()->get();

        $this->assertCount(2, $active);
        $active->each(fn($domain) => $this->assertEquals(Domain::STATUS_ACTIVE, $domain->status));
    }

    public function test_processed_scope_works(): void
    {
        Domain::factory()->create(['status' => Domain::STATUS_PROCESSED]);
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->create(['status' => Domain::STATUS_PROCESSED]);

        $processed = Domain::processed()->get();

        $this->assertCount(2, $processed);
        $processed->each(fn($domain) => $this->assertEquals(Domain::STATUS_PROCESSED, $domain->status));
    }

    public function test_failed_scope_works(): void
    {
        Domain::factory()->create(['status' => Domain::STATUS_FAILED]);
        Domain::factory()->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->create(['status' => Domain::STATUS_FAILED]);

        $failed = Domain::failed()->get();

        $this->assertCount(2, $failed);
        $failed->each(fn($domain) => $this->assertEquals(Domain::STATUS_FAILED, $domain->status));
    }

    public function test_mark_as_checked_increments_count(): void
    {
        $domain = Domain::factory()->create(['check_count' => 0]);

        $domain->markAsChecked();

        $this->assertEquals(1, $domain->fresh()->check_count);
    }

    public function test_mark_as_checked_updates_last_checked_at(): void
    {
        $domain = Domain::factory()->create(['last_checked_at' => null]);

        $domain->markAsChecked();

        $this->assertNotNull($domain->fresh()->last_checked_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $domain->fresh()->last_checked_at);
    }

    public function test_mark_as_active_changes_status(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $domain->markAsActive();

        $this->assertEquals(Domain::STATUS_ACTIVE, $domain->fresh()->status);
    }

    public function test_mark_as_processed_changes_status(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $domain->markAsProcessed();

        $this->assertEquals(Domain::STATUS_PROCESSED, $domain->fresh()->status);
    }

    public function test_mark_as_failed_changes_status(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $domain->markAsFailed('Test reason');

        $this->assertEquals(Domain::STATUS_FAILED, $domain->fresh()->status);
        $this->assertEquals('Test reason', $domain->fresh()->notes);
    }

    public function test_mark_as_failed_without_reason(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $domain->markAsFailed();

        $this->assertEquals(Domain::STATUS_FAILED, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->notes);
    }

    public function test_is_pending_returns_true_when_pending(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $this->assertTrue($domain->isPending());
    }

    public function test_is_pending_returns_false_when_not_pending(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);

        $this->assertFalse($domain->isPending());
    }

    public function test_is_active_returns_true_when_active(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_ACTIVE]);

        $this->assertTrue($domain->isActive());
    }

    public function test_is_active_returns_false_when_not_active(): void
    {
        $domain = Domain::factory()->create(['status' => Domain::STATUS_PENDING]);

        $this->assertFalse($domain->isActive());
    }

    public function test_status_constants_are_defined(): void
    {
        $this->assertEquals(0, Domain::STATUS_PENDING);
        $this->assertEquals(1, Domain::STATUS_ACTIVE);
        $this->assertEquals(2, Domain::STATUS_PROCESSED);
        $this->assertEquals(3, Domain::STATUS_FAILED);
        $this->assertEquals(4, Domain::STATUS_BLOCKED);
    }

    public function test_factory_creates_valid_domain(): void
    {
        $domain = Domain::factory()->create();

        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertNotNull($domain->domain);
        $this->assertNotNull($domain->tld);
        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
    }
}
