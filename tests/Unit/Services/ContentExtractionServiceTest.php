<?php

namespace Tests\Unit\Services;

use App\Services\ContentExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ContentExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContentExtractionService::class);
    }

    public function test_it_extracts_content_from_html(): void
    {
        $html = '<html>
            <head><title>Test Page</title></head>
            <body>
                <h1>Main Heading</h1>
                <p>This is a paragraph with more than twenty characters.</p>
            </body>
        </html>';

        $content = $this->service->extractContent($html);

        $this->assertIsArray($content);
        $this->assertArrayHasKey('title', $content);
        $this->assertArrayHasKey('description', $content);
        $this->assertArrayHasKey('headings', $content);
        $this->assertArrayHasKey('paragraphs', $content);
        $this->assertArrayHasKey('links', $content);
        $this->assertArrayHasKey('images', $content);
        $this->assertArrayHasKey('word_count', $content);
    }

    public function test_it_extracts_title_correctly(): void
    {
        $html = '<html><head><title>My Website Title</title></head><body></body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals('My Website Title', $content['title']);
    }

    public function test_it_returns_null_when_no_title(): void
    {
        $html = '<html><head></head><body></body></html>';

        $content = $this->service->extractContent($html);

        $this->assertNull($content['title']);
    }

    public function test_it_extracts_meta_description(): void
    {
        $html = '<html>
            <head>
                <meta name="description" content="This is the page description">
            </head>
            <body></body>
        </html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals('This is the page description', $content['description']);
    }

    public function test_it_returns_null_when_no_meta_description(): void
    {
        $html = '<html><head></head><body></body></html>';

        $content = $this->service->extractContent($html);

        $this->assertNull($content['description']);
    }

    public function test_it_extracts_all_heading_levels(): void
    {
        $html = '<html><body>
            <h1>Heading 1</h1>
            <h2>Heading 2</h2>
            <h3>Heading 3</h3>
            <h4>Heading 4</h4>
            <h5>Heading 5</h5>
            <h6>Heading 6</h6>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(6, $content['headings']);
        $this->assertContains('Heading 1', $content['headings']);
        $this->assertContains('Heading 2', $content['headings']);
        $this->assertContains('Heading 3', $content['headings']);
        $this->assertContains('Heading 4', $content['headings']);
        $this->assertContains('Heading 5', $content['headings']);
        $this->assertContains('Heading 6', $content['headings']);
    }

    public function test_it_trims_whitespace_from_headings(): void
    {
        $html = '<html><body>
            <h1>  Heading with spaces  </h1>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals('Heading with spaces', $content['headings'][0]);
    }

    public function test_it_returns_empty_array_when_no_headings(): void
    {
        $html = '<html><body><p>No headings here</p></body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEmpty($content['headings']);
    }

    public function test_it_extracts_paragraphs(): void
    {
        $html = '<html><body>
            <p>This is the first paragraph with enough text.</p>
            <p>This is the second paragraph with enough text.</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(2, $content['paragraphs']);
        $this->assertContains('This is the first paragraph with enough text.', $content['paragraphs']);
        $this->assertContains('This is the second paragraph with enough text.', $content['paragraphs']);
    }

    public function test_it_filters_out_short_paragraphs(): void
    {
        $html = '<html><body>
            <p>Short</p>
            <p>This is a long paragraph with more than twenty characters.</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['paragraphs']);
        $this->assertEquals('This is a long paragraph with more than twenty characters.', $content['paragraphs'][0]);
    }

    public function test_it_trims_paragraph_whitespace(): void
    {
        $html = '<html><body>
            <p>  Paragraph with whitespace around it that is long enough.  </p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals('Paragraph with whitespace around it that is long enough.', $content['paragraphs'][0]);
    }

    public function test_it_extracts_links_with_href_and_text(): void
    {
        $html = '<html><body>
            <a href="/about">About Us</a>
            <a href="/contact">Contact</a>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(2, $content['links']);
        $this->assertEquals('/about', $content['links'][0]['url']);
        $this->assertEquals('About Us', $content['links'][0]['text']);
        $this->assertEquals('/contact', $content['links'][1]['url']);
        $this->assertEquals('Contact', $content['links'][1]['text']);
    }

    public function test_it_skips_links_without_href(): void
    {
        $html = '<html><body>
            <a>No href</a>
            <a href="/valid">Valid Link</a>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['links']);
        $this->assertEquals('/valid', $content['links'][0]['url']);
    }

    public function test_it_skips_links_without_text(): void
    {
        $html = '<html><body>
            <a href="/empty"></a>
            <a href="/valid">Valid</a>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['links']);
        $this->assertEquals('/valid', $content['links'][0]['url']);
    }

    public function test_it_trims_link_text(): void
    {
        $html = '<html><body>
            <a href="/link">  Link Text  </a>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals('Link Text', $content['links'][0]['text']);
    }

    public function test_it_extracts_images_with_src_and_alt(): void
    {
        $html = '<html><body>
            <img src="/image1.jpg" alt="Image 1">
            <img src="/image2.jpg" alt="Image 2">
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(2, $content['images']);
        $this->assertEquals('/image1.jpg', $content['images'][0]['src']);
        $this->assertEquals('Image 1', $content['images'][0]['alt']);
        $this->assertEquals('/image2.jpg', $content['images'][1]['src']);
        $this->assertEquals('Image 2', $content['images'][1]['alt']);
    }

    public function test_it_extracts_images_without_alt(): void
    {
        $html = '<html><body>
            <img src="/image.jpg">
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['images']);
        $this->assertEquals('/image.jpg', $content['images'][0]['src']);
        $this->assertNull($content['images'][0]['alt']);
    }

    public function test_it_calculates_word_count_from_body(): void
    {
        $html = '<html><body>
            <p>This is a test paragraph with exactly ten words here.</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals(10, $content['word_count']);
    }

    public function test_it_returns_zero_word_count_when_no_body(): void
    {
        $html = '<html></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals(0, $content['word_count']);
    }

    public function test_it_handles_empty_html_gracefully(): void
    {
        $html = '';

        $content = $this->service->extractContent($html);

        $this->assertIsArray($content);
        $this->assertNull($content['title']);
        $this->assertNull($content['description']);
        $this->assertEmpty($content['headings']);
        $this->assertEmpty($content['paragraphs']);
        $this->assertEmpty($content['links']);
        $this->assertEmpty($content['images']);
        $this->assertEquals(0, $content['word_count']);
    }

    public function test_has_url_finds_matching_url(): void
    {
        $html = '<html><body>
            <a href="/contact">Contact Us</a>
            <a href="/about">About</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertTrue($result);
    }

    public function test_has_url_returns_false_when_not_found(): void
    {
        $html = '<html><body>
            <a href="/about">About</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertFalse($result);
    }

    public function test_has_url_is_case_insensitive(): void
    {
        $html = '<html><body>
            <a href="/CONTACT">Contact</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertTrue($result);
    }

    public function test_has_url_finds_partial_matches(): void
    {
        $html = '<html><body>
            <a href="/contact-us">Contact Us</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertTrue($result);
    }

    public function test_has_url_handles_empty_html(): void
    {
        $html = '';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertFalse($result);
    }

    public function test_has_url_handles_html_without_links(): void
    {
        $html = '<html><body><p>No links here</p></body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertFalse($result);
    }

    public function test_it_extracts_multiple_paragraphs_and_filters_short_ones(): void
    {
        $html = '<html><body>
            <p>Short</p>
            <p>This is a valid paragraph with enough content to be included.</p>
            <p>Too short</p>
            <p>Another valid paragraph that has more than twenty characters.</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(2, $content['paragraphs']);
    }

    public function test_it_handles_nested_elements_in_paragraphs(): void
    {
        $html = '<html><body>
            <p>This is a paragraph with <strong>bold text</strong> and <em>italic text</em>.</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertStringContainsString('bold text', $content['paragraphs'][0]);
        $this->assertStringContainsString('italic text', $content['paragraphs'][0]);
    }

    public function test_it_handles_nested_elements_in_headings(): void
    {
        $html = '<html><body>
            <h1>Heading with <span>nested elements</span></h1>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertStringContainsString('nested elements', $content['headings'][0]);
    }

    public function test_it_extracts_external_links(): void
    {
        $html = '<html><body>
            <a href="https://example.com/page">External Link</a>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['links']);
        $this->assertEquals('https://example.com/page', $content['links'][0]['url']);
    }

    public function test_it_extracts_absolute_image_urls(): void
    {
        $html = '<html><body>
            <img src="https://cdn.example.com/image.jpg" alt="External Image">
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['images']);
        $this->assertEquals('https://cdn.example.com/image.jpg', $content['images'][0]['src']);
    }

    public function test_it_handles_malformed_html(): void
    {
        $html = '<html><body><p>Unclosed paragraph<h1>Heading</h1>';

        $content = $this->service->extractContent($html);

        $this->assertIsArray($content);
        $this->assertNotEmpty($content['headings']);
    }

    public function test_it_counts_words_correctly_with_multiple_spaces(): void
    {
        $html = '<html><body>
            <p>Words   with   multiple   spaces</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEquals(4, $content['word_count']);
    }

    public function test_it_handles_html_with_scripts_and_styles(): void
    {
        $html = '<html>
            <head>
                <script>alert("test");</script>
                <style>body { color: red; }</style>
            </head>
            <body>
                <p>This is visible content with enough characters.</p>
            </body>
        </html>';

        $content = $this->service->extractContent($html);

        $this->assertCount(1, $content['paragraphs']);
        $this->assertEquals('This is visible content with enough characters.', $content['paragraphs'][0]);
    }

    public function test_it_returns_empty_links_array_when_no_links(): void
    {
        $html = '<html><body><p>No links in this content.</p></body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEmpty($content['links']);
    }

    public function test_it_returns_empty_images_array_when_no_images(): void
    {
        $html = '<html><body><p>No images in this content.</p></body></html>';

        $content = $this->service->extractContent($html);

        $this->assertEmpty($content['images']);
    }

    public function test_it_handles_special_characters_in_content(): void
    {
        $html = '<html><body>
            <h1>Heading with & special < characters ></h1>
            <p>Paragraph with special characters: &amp; &lt; &gt; &quot;</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertNotEmpty($content['headings']);
        $this->assertNotEmpty($content['paragraphs']);
    }

    public function test_it_handles_unicode_characters(): void
    {
        $html = '<html><body>
            <h1>Überschrift mit Umlauten</h1>
            <p>Paragraph with émojis and spëcial çharacters that is long enough.</p>
        </body></html>';

        $content = $this->service->extractContent($html);

        $this->assertStringContainsString('Überschrift', $content['headings'][0]);
        $this->assertStringContainsString('émojis', $content['paragraphs'][0]);
    }

    public function test_has_url_finds_query_parameters_in_urls(): void
    {
        $html = '<html><body>
            <a href="/contact?ref=footer">Contact</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertTrue($result);
    }

    public function test_has_url_finds_fragment_identifiers(): void
    {
        $html = '<html><body>
            <a href="/page#contact">Jump to Contact</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        $this->assertTrue($result);
    }

    public function test_it_handles_multiple_title_tags(): void
    {
        $html = '<html>
            <head>
                <title>First Title</title>
                <title>Second Title</title>
            </head>
            <body></body>
        </html>';

        $content = $this->service->extractContent($html);

        // Should get the first title
        $this->assertEquals('First Title', $content['title']);
    }

    public function test_it_handles_multiple_meta_descriptions(): void
    {
        $html = '<html>
            <head>
                <meta name="description" content="First Description">
                <meta name="description" content="Second Description">
            </head>
            <body></body>
        </html>';

        $content = $this->service->extractContent($html);

        // Should get the first description
        $this->assertEquals('First Description', $content['description']);
    }

    public function test_has_url_stops_after_first_match(): void
    {
        $html = '<html><body>
            <a href="/contact">Contact 1</a>
            <a href="/contact-us">Contact 2</a>
        </body></html>';

        $result = $this->service->hasUrl($html, 'contact');

        // Should return true after finding first match
        $this->assertTrue($result);
    }
}
