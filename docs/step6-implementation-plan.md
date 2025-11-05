# Step 6 Implementation Plan: Email Templates & Mistral AI Integration

## Executive Summary

Step 6 implements a sophisticated email template system with Mistral AI integration for personalized, context-aware email generation using website content.

**Key Objectives:**
- Create flexible email template system
- Integrate Mistral AI for content personalization
- Support template variables
- Generate personalized email copy
- Provide fallback mechanisms
- Enable template testing and preview

**Dependencies:**
- Step 1 completed (Website data exists)
- Step 3 completed (Content snapshots available)
- Step 5 completed (Email tracking)

---

## 1. Database Schema

### 1.1 Email Templates Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_email_templates_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();

            // Template content
            $table->string('subject_template', 500);
            $table->text('body_template');
            $table->text('preheader')->nullable()->comment('Email preview text');

            // AI configuration
            $table->boolean('ai_enabled')->default(false)->index();
            $table->text('ai_instructions')->nullable()->comment('Instructions for Mistral AI');
            $table->string('ai_tone', 50)->default('professional')->comment('professional, friendly, casual, formal');
            $table->unsignedSmallInteger('ai_max_tokens')->default(500);

            // Settings
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('usage_count')->default(0);

            // Metadata
            $table->json('available_variables')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
```

---

## 2. Models

### 2.1 Email Template Model

**File:** `app/Models/EmailTemplate.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'subject_template',
        'body_template',
        'preheader',
        'ai_enabled',
        'ai_instructions',
        'ai_tone',
        'ai_max_tokens',
        'is_active',
        'is_default',
        'usage_count',
        'available_variables',
        'metadata',
    ];

    protected $casts = [
        'ai_enabled' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'usage_count' => 'integer',
        'ai_max_tokens' => 'integer',
        'available_variables' => 'array',
        'metadata' => 'array',
    ];

    // AI Tone constants
    public const TONE_PROFESSIONAL = 'professional';
    public const TONE_FRIENDLY = 'friendly';
    public const TONE_CASUAL = 'casual';
    public const TONE_FORMAL = 'formal';

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAiEnabled($query)
    {
        return $query->where('ai_enabled', true);
    }

    /**
     * Increment usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Get default variables
     */
    public static function getDefaultVariables(): array
    {
        return [
            '{{website_url}}' => 'The website URL',
            '{{website_title}}' => 'The website title',
            '{{website_description}}' => 'The website meta description',
            '{{contact_name}}' => 'Contact name if available',
            '{{contact_email}}' => 'Contact email address',
            '{{platform}}' => 'Detected platform (WordPress, Shopify, etc.)',
            '{{page_count}}' => 'Number of pages on website',
            '{{domain}}' => 'Domain name only',
            '{{sender_name}}' => 'Your name',
            '{{sender_company}}' => 'Your company name',
        ];
    }
}
```

---

## 3. Services

### 3.1 Mistral AI Service

**File:** `app/Services/MistralAIService.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MistralAIService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.mistral.ai/v1';

    public function __construct()
    {
        $this->apiKey = config('services.mistral.api_key');
    }

    /**
     * Generate personalized email content
     */
    public function generateEmail(
        string $instructions,
        string $websiteContent,
        array $context = [],
        string $tone = 'professional',
        int $maxTokens = 500
    ): ?string {
        try {
            $prompt = $this->buildPrompt($instructions, $websiteContent, $context, $tone);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/chat/completions', [
                'model' => 'mistral-small-latest',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional email copywriter specializing in personalized outreach emails.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['choices'][0]['message']['content'] ?? null;
            }

            Log::error('Mistral AI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Mistral AI exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build prompt for AI
     */
    protected function buildPrompt(
        string $instructions,
        string $websiteContent,
        array $context,
        string $tone
    ): string {
        $contextStr = '';
        foreach ($context as $key => $value) {
            $contextStr .= "{$key}: {$value}\n";
        }

        return <<<PROMPT
Write a personalized email based on the following:

TONE: {$tone}

INSTRUCTIONS:
{$instructions}

WEBSITE CONTEXT:
{$contextStr}

WEBSITE CONTENT:
{$websiteContent}

Generate only the email content without subject line. Keep it concise and personalized.
PROMPT;
    }

    /**
     * Generate email subject
     */
    public function generateSubject(
        string $websiteTitle,
        string $context = '',
        string $tone = 'professional'
    ): ?string {
        try {
            $prompt = <<<PROMPT
Generate a compelling email subject line for an outreach email to: {$websiteTitle}

Context: {$context}
Tone: {$tone}

Generate only the subject line, no quotes, maximum 60 characters.
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->baseUrl . '/chat/completions', [
                'model' => 'mistral-small-latest',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.8,
                'max_tokens' => 50,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $subject = $data['choices'][0]['message']['content'] ?? null;
                return trim(str_replace(['"', "'"], '', $subject));
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Mistral AI subject generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
```

---

### 3.2 Email Template Service

**File:** `app/Services/EmailTemplateService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Website;
use Illuminate\Support\Str;

class EmailTemplateService
{
    protected MistralAIService $aiService;

    public function __construct(MistralAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Render email template with variables
     */
    public function render(
        EmailTemplate $template,
        Website $website,
        Contact $contact,
        array $additionalVars = []
    ): array {
        $variables = $this->buildVariables($website, $contact, $additionalVars);

        $subject = $this->replaceVariables($template->subject_template, $variables);
        $body = $this->replaceVariables($template->body_template, $variables);

        // Use AI if enabled
        if ($template->ai_enabled) {
            $aiContent = $this->generateAIContent($template, $website, $contact);

            if ($aiContent) {
                $body = $aiContent['body'] ?? $body;
                $subject = $aiContent['subject'] ?? $subject;
            }
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'preheader' => $template->preheader,
        ];
    }

    /**
     * Generate AI-enhanced content
     */
    protected function generateAIContent(
        EmailTemplate $template,
        Website $website,
        Contact $contact
    ): ?array {
        $websiteContent = $website->content_snapshot ?? '';

        if (empty($websiteContent)) {
            return null;
        }

        // Truncate content if too long
        $websiteContent = Str::limit($websiteContent, 2000);

        $context = [
            'website_url' => $website->url,
            'website_title' => $website->title,
            'platform' => $website->detected_platform,
            'contact_name' => $contact->name ?? 'there',
        ];

        // Generate body
        $body = $this->aiService->generateEmail(
            $template->ai_instructions ?? 'Write a friendly outreach email',
            $websiteContent,
            $context,
            $template->ai_tone,
            $template->ai_max_tokens
        );

        // Generate subject if not in template
        $subject = null;
        if (Str::contains($template->subject_template, '{{ai_subject}}')) {
            $subject = $this->aiService->generateSubject(
                $website->title ?? $website->url,
                '',
                $template->ai_tone
            );
        }

        return [
            'body' => $body,
            'subject' => $subject,
        ];
    }

    /**
     * Build template variables
     */
    protected function buildVariables(
        Website $website,
        Contact $contact,
        array $additional = []
    ): array {
        $parsedUrl = parse_url($website->url);

        return array_merge([
            '{{website_url}}' => $website->url,
            '{{website_title}}' => $website->title ?? $website->url,
            '{{website_description}}' => $website->description ?? '',
            '{{contact_name}}' => $contact->name ?? 'there',
            '{{contact_email}}' => $contact->email,
            '{{platform}}' => ucfirst($website->detected_platform ?? 'unknown'),
            '{{page_count}}' => $website->page_count ?? 0,
            '{{domain}}' => $parsedUrl['host'] ?? '',
            '{{sender_name}}' => config('app.sender_name', 'Our Team'),
            '{{sender_company}}' => config('app.sender_company', 'Company'),
        ], $additional);
    }

    /**
     * Replace variables in template
     */
    protected function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace($key, $value, $template);
        }

        return $template;
    }

    /**
     * Preview template
     */
    public function preview(EmailTemplate $template, ?Website $website = null, ?Contact $contact = null): array
    {
        // Use sample data if not provided
        if (!$website) {
            $website = new Website([
                'url' => 'https://example.com',
                'title' => 'Example Website',
                'description' => 'A sample website for testing',
                'detected_platform' => 'wordpress',
                'page_count' => 25,
            ]);
        }

        if (!$contact) {
            $contact = new Contact([
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ]);
            $contact->website = $website;
        }

        return $this->render($template, $website, $contact);
    }
}
```

---

### 3.3 Email Personalization Service

**File:** `app/Services/EmailPersonalizationService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Website;

class EmailPersonalizationService
{
    /**
     * Personalize email content
     */
    public function personalize(string $content, Website $website, Contact $contact): string
    {
        // Add personalized greeting
        $content = $this->addPersonalizedGreeting($content, $contact);

        // Add website-specific context
        $content = $this->addWebsiteContext($content, $website);

        return $content;
    }

    /**
     * Add personalized greeting
     */
    protected function addPersonalizedGreeting(string $content, Contact $contact): string
    {
        if ($contact->name) {
            $greeting = "Hi {$contact->name},\n\n";
        } else {
            $greeting = "Hello,\n\n";
        }

        // Add greeting if not present
        if (!Str::startsWith($content, ['Hi ', 'Hello ', 'Hey '])) {
            $content = $greeting . $content;
        }

        return $content;
    }

    /**
     * Add website-specific context
     */
    protected function addWebsiteContext(string $content, Website $website): string
    {
        // This can be expanded with more sophisticated context addition
        return $content;
    }
}
```

---

## 4. Configuration

**File:** `config/services.php`

Add Mistral AI configuration:

```php
'mistral' => [
    'api_key' => env('MISTRAL_API_KEY'),
    'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
],
```

**File:** `.env`

```env
MISTRAL_API_KEY=your_mistral_api_key_here
MISTRAL_MODEL=mistral-small-latest
```

---

## 5. Example Templates

### Basic Template (No AI)
```php
EmailTemplate::create([
    'name' => 'Basic Outreach',
    'subject_template' => 'Quick question about {{domain}}',
    'body_template' => <<<BODY
Hi {{contact_name}},

I came across {{website_url}} and noticed you're using {{platform}}.

I'd love to discuss how we can help improve your website.

Best regards,
{{sender_name}}
BODY,
    'ai_enabled' => false,
    'is_active' => true,
]);
```

### AI-Enhanced Template
```php
EmailTemplate::create([
    'name' => 'AI Personalized Outreach',
    'subject_template' => '{{ai_subject}}',
    'body_template' => '{{ai_body}}',
    'ai_enabled' => true,
    'ai_instructions' => 'Write a personalized email offering web development services. Mention specific things you noticed about their website. Keep it under 150 words.',
    'ai_tone' => 'professional',
    'ai_max_tokens' => 500,
    'is_active' => true,
]);
```

---

## 6. Testing

**File:** `tests/Unit/Services/EmailTemplateServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Website;
use App\Services\EmailTemplateService;
use App\Services\MistralAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_replaces_template_variables()
    {
        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Hello {{contact_name}}',
            'body_template' => 'Visit {{website_url}}',
            'ai_enabled' => false,
        ]);

        $website = Website::factory()->create(['url' => 'https://example.com']);
        $contact = Contact::factory()->create(['name' => 'John']);

        $service = new EmailTemplateService(new MistralAIService());
        $result = $service->render($template, $website, $contact);

        $this->assertEquals('Hello John', $result['subject']);
        $this->assertStringContainsString('https://example.com', $result['body']);
    }

    /** @test */
    public function it_generates_preview_with_sample_data()
    {
        $template = EmailTemplate::factory()->create([
            'subject_template' => 'Hello {{contact_name}}',
            'body_template' => 'Visit {{website_url}}',
        ]);

        $service = new EmailTemplateService(new MistralAIService());
        $preview = $service->preview($template);

        $this->assertArrayHasKey('subject', $preview);
        $this->assertArrayHasKey('body', $preview);
    }
}
```

---

## 7. Usage Examples

### Render Template
```php
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;

$template = EmailTemplate::find(1);
$website = Website::find(1);
$contact = Contact::find(1);

$service = new EmailTemplateService(new MistralAIService());
$email = $service->render($template, $website, $contact);

// Returns:
// [
//     'subject' => 'Rendered subject',
//     'body' => 'Rendered body',
//     'preheader' => 'Preview text'
// ]
```

### Preview Template
```php
$preview = $service->preview($template);
// Uses sample data for preview
```

---

## 8. Implementation Checklist

- [ ] Create email_templates migration
- [ ] Create EmailTemplate model
- [ ] Set up Mistral AI account and get API key
- [ ] Create MistralAIService
- [ ] Create EmailTemplateService
- [ ] Create EmailPersonalizationService
- [ ] Add Mistral config to services.php
- [ ] Create tests
- [ ] Create sample templates
- [ ] Test AI generation

---

## Conclusion

**Estimated Time:** 6-8 hours
**Priority:** HIGH - Core outreach functionality
**Risk Level:** MEDIUM - Depends on external AI API
**Next Document:** `step7-implementation-plan.md`
