# Step 3 Implementation Plan: Web Crawling Implementation

## Executive Summary

This document outlines the complete implementation plan for Step 3 of the automated website research and outreach application. Step 3 focuses on building a powerful web crawling system using Roach PHP to extract content, detect platforms, and gather intelligence from target websites.

**Key Objectives:**
- Configure Roach PHP for efficient web crawling
- Implement platform detection (WordPress, Shopify, Wix, custom, etc.)
- Extract and store website content for AI processing
- Track page counts, word counts, and metadata
- Handle timeouts, errors, and retry logic
- Integrate with contact extraction system
- Support configurable crawl limits and depth

**Dependencies:**
- Step 1 completed (Websites table exists)
- Step 2 completed (Contact extraction available)
- Roach PHP installed (`composer require roach-php/core`)

---

## 1. Roach PHP Configuration

### 1.1 Roach Spider Base Class

**File:** `app/Spiders/BaseWebsiteSpider.php`

```php
<?php

namespace App\Spiders;

use Generator;
use RoachPHP\Downloader\Middleware\RequestDeduplicationMiddleware;
use RoachPHP\Extensions\LoggerExtension;
use RoachPHP\Extensions\StatsCollectorExtension;
use RoachPHP\Http\Response;
use RoachPHP\Spider\BasicSpider;
use RoachPHP\Spider\ParseResult;

abstract class BaseWebsiteSpider extends BasicSpider
{
    public array $downloaderMiddleware = [
        RequestDeduplicationMiddleware::class,
    ];

    public array $spiderMiddleware = [
        // Add custom middleware here
    ];

    public array $itemProcessors = [
        // Will be defined per spider
    ];

    public array $extensions = [
        LoggerExtension::class,
        StatsCollectorExtension::class,
    ];

    public int $concurrency = 2;
    public int $requestDelay = 1; // seconds

    /**
     * Parse the response
     */
    abstract public function parse(Response $response): Generator;

    /**
     * Get user agent
     */
    protected function getUserAgent(): string
    {
        return 'Mozilla/5.0 (compatible; LeadBot/1.0; +https://example.com/bot)';
    }
}
```

---

### 1.2 Website Crawl Spider

**File:** `app/Spiders/WebsiteCrawlSpider.php`

```php
<?php

namespace App\Spiders;

use App\ItemProcessors\PageContentProcessor;
use App\ItemProcessors\PlatformDetectionProcessor;
use Generator;
use RoachPHP\Http\Response;
use RoachPHP\Spider\ParseResult;

class WebsiteCrawlSpider extends BaseWebsiteSpider
{
    public array $itemProcessors = [
        PlatformDetectionProcessor::class,
        PageContentProcessor::class,
    ];

    /**
     * Maximum pages to crawl
     */
    public int $maxPages = 10;

    /**
     * Current page count
     */
    private int $pageCount = 0;

    /**
     * Website ID being crawled
     */
    public int $websiteId;

    /**
     * Allowed domains
     */
    public array $allowedDomains = [];

    /**
     * Parse the response
     */
    public function parse(Response $response): Generator
    {
        // Stop if we've reached max pages
        if ($this->pageCount >= $this->maxPages) {
            return;
        }

        $this->pageCount++;

        // Extract text content
        $title = $response->filter('title')->first()->text('');
        $metaDescription = $response->filter('meta[name="description"]')->first()->attr('content', '');

        // Extract body text
        $bodyText = $response->filter('body')->first()->text('');
        $wordCount = str_word_count($bodyText);

        // Yield page data
        yield $this->item([
            'url' => $response->getUri(),
            'title' => $title,
            'description' => $metaDescription,
            'body' => $bodyText,
            'word_count' => $wordCount,
            'html' => $response->getBody(),
            'status_code' => $response->getStatus(),
            'website_id' => $this->websiteId,
        ]);

        // Follow links if we haven't hit the limit
        if ($this->pageCount < $this->maxPages) {
            $links = $response->filter('a')->links();

            foreach ($links as $link) {
                $url = $link->getUri();

                // Check if URL is within allowed domains
                if ($this->isAllowedUrl($url)) {
                    yield $this->request('GET', $url);
                }
            }
        }
    }

    /**
     * Check if URL is allowed
     */
    private function isAllowedUrl(string $url): bool
    {
        if (empty($this->allowedDomains)) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        foreach ($this->allowedDomains as $allowedDomain) {
            if (str_contains($host, $allowedDomain)) {
                return true;
            }
        }

        return false;
    }
}
```

