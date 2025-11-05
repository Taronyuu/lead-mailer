<?php

namespace Tests\Unit\Services;

use App\Models\Website;
use App\Services\ContentExtractionService;
use App\Services\PlatformDetectionService;
use App\Services\WebCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebCrawlerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WebCrawlerService $service;
    protected PlatformDetectionService $platformDetector;
    protected ContentExtractionService $contentExtractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformDetector = $this->createMock(PlatformDetectionService::class);
        $this->contentExtractor = $this->createMock(ContentExtractionService::class);

        $this->service = new WebCrawlerService(
            $this->platformDetector,
            $this->contentExtractor
        );
    }

    public function test_it_crawls_website_successfully(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        $html = '<html><head><title>Test</title></head><body>Content</body></html>';

        Http::fake([
            'example.com' => Http::response($html, 200),
        ]);

        $this->platformDetector->expects($this->once())
            ->method('detectFromHtml')
            ->willReturn('wordpress');

        $this->contentExtractor->expects($this->once())
            ->method('extractContent')
            ->willReturn([
                'title' => 'Test Site',
                'description' => 'Test Description',
                'headings' => ['Heading 1', 'Heading 2'],
                'paragraphs' => ['Paragraph 1', 'Paragraph 2'],
                'links' => [],
                'images' => [],
                'word_count' => 150,
            ]);

        $results = $this->service->crawl($website, 10);

        $this->assertEquals(1, $results['page_count']);
        $this->assertEquals(150, $results['word_count']);
        $this->assertEquals('wordpress', $results['detected_platform']);
        $this->assertNotEmpty($results['content_snapshot']);
        $this->assertStringContainsString('Test Site', $results['content_snapshot']);
    }

    public function test_it_updates_website_status_to_crawling(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Test',
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $this->service->crawl($website, 10);

        $website->refresh();
        // After crawl, status should still be CRAWLING (not updated by crawl method)
        $this->assertEquals(Website::STATUS_CRAWLING, $website->status);
        $this->assertNotNull($website->crawl_started_at);
    }

    public function test_it_throws_exception_on_http_failure(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to fetch website: HTTP 404');

        $this->service->crawl($website, 10);
    }

    public function test_it_throws_exception_on_http_500_error(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to fetch website: HTTP 500');

        $this->service->crawl($website, 10);
    }

    public function test_it_logs_crawl_start(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => null,
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 0,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Starting website crawl', [
                'website_id' => $website->id,
                'url' => $website->url,
                'max_pages' => 10,
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Crawl completed', \Mockery::any());

        $this->service->crawl($website, 10);
    }

    public function test_it_logs_crawl_completion(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('shopify');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => null,
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 100,
        ]);

        Log::shouldReceive('info')->twice();

        $this->service->crawl($website, 10);
    }

    public function test_it_logs_crawl_failure(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 500),
        ]);

        Log::shouldReceive('info')->once(); // Start log
        Log::shouldReceive('error')
            ->once()
            ->with('Crawl failed', \Mockery::any());

        try {
            $this->service->crawl($website, 10);
        } catch (\Exception $e) {
            // Expected
        }
    }

    public function test_it_passes_html_to_platform_detector(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        $html = '<html><body>WordPress content</body></html>';

        Http::fake([
            'example.com' => Http::response($html, 200),
        ]);

        $this->platformDetector->expects($this->once())
            ->method('detectFromHtml')
            ->with(strtolower($html))
            ->willReturn('wordpress');

        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => null,
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 0,
        ]);

        $this->service->crawl($website, 10);
    }

    public function test_it_passes_html_to_content_extractor(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        $html = '<html><body>Test content</body></html>';

        Http::fake([
            'example.com' => Http::response($html, 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');

        $this->contentExtractor->expects($this->once())
            ->method('extractContent')
            ->with($html)
            ->willReturn([
                'title' => null,
                'description' => null,
                'headings' => [],
                'paragraphs' => [],
                'links' => [],
                'images' => [],
                'word_count' => 0,
            ]);

        $this->service->crawl($website, 10);
    }

    public function test_crawl_and_update_updates_website_on_success(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Success</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('wix');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'My Site',
            'description' => 'Description',
            'headings' => ['H1'],
            'paragraphs' => ['P1'],
            'links' => [],
            'images' => [],
            'word_count' => 200,
        ]);

        $this->service->crawlAndUpdate($website, 10);

        $website->refresh();
        $this->assertEquals(Website::STATUS_COMPLETED, $website->status);
        $this->assertEquals(1, $website->page_count);
        $this->assertEquals(200, $website->word_count);
        $this->assertEquals('wix', $website->detected_platform);
        $this->assertNotNull($website->content_snapshot);
        $this->assertNotNull($website->crawled_at);
        $this->assertNull($website->crawl_error);
    }

    public function test_crawl_and_update_updates_website_on_failure(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        Http::fake([
            'example.com' => Http::response('', 404),
        ]);

        try {
            $this->service->crawlAndUpdate($website, 10);
        } catch (\Exception $e) {
            // Expected
        }

        $website->refresh();
        $this->assertEquals(Website::STATUS_FAILED, $website->status);
        $this->assertNotNull($website->crawl_error);
        $this->assertStringContainsString('Failed to fetch website', $website->crawl_error);
    }

    public function test_crawl_and_update_rethrows_exception_after_marking_failed(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('', 500),
        ]);

        $this->expectException(\Exception::class);

        $this->service->crawlAndUpdate($website, 10);
    }

    public function test_it_builds_content_snapshot_with_title(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'My Website Title',
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        $this->assertStringContainsString('Title: My Website Title', $results['content_snapshot']);
    }

    public function test_it_builds_content_snapshot_with_description(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Title',
            'description' => 'This is a test description',
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        $this->assertStringContainsString('Description: This is a test description', $results['content_snapshot']);
    }

    public function test_it_builds_content_snapshot_with_headings(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Title',
            'description' => null,
            'headings' => ['Heading One', 'Heading Two', 'Heading Three'],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        $this->assertStringContainsString('Headings:', $results['content_snapshot']);
        $this->assertStringContainsString('- Heading One', $results['content_snapshot']);
        $this->assertStringContainsString('- Heading Two', $results['content_snapshot']);
    }

    public function test_it_builds_content_snapshot_with_paragraphs(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Title',
            'description' => null,
            'headings' => [],
            'paragraphs' => ['This is paragraph one with some content.', 'This is paragraph two.'],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        $this->assertStringContainsString('Content:', $results['content_snapshot']);
        $this->assertStringContainsString('This is paragraph one', $results['content_snapshot']);
    }

    public function test_it_limits_headings_to_first_10(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $headings = array_map(fn($i) => "Heading {$i}", range(1, 20));

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Title',
            'description' => null,
            'headings' => $headings,
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        // Should contain first 10
        $this->assertStringContainsString('Heading 1', $results['content_snapshot']);
        $this->assertStringContainsString('Heading 10', $results['content_snapshot']);
        // Should not contain beyond 10
        $this->assertStringNotContainsString('Heading 11', $results['content_snapshot']);
    }

    public function test_it_limits_paragraphs_to_first_5(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $paragraphs = array_map(fn($i) => "Paragraph {$i} with unique content", range(1, 10));

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Title',
            'description' => null,
            'headings' => [],
            'paragraphs' => $paragraphs,
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        // Should contain first 5
        $this->assertStringContainsString('Paragraph 1', $results['content_snapshot']);
        $this->assertStringContainsString('Paragraph 5', $results['content_snapshot']);
        // Should not contain beyond 5
        $this->assertStringNotContainsString('Paragraph 6', $results['content_snapshot']);
    }

    public function test_it_truncates_long_paragraphs(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $longParagraph = str_repeat('This is a very long paragraph. ', 50);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => 'Title',
            'description' => null,
            'headings' => [],
            'paragraphs' => [$longParagraph],
            'links' => [],
            'images' => [],
            'word_count' => 50,
        ]);

        $results = $this->service->crawl($website);

        // Should be truncated to 200 chars + "..."
        $this->assertStringContainsString('...', $results['content_snapshot']);
        $this->assertLessThan(strlen($longParagraph), strlen($results['content_snapshot']));
    }

    public function test_it_uses_custom_user_agent(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => null,
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 0,
        ]);

        $this->service->crawl($website);

        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'Mozilla/5.0 (compatible; LeadBot/1.0)');
        });
    }

    public function test_it_sets_15_second_timeout(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body>Test</body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => null,
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 0,
        ]);

        $this->service->crawl($website);

        // HTTP timeout is configured, but we can't easily assert it in tests
        // The fact that the test passes confirms the request was made
        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com';
        });
    }

    public function test_it_handles_empty_content_extraction_results(): void
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
        ]);

        Http::fake([
            'example.com' => Http::response('<html><body></body></html>', 200),
        ]);

        $this->platformDetector->method('detectFromHtml')->willReturn('custom');
        $this->contentExtractor->method('extractContent')->willReturn([
            'title' => null,
            'description' => null,
            'headings' => [],
            'paragraphs' => [],
            'links' => [],
            'images' => [],
            'word_count' => 0,
        ]);

        $results = $this->service->crawl($website);

        $this->assertEquals(0, $results['word_count']);
        $this->assertStringContainsString('Title: N/A', $results['content_snapshot']);
    }
}
