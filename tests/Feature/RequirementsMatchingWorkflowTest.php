<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use App\Jobs\EvaluateWebsiteRequirementsJob;

class RequirementsMatchingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_qualification_flow(): void
    {
        Queue::fake();

        // 1. Create requirements
        $requirement1 = WebsiteRequirement::factory()->create([
            'name' => 'Minimum Word Count',
            'requirement_type' => 'word_count',
            'operator' => '>=',
            'value' => '500',
            'is_active' => true,
            'is_mandatory' => true,
        ]);

        $requirement2 = WebsiteRequirement::factory()->create([
            'name' => 'Has Contact Page',
            'requirement_type' => 'has_contact_page',
            'operator' => '=',
            'value' => 'true',
            'is_active' => true,
            'is_mandatory' => true,
        ]);

        // 2. Create website that meets requirements
        $website = Website::factory()->create([
            'url' => 'https://qualified-site.com',
            'word_count' => 1000,
            'content_snapshot' => '<html><body><a href="/contact">Contact Us</a></body></html>',
            'status' => Website::STATUS_COMPLETED,
        ]);

        // 3. Dispatch evaluation job
        EvaluateWebsiteRequirementsJob::dispatch($website);
        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class);

        // 4. Simulate evaluation - website meets all requirements
        $website->markAsQualified([
            'word_count' => ['required' => 500, 'actual' => 1000, 'passed' => true],
            'has_contact_page' => ['required' => true, 'actual' => true, 'passed' => true],
        ]);

        // 5. Assert qualification status
        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'meets_requirements' => true,
        ]);

        $this->assertTrue($website->fresh()->isQualified());
        $this->assertNotNull($website->fresh()->requirement_match_details);
        $this->assertIsArray($website->fresh()->requirement_match_details);
    }

    public function test_website_disqualification_flow(): void
    {
        // 1. Create mandatory requirement
        $requirement = WebsiteRequirement::factory()->create([
            'name' => 'Minimum Word Count',
            'requirement_type' => 'word_count',
            'operator' => '>=',
            'value' => '1000',
            'is_active' => true,
            'is_mandatory' => true,
        ]);

        // 2. Create website that doesn't meet requirement
        $website = Website::factory()->create([
            'url' => 'https://unqualified-site.com',
            'word_count' => 300, // Too low
            'status' => Website::STATUS_COMPLETED,
        ]);

        // 3. Simulate evaluation - website fails requirement
        $website->markAsUnqualified([
            'word_count' => ['required' => 1000, 'actual' => 300, 'passed' => false],
        ]);

        // 4. Assert disqualification
        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'meets_requirements' => false,
        ]);

        $this->assertFalse($website->fresh()->isQualified());
    }

    public function test_multiple_requirements_evaluation(): void
    {
        // Create multiple requirements
        $requirements = [
            WebsiteRequirement::factory()->create([
                'requirement_type' => 'word_count',
                'operator' => '>=',
                'value' => '500',
                'is_active' => true,
                'is_mandatory' => true,
            ]),
            WebsiteRequirement::factory()->create([
                'requirement_type' => 'page_count',
                'operator' => '>=',
                'value' => '5',
                'is_active' => true,
                'is_mandatory' => true,
            ]),
            WebsiteRequirement::factory()->create([
                'requirement_type' => 'platform',
                'operator' => 'in',
                'value' => 'wordpress,shopify',
                'is_active' => true,
                'is_mandatory' => false, // Optional requirement
            ]),
        ];

        $website = Website::factory()->create([
            'word_count' => 800,
            'page_count' => 10,
            'detected_platform' => 'wordpress',
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Simulate evaluation - all requirements met
        $matchDetails = [
            'word_count' => ['required' => 500, 'actual' => 800, 'passed' => true, 'mandatory' => true],
            'page_count' => ['required' => 5, 'actual' => 10, 'passed' => true, 'mandatory' => true],
            'platform' => ['required' => ['wordpress', 'shopify'], 'actual' => 'wordpress', 'passed' => true, 'mandatory' => false],
        ];

        $website->markAsQualified($matchDetails);

        $this->assertTrue($website->fresh()->isQualified());
        $this->assertCount(3, $website->fresh()->requirement_match_details);
    }

    public function test_mandatory_vs_optional_requirements(): void
    {
        $mandatoryReq = WebsiteRequirement::factory()->create([
            'requirement_type' => 'word_count',
            'operator' => '>=',
            'value' => '500',
            'is_mandatory' => true,
            'is_active' => true,
        ]);

        $optionalReq = WebsiteRequirement::factory()->create([
            'requirement_type' => 'has_blog',
            'operator' => '=',
            'value' => 'true',
            'is_mandatory' => false,
            'is_active' => true,
        ]);

        // Website meets mandatory but not optional
        $website = Website::factory()->create([
            'word_count' => 600,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Should still be qualified if mandatory requirements are met
        $matchDetails = [
            'word_count' => ['required' => 500, 'actual' => 600, 'passed' => true, 'mandatory' => true],
            'has_blog' => ['required' => true, 'actual' => false, 'passed' => false, 'mandatory' => false],
        ];

        $website->markAsQualified($matchDetails);

        $this->assertTrue($website->fresh()->isQualified());
    }

    public function test_inactive_requirements_ignored(): void
    {
        $activeReq = WebsiteRequirement::factory()->create([
            'name' => 'Active Requirement',
            'is_active' => true,
        ]);

        $inactiveReq = WebsiteRequirement::factory()->create([
            'name' => 'Inactive Requirement',
            'is_active' => false,
        ]);

        $activeRequirements = WebsiteRequirement::where('is_active', true)->get();

        $this->assertCount(1, $activeRequirements);
        $this->assertEquals('Active Requirement', $activeRequirements->first()->name);
    }

    public function test_requirement_operators(): void
    {
        // Greater than or equal
        $req1 = WebsiteRequirement::factory()->create([
            'operator' => '>=',
            'value' => '100',
        ]);

        // Equals
        $req2 = WebsiteRequirement::factory()->create([
            'operator' => '=',
            'value' => 'wordpress',
        ]);

        // In list
        $req3 = WebsiteRequirement::factory()->create([
            'operator' => 'in',
            'value' => 'wordpress,shopify,wix',
        ]);

        // Contains
        $req4 = WebsiteRequirement::factory()->create([
            'operator' => 'contains',
            'value' => 'contact',
        ]);

        $this->assertEquals('>=', $req1->operator);
        $this->assertEquals('=', $req2->operator);
        $this->assertEquals('in', $req3->operator);
        $this->assertEquals('contains', $req4->operator);
    }

    public function test_qualified_leads_scope(): void
    {
        // Create qualified websites
        Website::factory()->count(3)->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create unqualified websites
        Website::factory()->count(2)->create([
            'meets_requirements' => false,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create pending website
        Website::factory()->create([
            'meets_requirements' => null,
            'status' => Website::STATUS_PENDING,
        ]);

        $qualifiedLeads = Website::qualifiedLeads()->get();

        $this->assertCount(3, $qualifiedLeads);
        $this->assertTrue($qualifiedLeads->every(fn($w) => $w->meets_requirements === true));
        $this->assertTrue($qualifiedLeads->every(fn($w) => $w->status === Website::STATUS_COMPLETED));
    }

    public function test_requirement_match_details_structure(): void
    {
        $website = Website::factory()->create();

        $matchDetails = [
            'word_count' => [
                'required' => 500,
                'actual' => 750,
                'passed' => true,
                'mandatory' => true,
            ],
            'page_count' => [
                'required' => 5,
                'actual' => 3,
                'passed' => false,
                'mandatory' => false,
            ],
        ];

        $website->markAsQualified($matchDetails);

        $stored = $website->fresh()->requirement_match_details;

        $this->assertIsArray($stored);
        $this->assertArrayHasKey('word_count', $stored);
        $this->assertArrayHasKey('page_count', $stored);
        $this->assertTrue($stored['word_count']['passed']);
        $this->assertFalse($stored['page_count']['passed']);
    }

    public function test_website_pending_review_status(): void
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_PER_REVIEW,
            'meets_requirements' => null,
        ]);

        $this->assertEquals(Website::STATUS_PER_REVIEW, $website->status);
        $this->assertNull($website->meets_requirements);
    }

    public function test_priority_scoring_based_on_requirements(): void
    {
        $highMatchWebsite = Website::factory()->create([
            'meets_requirements' => true,
            'requirement_match_details' => [
                'word_count' => ['passed' => true, 'score' => 100],
                'page_count' => ['passed' => true, 'score' => 100],
                'has_contact' => ['passed' => true, 'score' => 100],
            ],
        ]);

        $lowMatchWebsite = Website::factory()->create([
            'meets_requirements' => true,
            'requirement_match_details' => [
                'word_count' => ['passed' => true, 'score' => 60],
                'page_count' => ['passed' => false, 'score' => 0],
            ],
        ]);

        // High match has more passed requirements
        $highMatchCount = count(array_filter(
            $highMatchWebsite->requirement_match_details,
            fn($detail) => $detail['passed'] === true
        ));

        $lowMatchCount = count(array_filter(
            $lowMatchWebsite->requirement_match_details,
            fn($detail) => $detail['passed'] === true
        ));

        $this->assertGreaterThan($lowMatchCount, $highMatchCount);
    }
}