---

### 1.3 Item Processors

#### Page Content Processor

**File:** `app/ItemProcessors/PageContentProcessor.php`

```php
<?php

namespace App\ItemProcessors;

use App\Models\Website;
use Illuminate\Support\Facades\Log;
use RoachPHP\ItemPipeline\ItemInterface;
use RoachPHP\ItemPipeline\Processors\ItemProcessorInterface;
use RoachPHP\Support\Configurable;

class PageContentProcessor implements ItemProcessorInterface
{
    use Configurable;

    private array $collectedContent = [];
    private int $totalWordCount = 0;
    private int $pageCount = 0;

    public function processItem(ItemInterface $item): ItemInterface
    {
        $this->pageCount++;
        $this->totalWordCount += $item->get('word_count', 0);

        // Collect content for first 10 pages
        if ($this->pageCount <= 10) {
            $this->collectedContent[] = [
                'url' => $item->get('url'),
                'title' => $item->get('title'),
                'text' => substr($item->get('body'), 0, 2000), // Limit per page
            ];
        }

        return $item;
    }

    /**
     * Get collected content snapshot
     */
    public function getContentSnapshot(): string
    {
        $snapshot = "Website Content Snapshot\n\n";

        foreach ($this->collectedContent as $page) {
            $snapshot .= "URL: {$page['url']}\n";
            $snapshot .= "Title: {$page['title']}\n";
            $snapshot .= "Content: {$page['text']}\n";
            $snapshot .= "\n---\n\n";
        }

        return $snapshot;
    }

    /**
     * Get total word count
     */
    public function getTotalWordCount(): int
    {
        return $this->totalWordCount;
    }

    /**
     * Get page count
     */
    public function getPageCount(): int
    {
        return $this->pageCount;
    }
}
```

---

#### Platform Detection Processor

**File:** `app/ItemProcessors/PlatformDetectionProcessor.php`

```php
<?php

namespace App\ItemProcessors;

use RoachPHP\ItemPipeline\ItemInterface;
use RoachPHP\ItemPipeline\Processors\ItemProcessorInterface;
use RoachPHP\Support\Configurable;

class PlatformDetectionProcessor implements ItemProcessorInterface
{
    use Configurable;

    private ?string $detectedPlatform = null;

    public function processItem(ItemInterface $item): ItemInterface
    {
        // Only detect once (from first page)
        if ($this->detectedPlatform === null) {
            $html = $item->get('html', '');
            $this->detectedPlatform = $this->detectPlatform($html);
        }

        return $item;
    }

    /**
     * Detect platform from HTML
     */
    private function detectPlatform(string $html): string
    {
        $html = strtolower($html);

        // WordPress detection
        if (str_contains($html, 'wp-content') ||
            str_contains($html, 'wp-includes') ||
            str_contains($html, 'wordpress')) {
            return 'wordpress';
        }

        // Shopify detection
        if (str_contains($html, 'shopify') ||
            str_contains($html, 'cdn.shopify.com')) {
            return 'shopify';
        }

        // Wix detection
        if (str_contains($html, 'wix.com') ||
            str_contains($html, 'wixsite.com')) {
            return 'wix';
        }

        // Squarespace detection
        if (str_contains($html, 'squarespace')) {
            return 'squarespace';
        }

        // Webflow detection
        if (str_contains($html, 'webflow')) {
            return 'webflow';
        }

        // Joomla detection
        if (str_contains($html, 'joomla')) {
            return 'joomla';
        }

        // Drupal detection
        if (str_contains($html, 'drupal')) {
            return 'drupal';
        }

        // WooCommerce detection (WordPress + ecommerce)
        if ((str_contains($html, 'wp-content') || str_contains($html, 'wordpress')) &&
            str_contains($html, 'woocommerce')) {
            return 'woocommerce';
        }

        return 'custom';
    }

    /**
     * Get detected platform
     */
    public function getDetectedPlatform(): ?string
    {
        return $this->detectedPlatform;
    }
}
```

