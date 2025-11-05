<?php

namespace Tests\Unit\Services;

use App\Models\Website;
use App\Models\WebsiteRequirement;
use App\Services\ContentExtractionService;
use App\Services\CriteriaEvaluator;
use App\Services\RequirementsMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class RequirementsMatcherServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RequirementsMatcherService $service;
    protected $contentExtractorMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contentExtractorMock = Mockery::mock(ContentExtractionService::class);
        $this->service = new RequirementsMatcherService($this->contentExtractorMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_evaluates_website_against_all_active_requirements()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'detected_platform' => 'wordpress',
            'word_count' => 1000,
            'content_snapshot' => 'test content with keyword',
        ]);

        $requirement1 = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 10],
        ]);

        $requirement2 = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['platforms' => ['wordpress']],
        ]);

        // Inactive requirement should be ignored
        WebsiteRequirement::factory()->create([
            'is_active' => false,
            'criteria' => ['min_pages' => 100],
        ]);

        $results = $this->service->evaluateWebsite($website);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['matches']);
        $this->assertTrue($results[1]['matches']);

        // Check pivot table was updated
        $this->assertDatabaseHas('website_requirement', [
            'website_id' => $website->id,
            'requirement_id' => $requirement1->id,
            'matches' => true,
        ]);

        $this->assertDatabaseHas('website_requirement', [
            'website_id' => $website->id,
            'requirement_id' => $requirement2->id,
            'matches' => true,
        ]);

        // Website should be marked as meeting requirements
        $this->assertTrue($website->fresh()->meets_requirements);
    }

    /** @test */
    public function it_updates_website_qualification_status_to_false_when_no_matches()
    {
        $website = Website::factory()->create([
            'page_count' => 5,
            'meets_requirements' => true, // Start as true
        ]);

        WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 100], // Won't match
        ]);

        $results = $this->service->evaluateWebsite($website);

        $this->assertFalse($results[0]['matches']);
        $this->assertFalse($website->fresh()->meets_requirements);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_min_pages_criteria()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['min_pages' => 10],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertEquals($requirement->id, $result['requirement_id']);
        $this->assertEquals($requirement->name, $result['requirement_name']);
        $this->assertTrue($result['details']['min_pages']['matched']);
        $this->assertEquals(100.0, $result['score']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_max_pages_criteria()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['max_pages' => 100],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['max_pages']['matched']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_platform_criteria()
    {
        $website = Website::factory()->create(['detected_platform' => 'wordpress']);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['platforms' => ['wordpress', 'shopify']],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['platforms']['matched']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_min_word_count_criteria()
    {
        $website = Website::factory()->create(['word_count' => 1000]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['min_word_count' => 500],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['min_word_count']['matched']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_max_word_count_criteria()
    {
        $website = Website::factory()->create(['word_count' => 800]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['max_word_count' => 1000],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['max_word_count']['matched']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_required_keywords()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'This is a blog about technology and programming',
        ]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['required_keywords' => ['technology', 'programming']],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['required_keywords']['matched']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_excluded_keywords()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'This is a blog about technology',
        ]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['excluded_keywords' => ['gambling', 'casino']],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['excluded_keywords']['matched']);
    }

    /** @test */
    public function it_evaluates_against_requirement_with_required_urls()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Visit our site at example.com/contact',
        ]);
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => ['required_urls' => ['example.com', 'contact']],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertTrue($result['details']['required_urls']['matched']);
    }

    /** @test */
    public function it_evaluates_all_criteria_together()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'detected_platform' => 'wordpress',
            'word_count' => 1000,
            'content_snapshot' => 'This is about technology and has example.com',
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => [
                'min_pages' => 10,
                'max_pages' => 100,
                'platforms' => ['wordpress'],
                'min_word_count' => 500,
                'max_word_count' => 2000,
                'required_keywords' => ['technology'],
                'excluded_keywords' => ['gambling'],
                'required_urls' => ['example.com'],
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertEquals(100.0, $result['score']);
        $this->assertCount(8, $result['details']);
    }

    /** @test */
    public function it_fails_when_any_criterion_does_not_match()
    {
        $website = Website::factory()->create([
            'page_count' => 5, // Fails min_pages
            'detected_platform' => 'wordpress',
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => [
                'min_pages' => 10,
                'platforms' => ['wordpress'],
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertFalse($result['matches']);
        $this->assertFalse($result['details']['min_pages']['matched']);
        $this->assertTrue($result['details']['platforms']['matched']);
        $this->assertEquals(50.0, $result['score']); // 1 out of 2 matched
    }

    /** @test */
    public function it_calculates_score_correctly()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'word_count' => 400, // Fails min_word_count
            'detected_platform' => 'wordpress',
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => [
                'min_pages' => 10, // Passes
                'platforms' => ['wordpress'], // Passes
                'min_word_count' => 500, // Fails
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertFalse($result['matches']);
        $this->assertEquals(66.67, $result['score']); // 2 out of 3 matched
    }

    /** @test */
    public function it_returns_zero_score_for_empty_criteria()
    {
        $website = Website::factory()->create();
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => null,
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']); // No criteria means all match
        $this->assertEquals(0, $result['score']);
        $this->assertEmpty($result['details']);
    }

    /** @test */
    public function it_handles_missing_website_data_gracefully()
    {
        $website = Website::factory()->create([
            'page_count' => null,
            'word_count' => null,
            'content_snapshot' => null,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => [
                'min_pages' => 10,
                'min_word_count' => 500,
                'required_keywords' => ['test'],
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertFalse($result['matches']);
        $this->assertFalse($result['details']['min_pages']['matched']);
        $this->assertFalse($result['details']['min_word_count']['matched']);
        $this->assertFalse($result['details']['required_keywords']['matched']);
    }

    /** @test */
    public function it_syncs_without_detaching_existing_requirements()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $requirement1 = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 10],
        ]);
        $requirement2 = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 20],
        ]);

        // First evaluation
        $this->service->evaluateWebsite($website);

        // Verify both requirements are attached
        $this->assertEquals(2, $website->requirements()->count());

        // Second evaluation should not remove existing attachments
        $this->service->evaluateWebsite($website);

        $this->assertEquals(2, $website->requirements()->count());
    }

    /** @test */
    public function it_updates_existing_pivot_data_on_reevaluation()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 10],
        ]);

        // First evaluation - should match
        $this->service->evaluateWebsite($website);

        $pivot = $website->requirements()->where('requirement_id', $requirement->id)->first()->pivot;
        $this->assertTrue($pivot->matches);

        // Update website to fail requirement
        $website->update(['page_count' => 5]);

        // Re-evaluate
        $this->service->evaluateWebsite($website);

        $pivot = $website->requirements()->where('requirement_id', $requirement->id)->first()->pivot;
        $this->assertFalse($pivot->matches);
    }

    /** @test */
    public function it_stores_match_details_as_json_in_pivot()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 10, 'max_pages' => 100],
        ]);

        $this->service->evaluateWebsite($website);

        $pivot = $website->requirements()->where('requirement_id', $requirement->id)->first()->pivot;
        $details = json_decode($pivot->match_details, true);

        $this->assertIsArray($details);
        $this->assertArrayHasKey('min_pages', $details);
        $this->assertArrayHasKey('max_pages', $details);
    }

    /** @test */
    public function it_handles_empty_active_requirements()
    {
        $website = Website::factory()->create(['page_count' => 50]);

        // All requirements are inactive
        WebsiteRequirement::factory()->create(['is_active' => false]);

        $results = $this->service->evaluateWebsite($website);

        $this->assertEmpty($results);
        $this->assertFalse($website->fresh()->meets_requirements);
    }

    /** @test */
    public function it_uses_criteria_evaluator_for_evaluations()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'detected_platform' => 'wordpress',
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => [
                'min_pages' => 10,
                'platforms' => ['wordpress'],
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        // Verify CriteriaEvaluator methods were called by checking the result structure
        $this->assertArrayHasKey('min_pages', $result['details']);
        $this->assertArrayHasKey('platforms', $result['details']);
        $this->assertEquals('min_pages', $result['details']['min_pages']['criterion']);
        $this->assertEquals('platform', $result['details']['platforms']['criterion']);
    }
}
