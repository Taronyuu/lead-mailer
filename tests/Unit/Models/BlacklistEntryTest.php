<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\BlacklistEntry;
use App\Models\User;

class BlacklistEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'type',
            'value',
            'reason',
            'source',
            'added_by_user_id',
        ];

        $entry = new BlacklistEntry();

        $this->assertEquals($fillable, $entry->getFillable());
    }

    public function test_it_does_not_use_soft_deletes(): void
    {
        $entry = BlacklistEntry::factory()->create();

        $entry->delete();

        $this->assertDatabaseMissing('blacklist_entries', ['id' => $entry->id]);
    }

    public function test_it_belongs_to_user_as_added_by(): void
    {
        $user = User::factory()->create();
        $entry = BlacklistEntry::factory()->create(['added_by_user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $entry->addedBy);
        $this->assertEquals($user->id, $entry->addedBy->id);
    }

    public function test_added_by_can_be_null(): void
    {
        $entry = BlacklistEntry::factory()->create(['added_by_user_id' => null]);

        $this->assertNull($entry->added_by_user_id);
        $this->assertNull($entry->addedBy);
    }

    public function test_domains_scope_works(): void
    {
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_DOMAIN]);
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_EMAIL]);
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_DOMAIN]);

        $domains = BlacklistEntry::domains()->get();

        $this->assertCount(2, $domains);
        $domains->each(fn($entry) => $this->assertEquals(BlacklistEntry::TYPE_DOMAIN, $entry->type));
    }

    public function test_emails_scope_works(): void
    {
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_EMAIL]);
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_DOMAIN]);
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_EMAIL]);

        $emails = BlacklistEntry::emails()->get();

        $this->assertCount(2, $emails);
        $emails->each(fn($entry) => $this->assertEquals(BlacklistEntry::TYPE_EMAIL, $entry->type));
    }

    public function test_type_constants_are_defined(): void
    {
        $this->assertEquals('domain', BlacklistEntry::TYPE_DOMAIN);
        $this->assertEquals('email', BlacklistEntry::TYPE_EMAIL);
    }

    public function test_source_constants_are_defined(): void
    {
        $this->assertEquals('manual', BlacklistEntry::SOURCE_MANUAL);
        $this->assertEquals('import', BlacklistEntry::SOURCE_IMPORT);
        $this->assertEquals('auto', BlacklistEntry::SOURCE_AUTO);
    }

    public function test_factory_creates_valid_blacklist_entry(): void
    {
        $entry = BlacklistEntry::factory()->create();

        $this->assertInstanceOf(BlacklistEntry::class, $entry);
        $this->assertNotNull($entry->type);
        $this->assertNotNull($entry->value);
        $this->assertDatabaseHas('blacklist_entries', ['id' => $entry->id]);
    }

    public function test_factory_can_create_domain_type(): void
    {
        $entry = BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_DOMAIN]);

        $this->assertEquals(BlacklistEntry::TYPE_DOMAIN, $entry->type);
    }

    public function test_factory_can_create_email_type(): void
    {
        $entry = BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_EMAIL]);

        $this->assertEquals(BlacklistEntry::TYPE_EMAIL, $entry->type);
    }

    public function test_factory_can_create_with_manual_source(): void
    {
        $entry = BlacklistEntry::factory()->create(['source' => BlacklistEntry::SOURCE_MANUAL]);

        $this->assertEquals(BlacklistEntry::SOURCE_MANUAL, $entry->source);
    }

    public function test_factory_can_create_with_import_source(): void
    {
        $entry = BlacklistEntry::factory()->create(['source' => BlacklistEntry::SOURCE_IMPORT]);

        $this->assertEquals(BlacklistEntry::SOURCE_IMPORT, $entry->source);
    }

    public function test_factory_can_create_with_auto_source(): void
    {
        $entry = BlacklistEntry::factory()->create(['source' => BlacklistEntry::SOURCE_AUTO]);

        $this->assertEquals(BlacklistEntry::SOURCE_AUTO, $entry->source);
    }

    public function test_reason_can_be_provided(): void
    {
        $entry = BlacklistEntry::factory()->create(['reason' => 'Spam domain']);

        $this->assertEquals('Spam domain', $entry->reason);
    }

    public function test_reason_can_be_null(): void
    {
        $entry = BlacklistEntry::factory()->create(['reason' => null]);

        $this->assertNull($entry->reason);
    }
}
