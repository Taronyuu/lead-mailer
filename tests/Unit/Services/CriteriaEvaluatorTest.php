<?php

namespace Tests\Unit\Services;

use App\Models\Website;
use App\Services\CriteriaEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriteriaEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_evaluates_min_pages_when_met()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinPages(10);

        $this->assertEquals('min_pages', $result['criterion']);
        $this->assertEquals(10, $result['required']);
        $this->assertEquals(50, $result['actual']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('Website has 50 pages', $result['message']);
        $this->assertStringContainsString('required: 10+', $result['message']);
    }

    /** @test */
    public function it_evaluates_min_pages_when_not_met()
    {
        $website = Website::factory()->create(['page_count' => 5]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinPages(10);

        $this->assertFalse($result['matched']);
        $this->assertEquals(5, $result['actual']);
        $this->assertStringContainsString('only 5 pages', $result['message']);
    }

    /** @test */
    public function it_evaluates_min_pages_with_null_page_count()
    {
        $website = Website::factory()->create(['page_count' => null]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinPages(10);

        $this->assertFalse($result['matched']);
        $this->assertEquals(0, $result['actual']);
    }

    /** @test */
    public function it_evaluates_min_pages_when_exactly_equal()
    {
        $website = Website::factory()->create(['page_count' => 10]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinPages(10);

        $this->assertTrue($result['matched']);
        $this->assertEquals(10, $result['actual']);
    }

    /** @test */
    public function it_evaluates_max_pages_when_met()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMaxPages(100);

        $this->assertEquals('max_pages', $result['criterion']);
        $this->assertEquals(100, $result['required']);
        $this->assertEquals(50, $result['actual']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('Website has 50 pages', $result['message']);
        $this->assertStringContainsString('limit: 100', $result['message']);
    }

    /** @test */
    public function it_evaluates_max_pages_when_not_met()
    {
        $website = Website::factory()->create(['page_count' => 150]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMaxPages(100);

        $this->assertFalse($result['matched']);
        $this->assertEquals(150, $result['actual']);
        $this->assertStringContainsString('150 pages', $result['message']);
    }

    /** @test */
    public function it_evaluates_max_pages_when_exactly_equal()
    {
        $website = Website::factory()->create(['page_count' => 100]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMaxPages(100);

        $this->assertTrue($result['matched']);
    }

    /** @test */
    public function it_evaluates_platform_when_allowed()
    {
        $website = Website::factory()->create(['detected_platform' => 'wordpress']);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluatePlatform(['wordpress', 'shopify']);

        $this->assertEquals('platform', $result['criterion']);
        $this->assertEquals(['wordpress', 'shopify'], $result['required']);
        $this->assertEquals('wordpress', $result['actual']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString("Platform 'wordpress' is allowed", $result['message']);
    }

    /** @test */
    public function it_evaluates_platform_when_not_allowed()
    {
        $website = Website::factory()->create(['detected_platform' => 'drupal']);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluatePlatform(['wordpress', 'shopify']);

        $this->assertFalse($result['matched']);
        $this->assertEquals('drupal', $result['actual']);
        $this->assertStringContainsString("not in allowed list", $result['message']);
        $this->assertStringContainsString('wordpress, shopify', $result['message']);
    }

    /** @test */
    public function it_evaluates_platform_with_single_allowed_platform()
    {
        $website = Website::factory()->create(['detected_platform' => 'wordpress']);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluatePlatform(['wordpress']);

        $this->assertTrue($result['matched']);
    }

    /** @test */
    public function it_evaluates_min_word_count_when_met()
    {
        $website = Website::factory()->create(['word_count' => 1000]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinWordCount(500);

        $this->assertEquals('min_word_count', $result['criterion']);
        $this->assertEquals(500, $result['required']);
        $this->assertEquals(1000, $result['actual']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('1000 words', $result['message']);
        $this->assertStringContainsString('required: 500+', $result['message']);
    }

    /** @test */
    public function it_evaluates_min_word_count_when_not_met()
    {
        $website = Website::factory()->create(['word_count' => 300]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinWordCount(500);

        $this->assertFalse($result['matched']);
        $this->assertEquals(300, $result['actual']);
        $this->assertStringContainsString('only 300 words', $result['message']);
    }

    /** @test */
    public function it_evaluates_min_word_count_with_null_value()
    {
        $website = Website::factory()->create(['word_count' => null]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinWordCount(500);

        $this->assertFalse($result['matched']);
        $this->assertEquals(0, $result['actual']);
    }

    /** @test */
    public function it_evaluates_max_word_count_when_met()
    {
        $website = Website::factory()->create(['word_count' => 800]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMaxWordCount(1000);

        $this->assertEquals('max_word_count', $result['criterion']);
        $this->assertEquals(1000, $result['required']);
        $this->assertEquals(800, $result['actual']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('800 words', $result['message']);
        $this->assertStringContainsString('limit: 1000', $result['message']);
    }

    /** @test */
    public function it_evaluates_max_word_count_when_not_met()
    {
        $website = Website::factory()->create(['word_count' => 1500]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMaxWordCount(1000);

        $this->assertFalse($result['matched']);
        $this->assertEquals(1500, $result['actual']);
        $this->assertStringContainsString('exceeds limit: 1000', $result['message']);
    }

    /** @test */
    public function it_evaluates_required_keywords_when_all_found()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'This is a blog about Technology and Programming for developers',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredKeywords(['technology', 'programming']);

        $this->assertEquals('required_keywords', $result['criterion']);
        $this->assertEquals(['technology', 'programming'], $result['required']);
        $this->assertEquals(['technology', 'programming'], $result['found']);
        $this->assertEmpty($result['missing']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('All required keywords found', $result['message']);
    }

    /** @test */
    public function it_evaluates_required_keywords_case_insensitively()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'This is about TECHNOLOGY and programming',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredKeywords(['Technology', 'PROGRAMMING']);

        $this->assertTrue($result['matched']);
        $this->assertCount(2, $result['found']);
    }

    /** @test */
    public function it_evaluates_required_keywords_when_some_missing()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'This is about technology',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredKeywords(['technology', 'programming', 'design']);

        $this->assertFalse($result['matched']);
        $this->assertEquals(['technology'], $result['found']);
        $this->assertEquals(['programming', 'design'], $result['missing']);
        $this->assertStringContainsString('Missing keywords', $result['message']);
        $this->assertStringContainsString('programming, design', $result['message']);
    }

    /** @test */
    public function it_evaluates_required_keywords_with_null_content()
    {
        $website = Website::factory()->create(['content_snapshot' => null]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredKeywords(['technology', 'programming']);

        $this->assertFalse($result['matched']);
        $this->assertEmpty($result['found']);
        $this->assertEquals(['technology', 'programming'], $result['missing']);
    }

    /** @test */
    public function it_evaluates_excluded_keywords_when_none_found()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'This is a clean blog about technology',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateExcludedKeywords(['gambling', 'casino', 'poker']);

        $this->assertEquals('excluded_keywords', $result['criterion']);
        $this->assertEquals(['gambling', 'casino', 'poker'], $result['excluded']);
        $this->assertEmpty($result['found']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('No excluded keywords found', $result['message']);
    }

    /** @test */
    public function it_evaluates_excluded_keywords_when_some_found()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Visit our casino for gambling entertainment',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateExcludedKeywords(['gambling', 'casino', 'poker']);

        $this->assertFalse($result['matched']);
        $this->assertContains('gambling', $result['found']);
        $this->assertContains('casino', $result['found']);
        $this->assertNotContains('poker', $result['found']);
        $this->assertStringContainsString('Found excluded keywords', $result['message']);
        $this->assertStringContainsString('gambling', $result['message']);
    }

    /** @test */
    public function it_evaluates_excluded_keywords_case_insensitively()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Our CASINO offers great GAMBLING',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateExcludedKeywords(['casino', 'gambling']);

        $this->assertFalse($result['matched']);
        $this->assertCount(2, $result['found']);
    }

    /** @test */
    public function it_evaluates_excluded_keywords_with_null_content()
    {
        $website = Website::factory()->create(['content_snapshot' => null]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateExcludedKeywords(['gambling', 'casino']);

        $this->assertTrue($result['matched']);
        $this->assertEmpty($result['found']);
    }

    /** @test */
    public function it_evaluates_required_urls_when_all_found()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Visit us at example.com/contact or example.com/about',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredUrls(['example.com', 'contact', 'about']);

        $this->assertEquals('required_urls', $result['criterion']);
        $this->assertEquals(['example.com', 'contact', 'about'], $result['required']);
        $this->assertEquals(['example.com', 'contact', 'about'], $result['found']);
        $this->assertEmpty($result['missing']);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('All required URLs found', $result['message']);
    }

    /** @test */
    public function it_evaluates_required_urls_when_some_missing()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Visit us at example.com',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredUrls(['example.com', 'contact', 'pricing']);

        $this->assertFalse($result['matched']);
        $this->assertEquals(['example.com'], $result['found']);
        $this->assertEquals(['contact', 'pricing'], $result['missing']);
        $this->assertStringContainsString('Missing URLs', $result['message']);
        $this->assertStringContainsString('contact, pricing', $result['message']);
    }

    /** @test */
    public function it_evaluates_required_urls_case_insensitively()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Visit EXAMPLE.COM/CONTACT',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredUrls(['example.com', 'contact']);

        $this->assertTrue($result['matched']);
        $this->assertCount(2, $result['found']);
    }

    /** @test */
    public function it_evaluates_required_urls_with_null_content()
    {
        $website = Website::factory()->create(['content_snapshot' => null]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredUrls(['example.com', 'contact']);

        $this->assertFalse($result['matched']);
        $this->assertEmpty($result['found']);
        $this->assertEquals(['example.com', 'contact'], $result['missing']);
    }

    /** @test */
    public function it_evaluates_required_urls_with_partial_matches()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Visit example.com/contact-us for more info',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        // "contact" should match "contact-us" as it's a substring
        $result = $evaluator->evaluateRequiredUrls(['contact']);

        $this->assertTrue($result['matched']);
        $this->assertContains('contact', $result['found']);
    }

    /** @test */
    public function it_handles_empty_keyword_arrays()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Some content',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredKeywords([]);
        $this->assertTrue($result['matched']);
        $this->assertEmpty($result['missing']);

        $result = $evaluator->evaluateExcludedKeywords([]);
        $this->assertTrue($result['matched']);
        $this->assertEmpty($result['found']);
    }

    /** @test */
    public function it_handles_empty_url_patterns_array()
    {
        $website = Website::factory()->create([
            'content_snapshot' => 'Some content',
        ]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateRequiredUrls([]);

        $this->assertTrue($result['matched']);
        $this->assertEmpty($result['missing']);
    }

    /** @test */
    public function it_evaluates_zero_page_count()
    {
        $website = Website::factory()->create(['page_count' => 0]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinPages(1);
        $this->assertFalse($result['matched']);

        $result = $evaluator->evaluateMaxPages(0);
        $this->assertTrue($result['matched']);
    }

    /** @test */
    public function it_evaluates_zero_word_count()
    {
        $website = Website::factory()->create(['word_count' => 0]);
        $evaluator = new CriteriaEvaluator($website);

        $result = $evaluator->evaluateMinWordCount(1);
        $this->assertFalse($result['matched']);

        $result = $evaluator->evaluateMaxWordCount(0);
        $this->assertTrue($result['matched']);
    }

    /** @test */
    public function it_constructs_with_website()
    {
        $website = Website::factory()->create(['page_count' => 50]);
        $evaluator = new CriteriaEvaluator($website);

        $reflection = new \ReflectionClass($evaluator);
        $property = $reflection->getProperty('website');
        $property->setAccessible(true);

        $this->assertSame($website, $property->getValue($evaluator));
    }
}
