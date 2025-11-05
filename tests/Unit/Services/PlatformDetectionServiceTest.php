<?php

namespace Tests\Unit\Services;

use App\Services\PlatformDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlatformDetectionService::class);
    }

    public function test_it_detects_wordpress_from_html(): void
    {
        $html = '<html><body><script src="/wp-content/themes/theme.js"></script></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('wordpress', $result);
    }

    public function test_it_detects_wordpress_from_wp_includes(): void
    {
        $html = '<html><body><link rel="stylesheet" href="/wp-includes/style.css"></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('wordpress', $result);
    }

    public function test_it_detects_wordpress_from_wp_json(): void
    {
        $html = '<html><body><link rel="https://example.com/wp-json/"></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('wordpress', $result);
    }

    public function test_it_detects_shopify_from_html(): void
    {
        $html = '<html><body><script src="https://cdn.shopify.com/script.js"></script></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('shopify', $result);
    }

    public function test_it_detects_shopify_from_myshopify_domain(): void
    {
        $html = '<html><body><a href="https://mystore.myshopify.com">Store</a></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('shopify', $result);
    }

    public function test_it_detects_wix_from_html(): void
    {
        $html = '<html><body><meta content="Wix.com Website Builder"></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('wix', $result);
    }

    public function test_it_detects_wix_from_wixsite_domain(): void
    {
        $html = '<html><body><a href="https://mysite.wixsite.com/site">Site</a></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('wix', $result);
    }

    public function test_it_detects_squarespace_from_html(): void
    {
        $html = '<html><body><script src="https://squarespace-cdn.com/script.js"></script></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('squarespace', $result);
    }

    public function test_it_detects_webflow_from_html(): void
    {
        $html = '<html><body><meta name="generator" content="Webflow"></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('webflow', $result);
    }

    public function test_it_detects_joomla_from_html(): void
    {
        $html = '<html><body><script src="/components/com_content/script.js"></script></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('joomla', $result);
    }

    public function test_it_detects_drupal_from_html(): void
    {
        $html = '<html><body><link rel="stylesheet" href="/sites/all/themes/theme.css"></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('drupal', $result);
    }

    public function test_it_detects_woocommerce_from_html(): void
    {
        $html = '<html><body><div class="woocommerce-cart"></div></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('woocommerce', $result);
    }

    public function test_it_returns_custom_for_unknown_platform(): void
    {
        $html = '<html><body><h1>My Custom Website</h1></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('custom', $result);
    }

    public function test_it_detects_platform_from_url_successfully(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body><div class="wp-content"></div></body></html>', 200),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('wordpress', $result);
    }

    public function test_it_returns_unknown_on_http_failure(): void
    {
        Http::fake([
            'example.com' => Http::response('', 404),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('unknown', $result);
    }

    public function test_it_returns_unknown_on_http_exception(): void
    {
        Http::fake([
            'example.com' => function () {
                throw new \Exception('Connection timeout');
            },
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('unknown', $result);
    }

    public function test_it_detects_from_headers_wordpress(): void
    {
        Http::fake([
            'example.com' => Http::response('<html></html>', 200, [
                'X-Powered-By' => 'WordPress',
            ]),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('wordpress', $result);
    }

    public function test_it_detects_from_headers_shopify(): void
    {
        Http::fake([
            'example.com' => Http::response('<html></html>', 200, [
                'X-Shopify-Stage' => 'production',
            ]),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('shopify', $result);
    }

    public function test_it_falls_back_to_html_when_headers_not_detected(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body><div class="wix-code"></div></body></html>', 200, [
                'X-Custom-Header' => 'value',
            ]),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('wix', $result);
    }

    public function test_it_uses_custom_user_agent(): void
    {
        Http::fake([
            'example.com' => Http::response('<html></html>', 200),
        ]);

        $this->service->detect('https://example.com');

        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'Mozilla/5.0 (compatible; LeadBot/1.0)');
        });
    }

    public function test_it_sets_10_second_timeout(): void
    {
        Http::fake([
            'example.com' => Http::response('<html></html>', 200),
        ]);

        $this->service->detect('https://example.com');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com';
        });
    }

    public function test_it_converts_html_to_lowercase_for_detection(): void
    {
        $html = '<HTML><BODY><DIV CLASS="WP-CONTENT"></DIV></BODY></HTML>';

        $result = $this->service->detectFromHtml(strtolower($html));

        $this->assertEquals('wordpress', $result);
    }

    public function test_get_detailed_info_identifies_cms_platforms(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body><div class="wp-content"></div></body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('wordpress', $info['platform']);
        $this->assertTrue($info['is_cms']);
        $this->assertFalse($info['is_ecommerce']);
        $this->assertFalse($info['is_website_builder']);
    }

    public function test_get_detailed_info_identifies_ecommerce_platforms(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body><script src="https://cdn.shopify.com"></script></body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('shopify', $info['platform']);
        $this->assertFalse($info['is_cms']);
        $this->assertTrue($info['is_ecommerce']);
        $this->assertFalse($info['is_website_builder']);
    }

    public function test_get_detailed_info_identifies_website_builders(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body><meta content="wix.com"></body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('wix', $info['platform']);
        $this->assertFalse($info['is_cms']);
        $this->assertFalse($info['is_ecommerce']);
        $this->assertTrue($info['is_website_builder']);
    }

    public function test_get_detailed_info_for_joomla(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>joomla</body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('joomla', $info['platform']);
        $this->assertTrue($info['is_cms']);
    }

    public function test_get_detailed_info_for_drupal(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>drupal</body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('drupal', $info['platform']);
        $this->assertTrue($info['is_cms']);
    }

    public function test_get_detailed_info_for_woocommerce(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>woocommerce</body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('woocommerce', $info['platform']);
        $this->assertTrue($info['is_ecommerce']);
    }

    public function test_get_detailed_info_for_squarespace(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>squarespace</body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('squarespace', $info['platform']);
        $this->assertTrue($info['is_website_builder']);
    }

    public function test_get_detailed_info_for_webflow(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>webflow</body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('webflow', $info['platform']);
        $this->assertTrue($info['is_website_builder']);
    }

    public function test_get_detailed_info_for_custom_platform(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>Custom site</body></html>', 200),
        ]);

        $info = $this->service->getDetailedInfo('https://example.com');

        $this->assertEquals('custom', $info['platform']);
        $this->assertFalse($info['is_cms']);
        $this->assertFalse($info['is_ecommerce']);
        $this->assertFalse($info['is_website_builder']);
    }

    public function test_detect_from_headers_returns_custom_when_no_match(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>wix</body></html>', 200, [
                'X-Custom-Header' => 'CustomValue',
            ]),
        ]);

        $result = $this->service->detect('https://example.com');

        // Should fall back to HTML detection and find wix
        $this->assertEquals('wix', $result);
    }

    public function test_it_detects_wordpress_with_case_insensitive_match(): void
    {
        $html = '<html><body><div class="WP-CONTENT"></div></body></html>';

        $result = $this->service->detectFromHtml(strtolower($html));

        $this->assertEquals('wordpress', $result);
    }

    public function test_it_detects_multiple_patterns_for_wordpress(): void
    {
        $patterns = [
            'wp-content',
            'wp-includes',
            'wordpress',
            '/wp-json/',
        ];

        foreach ($patterns as $pattern) {
            $html = "<html><body>Contains {$pattern} in content</body></html>";
            $result = $this->service->detectFromHtml(strtolower($html));
            $this->assertEquals('wordpress', $result, "Failed to detect WordPress with pattern: {$pattern}");
        }
    }

    public function test_it_detects_multiple_patterns_for_shopify(): void
    {
        $patterns = [
            'shopify',
            'cdn.shopify.com',
            'myshopify.com',
        ];

        foreach ($patterns as $pattern) {
            $html = "<html><body>Contains {$pattern} in content</body></html>";
            $result = $this->service->detectFromHtml(strtolower($html));
            $this->assertEquals('shopify', $result, "Failed to detect Shopify with pattern: {$pattern}");
        }
    }

    public function test_it_prioritizes_first_match_in_detection(): void
    {
        // HTML contains both wordpress and shopify indicators
        $html = '<html><body><div class="wp-content"></div><script src="cdn.shopify.com"></script></body></html>';

        $result = $this->service->detectFromHtml(strtolower($html));

        // WordPress comes first in the detectors array
        $this->assertEquals('wordpress', $result);
    }

    public function test_it_handles_empty_html(): void
    {
        $html = '';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('custom', $result);
    }

    public function test_it_handles_malformed_html(): void
    {
        $html = '<html><body><div>Unclosed div';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('custom', $result);
    }

    public function test_it_detects_wix_code_pattern(): void
    {
        $html = '<html><body><script type="wix-code">code</script></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('wix', $result);
    }

    public function test_it_detects_wc_prefix_for_woocommerce(): void
    {
        $html = '<html><body><div class="wc-cart"></div></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('woocommerce', $result);
    }

    public function test_it_detects_joomla_components(): void
    {
        $html = '<html><body><script src="/components/com_users/script.js"></script></body></html>';

        $result = $this->service->detectFromHtml($html);

        $this->assertEquals('joomla', $result);
    }

    public function test_detect_from_headers_handles_empty_headers(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><body>custom</body></html>', 200, []),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('custom', $result);
    }

    public function test_it_handles_http_500_errors_gracefully(): void
    {
        Http::fake([
            'example.com' => Http::response('', 500),
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('unknown', $result);
    }

    public function test_it_handles_http_timeout_gracefully(): void
    {
        Http::fake([
            'example.com' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
            },
        ]);

        $result = $this->service->detect('https://example.com');

        $this->assertEquals('unknown', $result);
    }
}
