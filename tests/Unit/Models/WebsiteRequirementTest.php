<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\WebsiteRequirement;

class WebsiteRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'name',
            'description',
            'criteria',
            'is_active',
            'priority',
        ];

        $requirement = new WebsiteRequirement();

        $this->assertEquals($fillable, $requirement->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['min_pages' => 10, 'platforms' => ['wordpress', 'shopify']],
            'is_active' => true,
            'priority' => '80',
        ]);

        $this->assertIsArray($requirement->criteria);
        $this->assertIsBool($requirement->is_active);
        $this->assertIsInt($requirement->priority);
    }

    public function test_it_uses_soft_deletes(): void
    {
        $requirement = WebsiteRequirement::factory()->create();

        $requirement->delete();

        $this->assertSoftDeleted('website_requirements', ['id' => $requirement->id]);
        $this->assertNotNull($requirement->fresh()->deleted_at);
    }

    public function test_active_scope_works(): void
    {
        WebsiteRequirement::factory()->create(['is_active' => true]);
        WebsiteRequirement::factory()->create(['is_active' => false]);
        WebsiteRequirement::factory()->create(['is_active' => true]);

        $active = WebsiteRequirement::active()->get();

        $this->assertCount(2, $active);
        $active->each(fn($requirement) => $this->assertTrue($requirement->is_active));
    }

    public function test_criteria_can_be_complex_array(): void
    {
        $criteria = [
            'min_pages' => 10,
            'max_pages' => 1000,
            'platforms' => ['wordpress', 'shopify'],
            'required_keywords' => ['seo', 'marketing'],
            'min_word_count' => 500,
        ];

        $requirement = WebsiteRequirement::factory()->create(['criteria' => $criteria]);

        $this->assertEquals($criteria, $requirement->fresh()->criteria);
        $this->assertIsArray($requirement->criteria);
        $this->assertEquals(10, $requirement->criteria['min_pages']);
        $this->assertIsArray($requirement->criteria['platforms']);
    }

    public function test_factory_creates_valid_website_requirement(): void
    {
        $requirement = WebsiteRequirement::factory()->create();

        $this->assertInstanceOf(WebsiteRequirement::class, $requirement);
        $this->assertNotNull($requirement->name);
        $this->assertIsArray($requirement->criteria);
        $this->assertDatabaseHas('website_requirements', ['id' => $requirement->id]);
    }

    public function test_factory_can_create_inactive_requirement(): void
    {
        $requirement = WebsiteRequirement::factory()->create(['is_active' => false]);

        $this->assertFalse($requirement->is_active);
    }

    public function test_factory_can_create_with_custom_priority(): void
    {
        $requirement = WebsiteRequirement::factory()->create(['priority' => 95]);

        $this->assertEquals(95, $requirement->priority);
    }
}