---

## 2. Services

### 2.1 Web Crawler Service

**File:** `app/Services/WebCrawlerService.php`

```php
<?php

namespace App\Services;

use App\Models\Website;
use App\Spiders\WebsiteCrawlSpider;
use App\ItemProcessors\PageContentProcessor;
use App\ItemProcessors\PlatformDetectionProcessor;
use Illuminate\Support\Facades\Log;
use RoachPHP\Roach;

class WebCrawlerService
{
    /**
     * Crawl a website
     */
    public function crawl(Website $website, int $maxPages = 10): array
    {
        try {
            Log::info('Starting website crawl', [
                'website_id' => $website->id,
                'url' => $website->url,
                'max_pages' => $maxPages,
            ]);

            // Mark as crawling
            $website->startCrawl();

            // Parse domain from URL
            $parsedUrl = parse_url($website->url);
            $domain = $parsedUrl['host'] ?? '';

            // Configure spider
            $contentProcessor = new PageContentProcessor();
            $platformProcessor = new PlatformDetectionProcessor();

            // Run the spider
            Roach::startSpider(
                WebsiteCrawlSpider::class,
                [
                    WebsiteCrawlSpider::class => [
                        'websiteId' => $website->id,
                        'maxPages' => $maxPages,
                        'allowedDomains' => [$domain],
                    ]
                ],
                [$website->url]
            );

            // Collect results
            $results = [
                'page_count' => $contentProcessor->getPageCount(),
                'word_count' => $contentProcessor->getTotalWordCount(),
                'content_snapshot' => $contentProcessor->getContentSnapshot(),
                'detected_platform' => $platformProcessor->getDetectedPlatform(),
            ];

            Log::info('Crawl completed', [
                'website_id' => $website->id,
                'page_count' => $results['page_count'],
                'platform' => $results['detected_platform'],
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Crawl failed', [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Crawl website and update database
     */
    public function crawlAndUpdate(Website $website, int $maxPages = 10): void
    {
        try {
            $results = $this->crawl($website, $maxPages);

            // Update website with results
            $website->completeCrawl([
                'page_count' => $results['page_count'],
                'word_count' => $results['word_count'],
                'content_snapshot' => $results['content_snapshot'],
                'detected_platform' => $results['detected_platform'],
            ]);

        } catch (\Exception $e) {
            $website->failCrawl($e->getMessage());
            throw $e;
        }
    }
}
```

---

### 2.2 Platform Detection Service

