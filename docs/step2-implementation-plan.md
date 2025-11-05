# Step 2 Implementation Plan: Email/Contact Extraction System

## Executive Summary

This document outlines the complete implementation plan for Step 2 of the automated website research and outreach application. Step 2 focuses on building a robust email and contact extraction system that automatically discovers contact information from crawled websites.

**Key Objectives:**
- Create database infrastructure to store extracted contact information
- Build intelligent email extraction from HTML pages
- Implement email validation (format + MX records)
- Detect common contact page patterns
- Prevent duplicate contacts per website and globally
- Track contact source and validation status

**Dependencies:**
- Step 1 completed (Domains and Websites tables exist)

---

## 1. Database Schema Design

### 1.1 Contacts Table

**Purpose:** Store all extracted email addresses and contact information with validation status.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_contacts_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');

            // Contact information
            $table->string('email')->index()->comment('Email address');
            $table->string('name')->nullable()->comment('Contact name if found');
            $table->string('phone')->nullable()->comment('Phone number if found');
            $table->string('position')->nullable()->comment('Job title/position if found');

            // Source tracking
            $table->string('source_url', 500)->nullable()->comment('URL where contact was found');
            $table->string('source_type', 50)->nullable()->index()->comment('contact_page, about_page, footer, body, etc.');
            $table->text('source_context')->nullable()->comment('Surrounding text for context');

            // Validation
            $table->boolean('is_validated')->default(false)->index()->comment('Has email been validated');
            $table->boolean('is_valid')->nullable()->index()->comment('Result of validation');
            $table->string('validation_error')->nullable()->comment('Error message if invalid');
            $table->timestamp('validated_at')->nullable()->comment('When validation occurred');

            // MX record validation
            $table->boolean('mx_valid')->nullable()->comment('MX records exist for domain');
            $table->string('mx_host')->nullable()->comment('Primary MX host');

            // Priority and confidence
            $table->unsignedTinyInteger('priority')->default(50)->index()->comment('Contact priority (1-100)');
            $table->unsignedTinyInteger('confidence_score')->default(50)->comment('Confidence this is valid (1-100)');

            // Outreach tracking
            $table->boolean('contacted')->default(false)->index()->comment('Has been contacted');
            $table->timestamp('first_contacted_at')->nullable()->comment('First outreach timestamp');
            $table->unsignedInteger('contact_count')->default(0)->comment('Number of times contacted');

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes
            $table->unique(['website_id', 'email'], 'contacts_website_email_unique');
            $table->index(['email', 'contacted'], 'contacts_email_contacted_idx');
            $table->index(['is_validated', 'is_valid'], 'contacts_validation_idx');
            $table->index(['source_type', 'priority'], 'contacts_source_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
```

**Raw SQL Equivalent:**

```sql
CREATE TABLE `contacts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `website_id` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(255) NOT NULL COMMENT 'Email address',
    `name` VARCHAR(255) NULL COMMENT 'Contact name if found',
    `phone` VARCHAR(255) NULL COMMENT 'Phone number if found',
    `position` VARCHAR(255) NULL COMMENT 'Job title/position if found',
    `source_url` VARCHAR(500) NULL COMMENT 'URL where contact was found',
    `source_type` VARCHAR(50) NULL COMMENT 'contact_page, about_page, footer, body, etc.',
    `source_context` TEXT NULL COMMENT 'Surrounding text for context',
    `is_validated` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Has email been validated',
    `is_valid` BOOLEAN NULL COMMENT 'Result of validation',
    `validation_error` VARCHAR(255) NULL COMMENT 'Error message if invalid',
    `validated_at` TIMESTAMP NULL COMMENT 'When validation occurred',
    `mx_valid` BOOLEAN NULL COMMENT 'MX records exist for domain',
    `mx_host` VARCHAR(255) NULL COMMENT 'Primary MX host',
    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Contact priority (1-100)',
    `confidence_score` TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Confidence this is valid (1-100)',
    `contacted` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Has been contacted',
    `first_contacted_at` TIMESTAMP NULL COMMENT 'First outreach timestamp',
    `contact_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times contacted',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `contacts_email_index` (`email`),
    INDEX `contacts_source_type_index` (`source_type`),
    INDEX `contacts_is_validated_index` (`is_validated`),
    INDEX `contacts_is_valid_index` (`is_valid`),
    INDEX `contacts_priority_index` (`priority`),
    INDEX `contacts_contacted_index` (`contacted`),
    UNIQUE INDEX `contacts_website_email_unique` (`website_id`, `email`),
    INDEX `contacts_email_contacted_idx` (`email`, `contacted`),
    INDEX `contacts_validation_idx` (`is_validated`, `is_valid`),
    INDEX `contacts_source_priority_idx` (`source_type`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Priority Scoring System:**
- 100: Found on dedicated contact page
- 90: Found in "Contact Us" section
- 80: Found on About page
- 70: Found in footer
- 60: Found in header/navigation
- 50: Found in body content
- 30: Generic emails (info@, admin@, etc.)
- 10: Role-based emails (noreply@, postmaster@, etc.)

---

## 2. Eloquent Models

### 2.1 Contact Model

**File:** `app/Models/Contact.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'website_id',
        'email',
        'name',
        'phone',
        'position',
        'source_url',
        'source_type',
        'source_context',
        'is_validated',
        'is_valid',
        'validation_error',
        'validated_at',
        'mx_valid',
        'mx_host',
        'priority',
        'confidence_score',
        'contacted',
        'first_contacted_at',
        'contact_count',
    ];

    protected $casts = [
        'is_validated' => 'boolean',
        'is_valid' => 'boolean',
        'mx_valid' => 'boolean',
        'contacted' => 'boolean',
        'priority' => 'integer',
        'confidence_score' => 'integer',
        'contact_count' => 'integer',
        'validated_at' => 'datetime',
        'first_contacted_at' => 'datetime',
    ];

    // Source type constants
    public const SOURCE_CONTACT_PAGE = 'contact_page';
    public const SOURCE_ABOUT_PAGE = 'about_page';
    public const SOURCE_FOOTER = 'footer';
    public const SOURCE_HEADER = 'header';
    public const SOURCE_BODY = 'body';
    public const SOURCE_TEAM_PAGE = 'team_page';

    /**
     * Get the website this contact belongs to
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Get all email logs for this contact
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailSentLog::class);
    }

    /**
     * Scope: Get only validated contacts
     */
    public function scopeValidated($query)
    {
        return $query->where('is_validated', true)
            ->where('is_valid', true);
    }

    /**
     * Scope: Get high priority contacts
     */
    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 70);
    }

    /**
     * Scope: Not yet contacted
     */
    public function scopeNotContacted($query)
    {
        return $query->where('contacted', false);
    }

    /**
     * Scope: Filter by source type
     */
    public function scopeFromSource($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Mark contact as validated
     */
    public function markAsValidated(bool $isValid, ?string $error = null, ?array $mxData = null): void
    {
        $this->update([
            'is_validated' => true,
            'is_valid' => $isValid,
            'validation_error' => $error,
            'validated_at' => now(),
            'mx_valid' => $mxData['mx_valid'] ?? null,
            'mx_host' => $mxData['mx_host'] ?? null,
        ]);
    }

    /**
     * Mark as contacted
     */
    public function markAsContacted(): void
    {
        $this->update([
            'contacted' => true,
            'first_contacted_at' => $this->first_contacted_at ?? now(),
            'contact_count' => $this->contact_count + 1,
        ]);
    }

    /**
     * Check if email is generic
     */
    public function isGenericEmail(): bool
    {
        $genericPrefixes = [
            'info', 'contact', 'admin', 'support', 'sales', 'hello',
            'mail', 'office', 'general', 'inquiry', 'help'
        ];

        $localPart = explode('@', $this->email)[0] ?? '';

        return in_array(strtolower($localPart), $genericPrefixes);
    }

    /**
     * Check if email is a noreply address
     */
    public function isNoReplyEmail(): bool
    {
        $noReplyPatterns = ['noreply', 'no-reply', 'donotreply', 'postmaster', 'mailer-daemon'];

        $email = strtolower($this->email);

        foreach ($noReplyPatterns as $pattern) {
            if (str_contains($email, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get priority label
     */
    public function getPriorityLabelAttribute(): string
    {
        return match(true) {
            $this->priority >= 90 => 'Very High',
            $this->priority >= 70 => 'High',
            $this->priority >= 50 => 'Medium',
            $this->priority >= 30 => 'Low',
            default => 'Very Low',
        };
    }

    /**
     * Auto-calculate priority on creation
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($contact) {
            if ($contact->priority === 50) { // Default value
                $contact->priority = static::calculatePriority($contact);
            }
        });
    }

    /**
     * Calculate priority based on source and email type
     */
    protected static function calculatePriority(Contact $contact): int
    {
        $priority = 50; // Base priority

        // Boost based on source
        $priority += match($contact->source_type) {
            self::SOURCE_CONTACT_PAGE => 50,
            self::SOURCE_ABOUT_PAGE => 30,
            self::SOURCE_TEAM_PAGE => 35,
            self::SOURCE_FOOTER => 20,
            self::SOURCE_HEADER => 10,
            default => 0,
        };

        // Reduce for generic emails
        if ($contact->isGenericEmail()) {
            $priority -= 20;
        }

        // Severely reduce for noreply
        if ($contact->isNoReplyEmail()) {
            $priority = 10;
        }

        // Boost if name found
        if ($contact->name) {
            $priority += 15;
        }

        // Boost if position found
        if ($contact->position) {
            $priority += 10;
        }

        return max(1, min(100, $priority)); // Clamp between 1-100
    }
}
```

---

## 3. Services

### 3.1 Contact Extraction Service

**File:** `app/Services/ContactExtractionService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Website;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ContactExtractionService
{
    /**
     * Email regex pattern
     */
    protected const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    /**
     * Contact page URL patterns
     */
    protected const CONTACT_PAGE_PATTERNS = [
        '/contact',
        '/contact-us',
        '/contactus',
        '/get-in-touch',
        '/reach-us',
        '/kontakt', // German
        '/contacto', // Spanish
    ];

    /**
     * About page URL patterns
     */
    protected const ABOUT_PAGE_PATTERNS = [
        '/about',
        '/about-us',
        '/aboutus',
        '/who-we-are',
        '/our-team',
        '/team',
        '/meet-the-team',
    ];

    /**
     * Extract contacts from HTML content
     */
    public function extractFromHtml(
        string $html,
        string $url,
        Website $website,
        ?string $sourceType = null
    ): array {
        $crawler = new Crawler($html);
        $contacts = [];

        // Determine source type if not provided
        if (!$sourceType) {
            $sourceType = $this->determineSourceType($url);
        }

        // Extract emails with context
        $emailMatches = $this->findEmailsWithContext($html, $crawler);

        foreach ($emailMatches as $match) {
            // Skip if already exists for this website
            if ($this->contactExists($website->id, $match['email'])) {
                continue;
            }

            // Create contact
            $contact = Contact::create([
                'website_id' => $website->id,
                'email' => $match['email'],
                'name' => $match['name'] ?? null,
                'position' => $match['position'] ?? null,
                'source_url' => $url,
                'source_type' => $sourceType,
                'source_context' => $match['context'] ?? null,
            ]);

            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * Find emails with surrounding context
     */
    protected function findEmailsWithContext(string $html, Crawler $crawler): array
    {
        $results = [];
        $seenEmails = [];

        // First try to find emails in mailto links
        $crawler->filter('a[href^="mailto:"]')->each(function (Crawler $node) use (&$results, &$seenEmails) {
            $href = $node->attr('href');
            $email = str_replace('mailto:', '', $href);
            $email = strtolower(trim(explode('?', $email)[0])); // Remove query params

            if ($this->isValidEmailFormat($email) && !isset($seenEmails[$email])) {
                $seenEmails[$email] = true;
                $results[] = [
                    'email' => $email,
                    'context' => $node->text(),
                    'name' => $this->extractNameFromContext($node->text()),
                ];
            }
        });

        // Find emails in text content
        if (preg_match_all(self::EMAIL_PATTERN, $html, $matches)) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim($email));

                if ($this->isValidEmailFormat($email) && !isset($seenEmails[$email])) {
                    $seenEmails[$email] = true;
                    $context = $this->extractContext($html, $email);

                    $results[] = [
                        'email' => $email,
                        'context' => $context,
                        'name' => $this->extractNameFromContext($context),
                        'position' => $this->extractPositionFromContext($context),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Extract surrounding context for an email
     */
    protected function extractContext(string $html, string $email, int $contextLength = 200): string
    {
        $position = strpos($html, $email);

        if ($position === false) {
            return '';
        }

        $start = max(0, $position - $contextLength);
        $length = $contextLength * 2 + strlen($email);

        $context = substr($html, $start, $length);
        $context = strip_tags($context);
        $context = preg_replace('/\s+/', ' ', $context);

        return trim($context);
    }

    /**
     * Try to extract a name from context
     */
    protected function extractNameFromContext(?string $context): ?string
    {
        if (!$context) {
            return null;
        }

        // Common patterns: "Contact John Doe", "Email: Jane Smith", etc.
        $patterns = [
            '/(?:contact|email|reach|write to)\s+([A-Z][a-z]+\s+[A-Z][a-z]+)/i',
            '/([A-Z][a-z]+\s+[A-Z][a-z]+)\s*[-–]\s*(?:CEO|CTO|Manager|Director)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $context, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Try to extract position/title from context
     */
    protected function extractPositionFromContext(?string $context): ?string
    {
        if (!$context) {
            return null;
        }

        $titles = ['CEO', 'CTO', 'CFO', 'COO', 'Director', 'Manager', 'Founder', 'Co-Founder', 'President', 'VP'];

        foreach ($titles as $title) {
            if (stripos($context, $title) !== false) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Determine source type from URL
     */
    protected function determineSourceType(string $url): string
    {
        $url = strtolower($url);

        foreach (self::CONTACT_PAGE_PATTERNS as $pattern) {
            if (str_contains($url, $pattern)) {
                return Contact::SOURCE_CONTACT_PAGE;
            }
        }

        foreach (self::ABOUT_PAGE_PATTERNS as $pattern) {
            if (str_contains($url, $pattern)) {
                return Contact::SOURCE_ABOUT_PAGE;
            }
        }

        return Contact::SOURCE_BODY;
    }

    /**
     * Check if contact already exists
     */
    protected function contactExists(int $websiteId, string $email): bool
    {
        return Contact::where('website_id', $websiteId)
            ->where('email', $email)
            ->exists();
    }

    /**
     * Validate email format
     */
    protected function isValidEmailFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Get priority URLs to check for contacts
     */
    public function getPriorityUrls(Website $website): array
    {
        $baseUrl = rtrim($website->url, '/');

        return [
            $baseUrl . '/contact',
            $baseUrl . '/contact-us',
            $baseUrl . '/about',
            $baseUrl . '/about-us',
            $baseUrl . '/team',
            $baseUrl . '/our-team',
            $baseUrl, // Homepage
        ];
    }
}
```

---

### 3.2 Email Validation Service

**File:** `app/Services/EmailValidationService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;

class EmailValidationService
{
    /**
     * Validate a contact's email address
     */
    public function validate(Contact $contact): bool
    {
        // Format validation
        if (!$this->isValidFormat($contact->email)) {
            $contact->markAsValidated(false, 'Invalid email format');
            return false;
        }

        // Check if disposable email domain
        if ($this->isDisposableEmail($contact->email)) {
            $contact->markAsValidated(false, 'Disposable email domain');
            return false;
        }

        // MX record validation
        $mxResult = $this->validateMxRecords($contact->email);

        if (!$mxResult['valid']) {
            $contact->markAsValidated(false, $mxResult['error'], $mxResult);
            return false;
        }

        // All validations passed
        $contact->markAsValidated(true, null, $mxResult);
        return true;
    }

    /**
     * Validate email format
     */
    protected function isValidFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if email is from disposable domain
     */
    protected function isDisposableEmail(string $email): bool
    {
        $disposableDomains = [
            'tempmail.com',
            'guerrillamail.com',
            '10minutemail.com',
            'mailinator.com',
            'throwaway.email',
        ];

        $domain = strtolower(explode('@', $email)[1] ?? '');

        return in_array($domain, $disposableDomains);
    }

    /**
     * Validate MX records for email domain
     */
    protected function validateMxRecords(string $email): array
    {
        $domain = explode('@', $email)[1] ?? '';

        if (empty($domain)) {
            return [
                'valid' => false,
                'error' => 'Invalid domain',
                'mx_valid' => false,
                'mx_host' => null,
            ];
        }

        try {
            // Check MX records
            if (getmxrr($domain, $mxHosts, $weights)) {
                // Sort by priority (weight)
                array_multisort($weights, SORT_ASC, $mxHosts);

                return [
                    'valid' => true,
                    'error' => null,
                    'mx_valid' => true,
                    'mx_host' => $mxHosts[0] ?? null,
                ];
            }

            // No MX records, but check if A record exists (fallback)
            if (checkdnsrr($domain, 'A')) {
                return [
                    'valid' => true,
                    'error' => null,
                    'mx_valid' => false,
                    'mx_host' => $domain,
                ];
            }

            return [
                'valid' => false,
                'error' => 'No MX or A records found',
                'mx_valid' => false,
                'mx_host' => null,
            ];
        } catch (\Exception $e) {
            Log::error('MX validation error', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return [
                'valid' => false,
                'error' => 'DNS lookup failed: ' . $e->getMessage(),
                'mx_valid' => false,
                'mx_host' => null,
            ];
        }
    }

    /**
     * Batch validate multiple contacts
     */
    public function batchValidate(array $contacts): array
    {
        $results = [
            'validated' => 0,
            'valid' => 0,
            'invalid' => 0,
        ];

        foreach ($contacts as $contact) {
            $results['validated']++;

            if ($this->validate($contact)) {
                $results['valid']++;
            } else {
                $results['invalid']++;
            }
        }

        return $results;
    }
}
```

---

## 4. Queue Jobs

### 4.1 Extract Contacts Job

**File:** `app/Jobs/ExtractContactsJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\ContactExtractionService;
use App\Services\EmailValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExtractContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Website $website,
        public bool $validateImmediately = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ContactExtractionService $extractionService,
        EmailValidationService $validationService
    ): void {
        Log::info('Starting contact extraction', [
            'website_id' => $this->website->id,
            'url' => $this->website->url,
        ]);

        $extractedCount = 0;
        $validatedCount = 0;

        // Get priority URLs to check
        $urls = $extractionService->getPriorityUrls($this->website);

        foreach ($urls as $url) {
            try {
                // Fetch page content
                $response = Http::timeout(10)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; LeadBot/1.0)',
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    continue;
                }

                // Extract contacts from this page
                $contacts = $extractionService->extractFromHtml(
                    $response->body(),
                    $url,
                    $this->website
                );

                $extractedCount += count($contacts);

                // Validate immediately if requested
                if ($this->validateImmediately) {
                    foreach ($contacts as $contact) {
                        if ($validationService->validate($contact)) {
                            $validatedCount++;
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::warning('Failed to extract contacts from URL', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // Be polite - small delay between requests
            usleep(500000); // 0.5 second delay
        }

        Log::info('Contact extraction completed', [
            'website_id' => $this->website->id,
            'extracted' => $extractedCount,
            'validated' => $validatedCount,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Contact extraction job failed', [
            'website_id' => $this->website->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

## 5. Testing Strategy

### 5.1 Unit Tests

#### Test: Contact Model

**File:** `tests/Unit/Models/ContactTest.php`

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Contact;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_contact()
    {
        $website = Website::factory()->create();

        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'test@example.com',
        ]);

        $this->assertDatabaseHas('contacts', [
            'email' => 'test@example.com',
            'website_id' => $website->id,
        ]);
    }

    /** @test */
    public function it_belongs_to_a_website()
    {
        $contact = Contact::factory()->create();

        $this->assertInstanceOf(Website::class, $contact->website);
    }

    /** @test */
    public function it_calculates_priority_for_contact_page()
    {
        $contact = Contact::factory()->create([
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'name' => 'John Doe',
        ]);

        // Base (50) + Contact Page (50) + Has Name (15) = 115 (clamped to 100)
        $this->assertEquals(100, $contact->priority);
    }

    /** @test */
    public function it_reduces_priority_for_generic_emails()
    {
        $contact = Contact::factory()->create([
            'email' => 'info@example.com',
            'source_type' => Contact::SOURCE_BODY,
        ]);

        // Should be reduced because it's a generic email
        $this->assertLessThan(50, $contact->priority);
    }

    /** @test */
    public function it_identifies_generic_emails()
    {
        $contact = Contact::factory()->create([
            'email' => 'info@example.com',
        ]);

        $this->assertTrue($contact->isGenericEmail());
    }

    /** @test */
    public function it_identifies_noreply_emails()
    {
        $contact = Contact::factory()->create([
            'email' => 'noreply@example.com',
        ]);

        $this->assertTrue($contact->isNoReplyEmail());
    }

    /** @test */
    public function it_can_mark_as_validated()
    {
        $contact = Contact::factory()->create([
            'is_validated' => false,
        ]);

        $contact->markAsValidated(true, null, [
            'mx_valid' => true,
            'mx_host' => 'mail.example.com',
        ]);

        $this->assertTrue($contact->fresh()->is_validated);
        $this->assertTrue($contact->fresh()->is_valid);
        $this->assertTrue($contact->fresh()->mx_valid);
    }

    /** @test */
    public function it_can_mark_as_contacted()
    {
        $contact = Contact::factory()->create([
            'contacted' => false,
            'contact_count' => 0,
        ]);

        $contact->markAsContacted();

        $contact = $contact->fresh();
        $this->assertTrue($contact->contacted);
        $this->assertEquals(1, $contact->contact_count);
        $this->assertNotNull($contact->first_contacted_at);
    }

    /** @test */
    public function validated_scope_returns_only_valid_contacts()
    {
        Contact::factory()->count(3)->create([
            'is_validated' => true,
            'is_valid' => true,
        ]);

        Contact::factory()->count(2)->create([
            'is_validated' => true,
            'is_valid' => false,
        ]);

        $validated = Contact::validated()->get();

        $this->assertCount(3, $validated);
    }

    /** @test */
    public function it_prevents_duplicate_emails_per_website()
    {
        $website = Website::factory()->create();

        Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'same@example.com',
        ]);

        $this->expectException(\Exception::class);

        Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'same@example.com',
        ]);
    }

    /** @test */
    public function it_allows_same_email_for_different_websites()
    {
        $website1 = Website::factory()->create();
        $website2 = Website::factory()->create();

        $contact1 = Contact::factory()->create([
            'website_id' => $website1->id,
            'email' => 'shared@example.com',
        ]);

        $contact2 = Contact::factory()->create([
            'website_id' => $website2->id,
            'email' => 'shared@example.com',
        ]);

        $this->assertNotEquals($contact1->id, $contact2->id);
    }
}
```

---

### 5.2 Feature Tests

#### Test: Contact Extraction

**File:** `tests/Feature/ContactExtractionTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\ContactExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactExtractionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_extracts_emails_from_html()
    {
        $website = Website::factory()->create();
        $service = new ContactExtractionService();

        $html = '
            <html>
                <body>
                    <p>Contact us at <a href="mailto:info@example.com">info@example.com</a></p>
                    <footer>Email: support@example.com</footer>
                </body>
            </html>
        ';

        $contacts = $service->extractFromHtml($html, $website->url, $website);

        $this->assertCount(2, $contacts);
        $this->assertEquals('info@example.com', $contacts[0]->email);
        $this->assertEquals('support@example.com', $contacts[1]->email);
    }

    /** @test */
    public function it_determines_source_type_from_url()
    {
        $website = Website::factory()->create();
        $service = new ContactExtractionService();

        $html = '<a href="mailto:test@example.com">Contact</a>';

        $contacts = $service->extractFromHtml(
            $html,
            'https://example.com/contact',
            $website
        );

        $this->assertEquals('contact_page', $contacts[0]->source_type);
    }

    /** @test */
    public function it_skips_duplicate_emails_for_same_website()
    {
        $website = Website::factory()->create();
        $service = new ContactExtractionService();

        $html = '<a href="mailto:duplicate@example.com">Email</a>';

        // First extraction
        $contacts1 = $service->extractFromHtml($html, $website->url, $website);
        $this->assertCount(1, $contacts1);

        // Second extraction - should skip duplicate
        $contacts2 = $service->extractFromHtml($html, $website->url, $website);
        $this->assertCount(0, $contacts2);
    }

    /** @test */
    public function it_extracts_name_from_context()
    {
        $website = Website::factory()->create();
        $service = new ContactExtractionService();

        $html = '
            <div>
                <p>Contact John Smith at <a href="mailto:john@example.com">john@example.com</a></p>
            </div>
        ';

        $contacts = $service->extractFromHtml($html, $website->url, $website);

        $this->assertEquals('John Smith', $contacts[0]->name);
    }
}
```

---

## 6. Database Factories

### 6.1 Contact Factory

**File:** `database/factories/ContactFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $sourceTypes = [
            Contact::SOURCE_CONTACT_PAGE,
            Contact::SOURCE_ABOUT_PAGE,
            Contact::SOURCE_FOOTER,
            Contact::SOURCE_HEADER,
            Contact::SOURCE_BODY,
        ];

        return [
            'website_id' => Website::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->optional(0.6)->name(),
            'phone' => fake()->optional(0.3)->phoneNumber(),
            'position' => fake()->optional(0.4)->randomElement(['CEO', 'CTO', 'Manager', 'Director']),
            'source_url' => fake()->url(),
            'source_type' => fake()->randomElement($sourceTypes),
            'source_context' => fake()->optional(0.7)->sentence(),
            'is_validated' => fake()->boolean(60),
            'is_valid' => fake()->boolean(80),
            'validation_error' => null,
            'validated_at' => fake()->optional(0.6)->dateTimeBetween('-7 days'),
            'mx_valid' => fake()->optional(0.6)->boolean(90),
            'mx_host' => fake()->optional(0.6)->domainName(),
            'priority' => fake()->numberBetween(1, 100),
            'confidence_score' => fake()->numberBetween(1, 100),
            'contacted' => fake()->boolean(30),
            'first_contacted_at' => null,
            'contact_count' => 0,
        ];
    }

    /**
     * Indicate that the contact is validated and valid
     */
    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_validated' => true,
            'is_valid' => true,
            'validated_at' => now(),
            'mx_valid' => true,
            'mx_host' => fake()->domainName(),
        ]);
    }

    /**
     * Indicate that the contact is high priority
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'name' => fake()->name(),
            'priority' => fake()->numberBetween(80, 100),
        ]);
    }

    /**
     * Indicate that the contact has been contacted
     */
    public function contacted(): static
    {
        return $this->state(fn (array $attributes) => [
            'contacted' => true,
            'first_contacted_at' => fake()->dateTimeBetween('-30 days'),
            'contact_count' => fake()->numberBetween(1, 5),
        ]);
    }
}
```

---

## 7. Implementation Checklist

### Phase 1: Database ✓
- [ ] Create contacts table migration
- [ ] Run migration: `php artisan migrate`
- [ ] Verify table structure: `php artisan db:table contacts`
- [ ] Check indexes exist

### Phase 2: Models ✓
- [ ] Create Contact model
- [ ] Add relationship to Website model
- [ ] Test model creation in tinker
- [ ] Test scopes and helper methods

### Phase 3: Services ✓
- [ ] Create ContactExtractionService
- [ ] Create EmailValidationService
- [ ] Test extraction with sample HTML
- [ ] Test validation logic

### Phase 4: Jobs ✓
- [ ] Create ExtractContactsJob
- [ ] Test job execution
- [ ] Verify queue processing

### Phase 5: Factories & Testing ✓
- [ ] Create ContactFactory
- [ ] Create ContactTest unit test
- [ ] Create ContactExtractionTest feature test
- [ ] Run tests: `php artisan test`

### Phase 6: Integration ✓
- [ ] Integrate with Website crawling (Step 3)
- [ ] Add contact extraction to crawl workflow
- [ ] Test end-to-end flow

---

## 8. Success Metrics

**Step 2 Completion Criteria:**

**Database:**
- ✓ Contacts table created with all fields
- ✓ Indexes optimized for queries
- ✓ Unique constraint working (website_id + email)

**Models:**
- ✓ Contact model with all relationships
- ✓ Helper methods functioning
- ✓ Scopes working correctly
- ✓ Auto-priority calculation working

**Services:**
- ✓ Can extract emails from HTML
- ✓ Can determine source types
- ✓ Can validate email formats
- ✓ Can check MX records
- ✓ Prevents duplicates

**Testing:**
- ✓ Unit tests passing (80%+ coverage)
- ✓ Feature tests passing
- ✓ Can handle edge cases

**Performance:**
- ✓ Extraction < 2 seconds per page
- ✓ Validation < 1 second per email
- ✓ Batch processing efficient

---

## 9. Usage Examples

### Extract Contacts from Website

```php
use App\Models\Website;
use App\Jobs\ExtractContactsJob;

$website = Website::find(1);

// Dispatch extraction job
ExtractContactsJob::dispatch($website);

// Or extract immediately
$extractionService = new ContactExtractionService();
$validationService = new EmailValidationService();

$contacts = $extractionService->extractFromHtml(
    $html,
    $url,
    $website
);

foreach ($contacts as $contact) {
    $validationService->validate($contact);
}
```

### Query Contacts

```php
use App\Models\Contact;

// Get validated, high-priority contacts not yet contacted
$contacts = Contact::validated()
    ->highPriority()
    ->notContacted()
    ->get();

// Get contacts from contact pages
$contactPageEmails = Contact::fromSource(Contact::SOURCE_CONTACT_PAGE)
    ->where('is_valid', true)
    ->get();
```

---

## 10. Next Steps

After Step 2 completion:
- **Step 3:** Integrate contact extraction into web crawling workflow
- **Step 5:** Use contacts for duplicate prevention
- **Step 7:** Use validated contacts for email sending

---

## Conclusion

This implementation plan provides a complete roadmap for building a robust contact extraction system capable of automatically discovering and validating email addresses from crawled websites.

**Estimated Implementation Time:** 3-4 hours for experienced Laravel developer

**Priority:** HIGH - Required for email outreach functionality

**Risk Level:** LOW - Standard patterns with proven libraries

**Next Document:** `step3-implementation-plan.md` (Web Crawling Implementation)
