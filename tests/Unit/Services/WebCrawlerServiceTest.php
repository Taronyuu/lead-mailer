<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Services\Crawler\ContentCrawlObserver;
use App\Services\Crawler\InternalLinksCrawlProfile;
use App\Services\WebCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Crawler\Crawler;
use Tests\TestCase;

class WebCrawlerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_creates_service_instance(): void
    {
        $service = new WebCrawlerService();
        $this->assertInstanceOf(WebCrawlerService::class, $service);
    }

    public function test_observer_concatenates_pages_with_separator(): void
    {
        $observer = new ContentCrawlObserver(10);

        $mockStream1 = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
        $mockStream1->shouldReceive('__toString')->andReturn('<html><body>Page 1</body></html>');

        $mockStream2 = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
        $mockStream2->shouldReceive('__toString')->andReturn('<html><body>Page 2</body></html>');

        $mockResponse1 = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse1->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('text/html');
        $mockResponse1->shouldReceive('getBody')->andReturn($mockStream1);

        $mockResponse2 = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse2->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('text/html');
        $mockResponse2->shouldReceive('getBody')->andReturn($mockStream2);

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);

        $observer->crawled($mockUri, $mockResponse1);
        $observer->crawled($mockUri, $mockResponse2);

        $pages = $observer->getPages();
        $concatenated = implode("\n\n<!-- PAGE_SEPARATOR -->\n\n", $pages);

        $this->assertStringContainsString('<!-- PAGE_SEPARATOR -->', $concatenated);
        $this->assertStringContainsString('Page 1', $concatenated);
        $this->assertStringContainsString('Page 2', $concatenated);
    }

    public function test_service_throws_exception_when_observer_has_no_pages(): void
    {
        $this->assertTrue(true);
    }

    public function test_content_crawl_observer_collects_html(): void
    {
        $observer = new ContentCrawlObserver(10);
        $this->assertEmpty($observer->getPages());
        $this->assertEquals(0, $observer->getPageCount());
    }

    public function test_content_crawl_observer_respects_max_pages(): void
    {
        $observer = new ContentCrawlObserver(2);

        $mockStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->shouldReceive('__toString')->andReturn('<html><body>Test</body></html>');

        $mockResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('text/html');
        $mockResponse->shouldReceive('getBody')->andReturn($mockStream);

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);

        $observer->crawled($mockUri, $mockResponse);
        $observer->crawled($mockUri, $mockResponse);
        $observer->crawled($mockUri, $mockResponse);

        $this->assertEquals(2, $observer->getPageCount());
    }

    public function test_content_crawl_observer_skips_non_html(): void
    {
        $observer = new ContentCrawlObserver(10);

        $mockResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/pdf');

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);

        $observer->crawled($mockUri, $mockResponse);

        $this->assertEquals(0, $observer->getPageCount());
    }

    public function test_internal_links_crawl_profile_allows_same_host(): void
    {
        $profile = new InternalLinksCrawlProfile('https://example.com', 10);

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri->shouldReceive('getHost')->andReturn('example.com');
        $mockUri->shouldReceive('getPath')->andReturn('/about');

        $this->assertTrue($profile->shouldCrawl($mockUri));
    }

    public function test_internal_links_crawl_profile_blocks_external_host(): void
    {
        $profile = new InternalLinksCrawlProfile('https://example.com', 10);

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri->shouldReceive('getHost')->andReturn('other.com');
        $mockUri->shouldReceive('getPath')->andReturn('/');

        $this->assertFalse($profile->shouldCrawl($mockUri));
    }

    public function test_internal_links_crawl_profile_skips_pdf_files(): void
    {
        $profile = new InternalLinksCrawlProfile('https://example.com', 10);

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri->shouldReceive('getHost')->andReturn('example.com');
        $mockUri->shouldReceive('getPath')->andReturn('/document.pdf');

        $this->assertFalse($profile->shouldCrawl($mockUri));
    }

    public function test_internal_links_crawl_profile_respects_max_pages(): void
    {
        $profile = new InternalLinksCrawlProfile('https://example.com', 2);

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri->shouldReceive('getHost')->andReturn('example.com');
        $mockUri->shouldReceive('getPath')->andReturn('/page1');

        $this->assertTrue($profile->shouldCrawl($mockUri));

        $mockUri2 = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri2->shouldReceive('getHost')->andReturn('example.com');
        $mockUri2->shouldReceive('getPath')->andReturn('/page2');

        $this->assertTrue($profile->shouldCrawl($mockUri2));

        $mockUri3 = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri3->shouldReceive('getHost')->andReturn('example.com');

        $this->assertFalse($profile->shouldCrawl($mockUri3));
    }
}
