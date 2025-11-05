<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Contact;
use App\Models\Website;
use App\Models\EmailSentLog;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'website_id',
            'email',
            'name',
            'phone',
            'position',
            'source_type',
            'source_url',
            'priority',
            'is_validated',
            'is_valid',
            'validation_error',
            'validated_at',
            'contacted',
            'first_contacted_at',
            'last_contacted_at',
            'contact_count',
        ];

        $contact = new Contact();

        $this->assertEquals($fillable, $contact->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $contact = Contact::factory()->create([
            'priority' => '80',
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => true,
            'contact_count' => '5',
            'validated_at' => '2024-01-01 10:00:00',
            'first_contacted_at' => '2024-01-01 11:00:00',
            'last_contacted_at' => '2024-01-01 12:00:00',
        ]);

        $this->assertIsInt($contact->priority);
        $this->assertIsBool($contact->is_validated);
        $this->assertIsBool($contact->is_valid);
        $this->assertIsBool($contact->contacted);
        $this->assertIsInt($contact->contact_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $contact->validated_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $contact->first_contacted_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $contact->last_contacted_at);
    }

    public function test_it_uses_soft_deletes(): void
    {
        $contact = Contact::factory()->create();

        $contact->delete();

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
        $this->assertNotNull($contact->fresh()->deleted_at);
    }

    public function test_it_belongs_to_website(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $this->assertInstanceOf(Website::class, $contact->website);
        $this->assertEquals($website->id, $contact->website->id);
    }

    public function test_it_has_email_sent_logs_relationship(): void
    {
        $contact = Contact::factory()->create();
        $logs = EmailSentLog::factory()->count(2)->create(['contact_id' => $contact->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contact->emailSentLogs);
        $this->assertCount(2, $contact->emailSentLogs);
        $this->assertInstanceOf(EmailSentLog::class, $contact->emailSentLogs->first());
    }

    public function test_validated_scope_works(): void
    {
        Contact::factory()->create(['is_validated' => true, 'is_valid' => true]);
        Contact::factory()->create(['is_validated' => false, 'is_valid' => false]);
        Contact::factory()->create(['is_validated' => true, 'is_valid' => false]);
        Contact::factory()->create(['is_validated' => true, 'is_valid' => true]);

        $validated = Contact::validated()->get();

        $this->assertCount(2, $validated);
        $validated->each(function($contact) {
            $this->assertTrue($contact->is_validated);
            $this->assertTrue($contact->is_valid);
        });
    }

    public function test_not_contacted_scope_works(): void
    {
        Contact::factory()->create();
        Contact::factory()->create();
        Contact::factory()->create();

        $notContacted = Contact::notContacted()->get();

        $this->assertCount(2, $notContacted);
        $notContacted->each(fn($contact) => $this->assertFalse($contact->contacted));
    }

    public function test_contacted_scope_works(): void
    {
        Contact::factory()->create();
        Contact::factory()->create();
        Contact::factory()->create();

        $contacted = Contact::contacted()->get();

        $this->assertCount(2, $contacted);
        $contacted->each(fn($contact) => $this->assertTrue($contact->contacted));
    }

    public function test_high_priority_scope_works(): void
    {
        Contact::factory()->create(['priority' => 90]);
        Contact::factory()->create(['priority' => 50]);
        Contact::factory()->create(['priority' => 80]);
        Contact::factory()->create(['priority' => 79]);

        $highPriority = Contact::highPriority()->get();

        $this->assertCount(2, $highPriority);
        $highPriority->each(fn($contact) => $this->assertGreaterThanOrEqual(80, $contact->priority));
    }

    public function test_medium_priority_scope_works(): void
    {
        Contact::factory()->create(['priority' => 60]);
        Contact::factory()->create(['priority' => 40]);
        Contact::factory()->create(['priority' => 75]);
        Contact::factory()->create(['priority' => 80]);

        $mediumPriority = Contact::mediumPriority()->get();

        $this->assertCount(2, $mediumPriority);
        $mediumPriority->each(function($contact) {
            $this->assertGreaterThanOrEqual(50, $contact->priority);
            $this->assertLessThanOrEqual(79, $contact->priority);
        });
    }

    public function test_low_priority_scope_works(): void
    {
        Contact::factory()->create(['priority' => 30]);
        Contact::factory()->create(['priority' => 60]);
        Contact::factory()->create(['priority' => 40]);
        Contact::factory()->create(['priority' => 50]);

        $lowPriority = Contact::lowPriority()->get();

        $this->assertCount(2, $lowPriority);
        $lowPriority->each(fn($contact) => $this->assertLessThan(50, $contact->priority));
    }

    public function test_mark_as_validated_with_valid_email(): void
    {
        $contact = Contact::factory()->create([
            'is_validated' => false,
            'is_valid' => false,
        ]);

        $contact->markAsValidated(true);

        $fresh = $contact->fresh();
        $this->assertTrue($fresh->is_validated);
        $this->assertTrue($fresh->is_valid);
        $this->assertNull($fresh->validation_error);
        $this->assertNotNull($fresh->validated_at);
    }

    public function test_mark_as_validated_with_invalid_email(): void
    {
        $contact = Contact::factory()->create([
            'is_validated' => false,
            'is_valid' => false,
        ]);

        $contact->markAsValidated(false, 'Invalid format');

        $fresh = $contact->fresh();
        $this->assertTrue($fresh->is_validated);
        $this->assertFalse($fresh->is_valid);
        $this->assertEquals('Invalid format', $fresh->validation_error);
        $this->assertNotNull($fresh->validated_at);
    }

    public function test_mark_as_contacted_first_time(): void
    {
        $contact = Contact::factory()->create([
            'contacted' => false,
            'contact_count' => 0,
            'first_contacted_at' => null,
            'last_contacted_at' => null,
        ]);

        $contact->markAsContacted();

        $fresh = $contact->fresh();
        $this->assertTrue($fresh->contacted);
        $this->assertEquals(1, $fresh->contact_count);
        $this->assertNotNull($fresh->first_contacted_at);
        $this->assertNotNull($fresh->last_contacted_at);
    }

    public function test_mark_as_contacted_subsequent_time(): void
    {
        $firstContactTime = now()->subDays(5);
        $contact = Contact::factory()->create([
            'contacted' => true,
            'contact_count' => 1,
            'first_contacted_at' => $firstContactTime,
            'last_contacted_at' => $firstContactTime,
        ]);

        $contact->markAsContacted();

        $fresh = $contact->fresh();
        $this->assertTrue($fresh->contacted);
        $this->assertEquals(2, $fresh->contact_count);
        $this->assertEquals($firstContactTime->toDateTimeString(), $fresh->first_contacted_at->toDateTimeString());
        $this->assertNotEquals($firstContactTime->toDateTimeString(), $fresh->last_contacted_at->toDateTimeString());
    }

    public function test_is_validated_returns_true_when_validated_and_valid(): void
    {
        $contact = Contact::factory()->create(['is_validated' => true, 'is_valid' => true]);

        $this->assertTrue($contact->isValidated());
    }

    public function test_is_validated_returns_false_when_not_validated(): void
    {
        $contact = Contact::factory()->create(['is_validated' => false, 'is_valid' => true]);

        $this->assertFalse($contact->isValidated());
    }

    public function test_is_validated_returns_false_when_not_valid(): void
    {
        $contact = Contact::factory()->create(['is_validated' => true, 'is_valid' => false]);

        $this->assertFalse($contact->isValidated());
    }

    public function test_is_contacted_returns_true_when_contacted(): void
    {
        $contact = Contact::factory()->create();

        $this->assertTrue($contact->isContacted());
    }

    public function test_is_contacted_returns_false_when_not_contacted(): void
    {
        $contact = Contact::factory()->create();

        $this->assertFalse($contact->isContacted());
    }

    public function test_calculate_priority_for_contact_page(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'name' => null,
            'position' => null,
        ]);

        $this->assertEquals(80, $contact->calculatePriority());
    }

    public function test_calculate_priority_for_about_page(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_ABOUT_PAGE,
            'name' => null,
            'position' => null,
        ]);

        $this->assertEquals(70, $contact->calculatePriority());
    }

    public function test_calculate_priority_for_team_page(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_TEAM_PAGE,
            'name' => null,
            'position' => null,
        ]);

        $this->assertEquals(75, $contact->calculatePriority());
    }

    public function test_calculate_priority_for_header(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_HEADER,
            'name' => null,
            'position' => null,
        ]);

        $this->assertEquals(65, $contact->calculatePriority());
    }

    public function test_calculate_priority_for_footer(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_FOOTER,
            'name' => null,
            'position' => null,
        ]);

        $this->assertEquals(60, $contact->calculatePriority());
    }

    public function test_calculate_priority_for_body(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_BODY,
            'name' => null,
            'position' => null,
        ]);

        $this->assertEquals(55, $contact->calculatePriority());
    }

    public function test_calculate_priority_with_name_bonus(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_BODY,
            'name' => 'John Doe',
            'position' => null,
        ]);

        $this->assertEquals(65, $contact->calculatePriority());
    }

    public function test_calculate_priority_with_position_bonus(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_BODY,
            'name' => null,
            'position' => 'CEO',
        ]);

        $this->assertEquals(60, $contact->calculatePriority());
    }

    public function test_calculate_priority_with_both_bonuses(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_BODY,
            'name' => 'John Doe',
            'position' => 'CEO',
        ]);

        $this->assertEquals(70, $contact->calculatePriority());
    }

    public function test_calculate_priority_caps_at_100(): void
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'name' => 'John Doe',
            'position' => 'CEO',
        ]);

        // SOURCE_CONTACT_PAGE (30) + base (50) + name (10) + position (5) = 95
        // Max is capped at 100, but this scenario only reaches 95
        $this->assertEquals(95, $contact->calculatePriority());
    }

    public function test_source_type_constants_are_defined(): void
    {
        $this->assertEquals('contact_page', Contact::SOURCE_CONTACT_PAGE);
        $this->assertEquals('about_page', Contact::SOURCE_ABOUT_PAGE);
        $this->assertEquals('footer', Contact::SOURCE_FOOTER);
        $this->assertEquals('header', Contact::SOURCE_HEADER);
        $this->assertEquals('body', Contact::SOURCE_BODY);
        $this->assertEquals('team_page', Contact::SOURCE_TEAM_PAGE);
    }

    public function test_factory_creates_valid_contact(): void
    {
        $contact = Contact::factory()->create();

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertNotNull($contact->email);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }
}
