<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Website;
use App\Services\EmailTemplateService;
use App\Services\MistralAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmailTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EmailTemplateService $service;
    protected $aiServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiServiceMock = Mockery::mock(MistralAIService::class);
        $this->service = new EmailTemplateService($this->aiServiceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_renders_template_with_basic_variables()
    {
        config([
            'app.sender_name' => 'John Doe',
            'app.sender_company' => 'Acme Corp',
        ]);

        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'title' => 'Example Site',
            'description' => 'A great website',
            'detected_platform' => 'wordpress',
            'page_count' => 25,
        ]);

        $contact = Contact::factory()->create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'website_id' => $website->id,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Hello {{contact_name}}',
            'body_template' => 'Your site {{website_title}} on {{platform}} looks great!',
            'preheader' => 'Quick question',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Hello Jane Smith', $result['subject']);
        $this->assertEquals('Your site Example Site on Wordpress looks great!', $result['body']);
        $this->assertEquals('Quick question', $result['preheader']);
    }

    /** @test */
    public function it_replaces_all_available_variables()
    {
        config([
            'app.sender_name' => 'John',
            'app.sender_company' => 'Company',
        ]);

        $website = Website::factory()->create([
            'url' => 'https://test.example.com/page',
            'title' => 'Test Site',
            'description' => 'Description here',
            'detected_platform' => 'shopify',
            'page_count' => 50,
        ]);

        $contact = Contact::factory()->create([
            'name' => 'Bob',
            'email' => 'bob@test.com',
            'website_id' => $website->id,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{contact_name}} at {{domain}}',
            'body_template' => '{{website_url}} - {{website_title}} - {{website_description}} - {{platform}} - {{page_count}} - {{sender_name}} - {{sender_company}} - {{contact_email}}',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertStringContainsString('Bob', $result['subject']);
        $this->assertStringContainsString('test.example.com', $result['subject']);
        $this->assertStringContainsString('https://test.example.com/page', $result['body']);
        $this->assertStringContainsString('Test Site', $result['body']);
        $this->assertStringContainsString('Description here', $result['body']);
        $this->assertStringContainsString('Shopify', $result['body']);
        $this->assertStringContainsString('50', $result['body']);
        $this->assertStringContainsString('John', $result['body']);
        $this->assertStringContainsString('Company', $result['body']);
        $this->assertStringContainsString('bob@test.com', $result['body']);
    }

    /** @test */
    public function it_uses_default_values_for_missing_contact_name()
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'name' => null,
            'website_id' => $website->id,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Hello {{contact_name}}',
            'body_template' => 'Hi {{contact_name}}',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Hello there', $result['subject']);
        $this->assertEquals('Hi there', $result['body']);
    }

    /** @test */
    public function it_handles_missing_website_data()
    {
        $website = Website::factory()->create([
            'title' => null,
            'description' => null,
            'detected_platform' => null,
        ]);

        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{website_title}} - {{platform}}',
            'body_template' => '{{website_description}}',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        // Should use URL as fallback for title
        $this->assertStringContainsString($website->url, $result['subject']);
        $this->assertStringContainsString('Unknown', $result['subject']);
        $this->assertEquals('', $result['body']);
    }

    /** @test */
    public function it_uses_additional_variables()
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{custom_var}}',
            'body_template' => 'Value: {{another_var}}',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact, [
            '{{custom_var}}' => 'Custom Value',
            '{{another_var}}' => '12345',
        ]);

        $this->assertEquals('Custom Value', $result['subject']);
        $this->assertEquals('Value: 12345', $result['body']);
    }

    /** @test */
    public function it_generates_ai_content_when_enabled()
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'title' => 'Example',
            'detected_platform' => 'wordpress',
            'content_snapshot' => 'Website content here',
        ]);

        $contact = Contact::factory()->create([
            'name' => 'John',
            'website_id' => $website->id,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Default subject',
            'body_template' => 'Default body',
            'ai_enabled' => true,
            'ai_instructions' => 'Write a friendly email',
            'ai_tone' => 'casual',
            'ai_max_tokens' => 300,
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->once()
            ->with(
                'Write a friendly email',
                'Website content here',
                [
                    'website_url' => 'https://example.com',
                    'website_title' => 'Example',
                    'platform' => 'wordpress',
                    'contact_name' => 'John',
                ],
                'casual',
                300
            )
            ->andReturn('AI generated email body');

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('AI generated email body', $result['body']);
        $this->assertEquals('Default subject', $result['subject']);
    }

    /** @test */
    public function it_generates_ai_subject_when_template_contains_ai_subject_variable()
    {
        $website = Website::factory()->create([
            'title' => 'Tech Blog',
            'content_snapshot' => 'Content',
        ]);

        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{ai_subject}}',
            'body_template' => 'Body',
            'ai_enabled' => true,
            'ai_tone' => 'professional',
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->andReturn('AI body');

        $this->aiServiceMock
            ->shouldReceive('generateSubject')
            ->once()
            ->with('Tech Blog', '', 'professional')
            ->andReturn('AI Generated Subject');

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('AI Generated Subject', $result['subject']);
        $this->assertEquals('AI body', $result['body']);
    }

    /** @test */
    public function it_does_not_generate_ai_subject_when_template_does_not_contain_variable()
    {
        $website = Website::factory()->create(['content_snapshot' => 'Content']);
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Regular Subject',
            'body_template' => 'Body',
            'ai_enabled' => true,
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->andReturn('AI body');

        $this->aiServiceMock
            ->shouldNotReceive('generateSubject');

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Regular Subject', $result['subject']);
    }

    /** @test */
    public function it_falls_back_to_template_when_ai_generation_fails()
    {
        $website = Website::factory()->create(['content_snapshot' => 'Content']);
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Fallback Subject',
            'body_template' => 'Fallback Body',
            'ai_enabled' => true,
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->andReturn(null); // AI failed

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Fallback Subject', $result['subject']);
        $this->assertEquals('Fallback Body', $result['body']);
    }

    /** @test */
    public function it_does_not_use_ai_when_content_snapshot_is_empty()
    {
        $website = Website::factory()->create(['content_snapshot' => null]);
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'ai_enabled' => true,
        ]);

        $this->aiServiceMock
            ->shouldNotReceive('generateEmail');

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Subject', $result['subject']);
        $this->assertEquals('Body', $result['body']);
    }

    /** @test */
    public function it_truncates_long_content_for_ai()
    {
        $longContent = str_repeat('This is a long content. ', 500); // Very long content

        $website = Website::factory()->create([
            'title' => 'Site',
            'content_snapshot' => $longContent,
        ]);

        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'ai_enabled' => true,
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->once()
            ->withArgs(function ($instructions, $content) {
                // Content should be truncated to 2000 characters
                return strlen($content) <= 2003; // 2000 + '...'
            })
            ->andReturn('AI email');

        $this->service->render($template, $website, $contact);
    }

    /** @test */
    public function it_previews_template_with_sample_data()
    {
        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Hello {{contact_name}}',
            'body_template' => 'Your site {{website_title}} has {{page_count}} pages',
            'ai_enabled' => false,
        ]);

        $result = $this->service->preview($template);

        $this->assertEquals('Hello John Doe', $result['subject']);
        $this->assertStringContainsString('Example Website', $result['body']);
        $this->assertStringContainsString('25', $result['body']);
    }

    /** @test */
    public function it_previews_template_with_custom_website()
    {
        $website = Website::factory()->create([
            'title' => 'Custom Site',
            'page_count' => 100,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{website_title}}',
            'body_template' => '{{page_count}} pages',
            'ai_enabled' => false,
        ]);

        $result = $this->service->preview($template, $website);

        $this->assertEquals('Custom Site', $result['subject']);
        $this->assertEquals('100 pages', $result['body']);
    }

    /** @test */
    public function it_previews_template_with_custom_contact()
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Alice',
            'email' => 'alice@test.com',
            'website_id' => $website->id,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{contact_name}}',
            'body_template' => '{{contact_email}}',
            'ai_enabled' => false,
        ]);

        $result = $this->service->preview($template, $website, $contact);

        $this->assertEquals('Alice', $result['subject']);
        $this->assertEquals('alice@test.com', $result['body']);
    }

    /** @test */
    public function it_uses_default_ai_instructions_when_not_provided()
    {
        $website = Website::factory()->create(['content_snapshot' => 'Content']);
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'ai_enabled' => true,
            'ai_instructions' => null,
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->once()
            ->withArgs(function ($instructions) {
                return $instructions === 'Write a friendly outreach email';
            })
            ->andReturn('AI email');

        $this->service->render($template, $website, $contact);
    }

    /** @test */
    public function it_extracts_domain_from_url_correctly()
    {
        $website = Website::factory()->create([
            'url' => 'https://subdomain.example.com:8080/path?query=1',
        ]);

        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{domain}}',
            'body_template' => 'Body',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('subdomain.example.com', $result['subject']);
    }

    /** @test */
    public function it_handles_url_without_host()
    {
        $website = Website::factory()->create([
            'url' => '/relative/path',
        ]);

        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{domain}}',
            'body_template' => 'Body',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('', $result['subject']);
    }

    /** @test */
    public function it_capitalizes_platform_name()
    {
        $website = Website::factory()->create(['detected_platform' => 'wordpress']);
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{platform}}',
            'body_template' => 'Body',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Wordpress', $result['subject']);
    }

    /** @test */
    public function it_handles_unknown_platform()
    {
        $website = Website::factory()->create(['detected_platform' => null]);
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{platform}}',
            'body_template' => 'Body',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('Unknown', $result['subject']);
    }

    /** @test */
    public function it_uses_url_as_fallback_for_website_title()
    {
        $website = Website::factory()->create([
            'url' => 'https://example.com',
            'title' => null,
        ]);

        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => '{{website_title}}',
            'body_template' => 'Body',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('https://example.com', $result['subject']);
    }

    /** @test */
    public function it_uses_there_as_fallback_for_contact_name_in_ai_context()
    {
        $website = Website::factory()->create(['content_snapshot' => 'Content']);
        $contact = Contact::factory()->create([
            'name' => null,
            'website_id' => $website->id,
        ]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'ai_enabled' => true,
        ]);

        $this->aiServiceMock
            ->shouldReceive('generateEmail')
            ->once()
            ->withArgs(function ($instructions, $content, $context) {
                return $context['contact_name'] === 'there';
            })
            ->andReturn('AI email');

        $this->service->render($template, $website, $contact);
    }

    /** @test */
    public function it_returns_preheader_from_template()
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'preheader' => 'This is the preheader text',
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertEquals('This is the preheader text', $result['preheader']);
    }

    /** @test */
    public function it_handles_null_preheader()
    {
        $website = Website::factory()->create();
        $contact = Contact::factory::create(['website_id' => $website->id]);

        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'preheader' => null,
            'ai_enabled' => false,
        ]);

        $result = $this->service->render($template, $website, $contact);

        $this->assertNull($result['preheader']);
    }
}