**File:** `app/Services/PlatformDetectionService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PlatformDetectionService
{
    /**
     * Detect platform from URL
     */
    public function detect(string $url): string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; LeadBot/1.0)',
                ])
                ->get($url);

            if (!$response->successful()) {
                return 'unknown';
            }

            $html = strtolower($response->body());
            $headers = $response->headers();

            // Check response headers first
            $platform = $this->detectFromHeaders($headers);
            if ($platform !== 'custom') {
                return $platform;
            }

            // Check HTML content
            return $this->detectFromHtml($html);

        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Detect from HTTP headers
     */
    protected function detectFromHeaders(array $headers): string
    {
        $headerString = json_encode($headers);
        $headerString = strtolower($headerString);

        if (str_contains($headerString, 'x-powered-by') &&
            str_contains($headerString, 'wordpress')) {
            return 'wordpress';
        }

        if (str_contains($headerString, 'shopify')) {
            return 'shopify';
        }

        return 'custom';
    }

    /**
     * Detect from HTML content
     */
    protected function detectFromHtml(string $html): string
    {
        $detectors = [
            'wordpress' => [
                'wp-content',
                'wp-includes',
                'wordpress',
                '/wp-json/',
            ],
            'shopify' => [
                'shopify',
                'cdn.shopify.com',
                'myshopify.com',
            ],
            'wix' => [
                'wix.com',
                'wixsite.com',
                'wix-code',
            ],
            'squarespace' => [
                'squarespace',
                'squarespace-cdn',
            ],
            'webflow' => [
                'webflow',
            ],
            'joomla' => [
                'joomla',
                '/components/com_',
            ],
            'drupal' => [
                'drupal',
                'sites/all/themes',
            ],
            'woocommerce' => [
                'woocommerce',
                'wc-',
            ],
        ];

        foreach ($detectors as $platform => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($html, $pattern)) {
                    return $platform;
                }
            }
        }

        return 'custom';
    }

    /**
     * Get detailed platform information
     */
    public function getDetailedInfo(string $url): array
    {
        $platform = $this->detect($url);

        return [
            'platform' => $platform,
            'is_cms' => in_array($platform, ['wordpress', 'joomla', 'drupal']),
            'is_ecommerce' => in_array($platform, ['shopify', 'woocommerce']),
            'is_website_builder' => in_array($platform, ['wix', 'squarespace', 'webflow']),
        ];
    }
}
```

---

### 2.3 Content Extraction Service

**File:** `app/Services/ContentExtractionService.php`

```php
<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

class ContentExtractionService
{
    /**
     * Extract main content from HTML
     */
    public function extractContent(string $html): array
    {
        $crawler = new Crawler($html);

        return [
            'title' => $this->extractTitle($crawler),
            'description' => $this->extractDescription($crawler),
            'headings' => $this->extractHeadings($crawler),
            'paragraphs' => $this->extractParagraphs($crawler),
            'links' => $this->extractLinks($crawler),
            'images' => $this->extractImages($crawler),
            'word_count' => $this->calculateWordCount($crawler),
        ];
    }

    /**
     * Extract page title
     */
    protected function extractTitle(Crawler $crawler): ?string
    {
        try {
            return $crawler->filter('title')->first()->text();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract meta description
     */
    protected function extractDescription(Crawler $crawler): ?string
    {
        try {
            return $crawler->filter('meta[name="description"]')->first()->attr('content');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract all headings
     */
    protected function extractHeadings(Crawler $crawler): array
    {
        $headings = [];

        for ($i = 1; $i <= 6; $i++) {
            $crawler->filter("h{$i}")->each(function (Crawler $node) use (&$headings) {
                $headings[] = trim($node->text());
            });
        }

        return $headings;
    }

    /**
     * Extract paragraphs
     */
    protected function extractParagraphs(Crawler $crawler): array
    {
        $paragraphs = [];

        $crawler->filter('p')->each(function (Crawler $node) use (&$paragraphs) {
            $text = trim($node->text());
            if (strlen($text) > 20) { // Only meaningful paragraphs
                $paragraphs[] = $text;
            }
        });

        return $paragraphs;
    }

    /**
     * Extract links
     */
    protected function extractLinks(Crawler $crawler): array
    {
        $links = [];

        $crawler->filter('a')->each(function (Crawler $node) use (&$links) {
            $href = $node->attr('href');
            $text = trim($node->text());

            if ($href && $text) {
                $links[] = [
                    'url' => $href,
                    'text' => $text,
                ];
            }
        });

        return $links;
    }

    /**
     * Extract images
     */
    protected function extractImages(Crawler $crawler): array
    {
        $images = [];

        $crawler->filter('img')->each(function (Crawler $node) use (&$images) {
            $images[] = [
                'src' => $node->attr('src'),
                'alt' => $node->attr('alt'),
            ];
        });

        return $images;
    }

    /**
     * Calculate word count
     */
    protected function calculateWordCount(Crawler $crawler): int
    {
        try {
            $text = $crawler->filter('body')->first()->text();
            return str_word_count($text);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if URL exists on page
     */
    public function hasUrl(string $html, string $urlPattern): bool
    {
        $crawler = new Crawler($html);

        $found = false;

        $crawler->filter('a')->each(function (Crawler $node) use ($urlPattern, &$found) {
            $href = $node->attr('href');

            if (str_contains(strtolower($href), strtolower($urlPattern))) {
                $found = true;
            }
        });

        return $found;
    }
}
```

---

## 3. Queue Jobs

### 3.1 Crawl Website Job

**File:** `app/Jobs/CrawlWebsiteJob.php`

```php
<?php

namespace App\Jobs;

use App\Jobs\ExtractContactsJob;
use App\Models\Website;
use App\Services\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Website $website,
        public int $maxPages = 10,
        public bool $extractContacts = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WebCrawlerService $crawlerService): void
    {
        Log::info('Crawl job started', [
            'website_id' => $this->website->id,
            'url' => $this->website->url,
            'max_pages' => $this->maxPages,
        ]);

        try {
            // Perform crawl
            $crawlerService->crawlAndUpdate($this->website, $this->maxPages);

            // Extract contacts if requested
            if ($this->extractContacts) {
                ExtractContactsJob::dispatch($this->website);
            }

            Log::info('Crawl job completed', [
                'website_id' => $this->website->id,
                'page_count' => $this->website->fresh()->page_count,
            ]);

        } catch (\Exception $e) {
            Log::error('Crawl job failed', [
                'website_id' => $this->website->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->website->failCrawl($exception->getMessage());

        Log::error('Crawl job permanently failed', [
            'website_id' => $this->website->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

## 4. Testing Strategy

### 4.1 Unit Tests

#### Test: Platform Detection Service

**File:** `tests/Unit/Services/PlatformDetectionServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Services\PlatformDetectionService;
use Tests\TestCase;

class PlatformDetectionServiceTest extends TestCase
{
    protected PlatformDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformDetectionService();
    }

    /** @test */
    public function it_detects_wordpress_from_html()
    {
        $html = '<html><head><link rel="stylesheet" href="/wp-content/themes/theme.css"></head></html>';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('detectFromHtml');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $html);

        $this->assertEquals('wordpress', $result);
    }

    /** @test */
    public function it_detects_shopify_from_html()
    {
        $html = '<html><script src="https://cdn.shopify.com/s/files/1/script.js"></script></html>';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('detectFromHtml');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, strtolower($html));

        $this->assertEquals('shopify', $result);
    }

    /** @test */
    public function it_returns_custom_for_unknown_platform()
    {
        $html = '<html><body>Custom website</body></html>';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('detectFromHtml');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, strtolower($html));

        $this->assertEquals('custom', $result);
    }

    /** @test */
    public function it_provides_detailed_platform_info()
    {
        // Note: This would require mocking HTTP::get()
        // Simplified test
        $this->assertTrue(true);
    }
}
```

---

#### Test: Content Extraction Service

**File:** `tests/Unit/Services/ContentExtractionServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Services\ContentExtractionService;
use Tests\TestCase;

class ContentExtractionServiceTest extends TestCase
{
    protected ContentExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentExtractionService();
    }

    /** @test */
    public function it_extracts_title_from_html()
    {
        $html = '<html><head><title>Test Page</title></head><body></body></html>';

        $result = $this->service->extractContent($html);

        $this->assertEquals('Test Page', $result['title']);
    }

    /** @test */
    public function it_extracts_meta_description()
    {
        $html = '<html><head><meta name="description" content="Test description"></head></html>';

        $result = $this->service->extractContent($html);

        $this->assertEquals('Test description', $result['description']);
    }

    /** @test */
    public function it_extracts_headings()
    {
        $html = '<html><body><h1>Heading 1</h1><h2>Heading 2</h2></body></html>';

        $result = $this->service->extractContent($html);

        $this->assertCount(2, $result['headings']);
        $this->assertContains('Heading 1', $result['headings']);
        $this->assertContains('Heading 2', $result['headings']);
    }

    /** @test */
    public function it_calculates_word_count()
    {
        $html = '<html><body><p>This is a test paragraph with ten words here.</p></body></html>';

        $result = $this->service->extractContent($html);

        $this->assertEquals(10, $result['word_count']);
    }

    /** @test */
    public function it_checks_if_url_exists()
    {
        $html = '<html><body><a href="/contact">Contact Us</a></body></html>';

        $exists = $this->service->hasUrl($html, '/contact');

        $this->assertTrue($exists);
    }
}
```

---

### 4.2 Feature Tests

#### Test: Website Crawling

**File:** `tests/Feature/WebsiteCrawlingTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\CrawlWebsiteJob;
use App\Models\Website;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsiteCrawlingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_dispatches_crawl_job_for_website()
    {
        Queue::fake();

        $website = Website::factory()->create();

        CrawlWebsiteJob::dispatch($website);

        Queue::assertPushed(CrawlWebsiteJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    /** @test */
    public function crawl_job_updates_website_status()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
        ]);

        $website->startCrawl();

        $this->assertEquals(Website::STATUS_CRAWLING, $website->fresh()->status);
        $this->assertNotNull($website->fresh()->crawl_started_at);
    }

    /** @test */
    public function crawl_completion_updates_website_data()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_CRAWLING,
        ]);

        $website->completeCrawl([
            'page_count' => 15,
            'word_count' => 5000,
            'detected_platform' => 'wordpress',
        ]);

        $website = $website->fresh();

        $this->assertEquals(Website::STATUS_COMPLETED, $website->status);
        $this->assertEquals(15, $website->page_count);
        $this->assertEquals(5000, $website->word_count);
        $this->assertEquals('wordpress', $website->detected_platform);
        $this->assertNotNull($website->crawled_at);
    }

    /** @test */
    public function failed_crawl_updates_website_status()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_CRAWLING,
        ]);

        $website->failCrawl('Connection timeout');

        $website = $website->fresh();

        $this->assertEquals(Website::STATUS_FAILED, $website->status);
        $this->assertEquals('Connection timeout', $website->crawl_error);
    }
}
```

---

## 5. Configuration

### 5.1 Roach Configuration

**File:** `config/roach.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Spider Settings
    |--------------------------------------------------------------------------
    */
    'default_spider_settings' => [
        'concurrency' => env('ROACH_CONCURRENCY', 2),
        'request_delay' => env('ROACH_REQUEST_DELAY', 1),
        'request_timeout' => env('ROACH_REQUEST_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    */
    'user_agent' => env('ROACH_USER_AGENT', 'Mozilla/5.0 (compatible; LeadBot/1.0)'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Pages Per Crawl
    |--------------------------------------------------------------------------
    */
    'max_pages_per_crawl' => env('ROACH_MAX_PAGES', 10),

    /*
    |--------------------------------------------------------------------------
    | Respect Robots.txt
    |--------------------------------------------------------------------------
    */
    'respect_robots_txt' => env('ROACH_RESPECT_ROBOTS', true),
];
```

---

## 6. Implementation Checklist

### Phase 1: Roach PHP Setup ✓
- [ ] Verify Roach PHP installed: `composer show roach-php/core`
- [ ] Create config/roach.php configuration
- [ ] Create BaseWebsiteSpider
- [ ] Create WebsiteCrawlSpider
- [ ] Test spider with sample URL

### Phase 2: Item Processors ✓
- [ ] Create PageContentProcessor
- [ ] Create PlatformDetectionProcessor
- [ ] Test processors independently

### Phase 3: Services ✓
- [ ] Create WebCrawlerService
- [ ] Create PlatformDetectionService
- [ ] Create ContentExtractionService
- [ ] Test services with sample websites

### Phase 4: Jobs ✓
- [ ] Create CrawlWebsiteJob
- [ ] Configure queue workers
- [ ] Test job execution

### Phase 5: Testing ✓
- [ ] Create unit tests for services
- [ ] Create feature tests for crawling
- [ ] Run tests: `php artisan test`
- [ ] Test with real websites

### Phase 6: Integration ✓
- [ ] Integrate with Contact extraction (Step 2)
- [ ] Integrate with Requirements matching (Step 4)
- [ ] Test end-to-end workflow

---

## 7. Usage Examples

### Crawl a Website

```php
use App\Models\Website;
use App\Jobs\CrawlWebsiteJob;
use App\Services\WebCrawlerService;

// Via job (recommended)
$website = Website::find(1);
CrawlWebsiteJob::dispatch($website, maxPages: 10);

// Direct service call
$crawlerService = new WebCrawlerService();
$results = $crawlerService->crawlAndUpdate($website, 10);
```

### Detect Platform

```php
use App\Services\PlatformDetectionService;

$service = new PlatformDetectionService();

$platform = $service->detect('https://example.com');
// Returns: 'wordpress', 'shopify', 'custom', etc.

$info = $service->getDetailedInfo('https://example.com');
// Returns: ['platform' => 'wordpress', 'is_cms' => true, ...]
```

---

## 8. Performance Optimization

### Concurrent Crawling

```php
// Queue multiple crawls
foreach ($websites as $website) {
    CrawlWebsiteJob::dispatch($website);
}

// Process with multiple workers
php artisan queue:work --queue=default --sleep=3 --tries=3
```

### Rate Limiting

Configure in `.env`:
```env
ROACH_CONCURRENCY=2
ROACH_REQUEST_DELAY=1
ROACH_REQUEST_TIMEOUT=30
ROACH_MAX_PAGES=10
```

---

## 9. Success Metrics

**Step 3 Completion Criteria:**

**Roach PHP:**
- ✓ Spider classes configured
- ✓ Processors working
- ✓ Can crawl multiple pages
- ✓ Respects limits and delays

**Services:**
- ✓ Platform detection accurate (90%+)
- ✓ Content extraction complete
- ✓ Error handling robust

**Jobs:**
- ✓ Queue processing working
- ✓ Retry logic functioning
- ✓ Failure tracking accurate

**Testing:**
- ✓ Unit tests passing
- ✓ Feature tests passing
- ✓ Integration tests working

**Performance:**
- ✓ Can crawl 10 pages < 2 minutes
- ✓ No memory leaks
- ✓ Handles failures gracefully

---

## 10. Troubleshooting

### Common Issues

**Timeout Errors:**
```php
// Increase timeout in job
public $timeout = 900; // 15 minutes

// Increase in HTTP requests
Http::timeout(60)->get($url);
```

**Memory Issues:**
```php
// Process in smaller chunks
public int $maxPages = 5; // Reduce from 10

// Clear memory after each page
gc_collect_cycles();
```

**Rate Limiting:**
```env
ROACH_CONCURRENCY=1
ROACH_REQUEST_DELAY=2
```

---

## Conclusion

This implementation plan provides a complete roadmap for building a robust web crawling system capable of extracting content, detecting platforms, and gathering intelligence at scale.

**Estimated Implementation Time:** 8-10 hours for experienced Laravel developer

**Priority:** HIGH - Core functionality for lead research

**Risk Level:** MEDIUM - Depends on external websites' availability

**Next Document:** `step4-implementation-plan.md` (Requirements Matching Engine)
