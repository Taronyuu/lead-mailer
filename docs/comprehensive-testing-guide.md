# Comprehensive Testing Guide

## Executive Summary

This document outlines a complete testing strategy to verify all components of the automated website research and outreach application are working correctly.

**Testing Objectives:**
- Verify all database migrations and models
- Test all services and business logic
- Confirm queue jobs execute correctly
- Validate email sending and rate limiting
- Ensure Filament admin panel functionality
- Test end-to-end workflows
- Verify performance at scale

**Test Coverage Goal:** 80%+ code coverage

---

## 1. Testing Setup

### 1.1 Install Testing Dependencies

```bash
# PHPUnit already included in Laravel
# Install additional testing tools
composer require --dev pestphp/pest
composer require --dev pestphp/pest-plugin-laravel

# Install Filament testing helpers
composer require --dev filament/filament:"^3.2" --dev
```

### 1.2 Configure Testing Environment

**File:** `.env.testing`

```env
APP_ENV=testing
APP_DEBUG=true

# Use separate test database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=lm_test
DB_USERNAME=lm
DB_PASSWORD=lm

# Disable external API calls in tests
MISTRAL_API_KEY=test_key_disabled

# Queue configuration
QUEUE_CONNECTION=sync
```

### 1.3 Create Test Database

```bash
# Create test database
docker exec -it mysql mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS lm_test;"

# Run migrations on test database
php artisan migrate --env=testing
```

---

## 2. Unit Tests

### 2.1 Model Tests

**File:** `tests/Unit/Models/DomainTest.php`

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Domain;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_domain()
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
        ]);

        $this->assertDatabaseHas('domains', [
            'domain' => 'example.com',
        ]);
    }

    /** @test */
    public function it_automatically_extracts_tld()
    {
        $domain = Domain::factory()->create([
            'domain' => 'test.org',
        ]);

        $this->assertEquals('org', $domain->tld);
    }

    /** @test */
    public function it_has_many_websites()
    {
        $domain = Domain::factory()->create();
        Website::factory()->count(3)->create([
            'domain_id' => $domain->id,
        ]);

        $this->assertCount(3, $domain->websites);
    }

    /** @test */
    public function it_can_mark_as_checked()
    {
        $domain = Domain::factory()->create([
            'check_count' => 0,
        ]);

        $domain->markAsChecked();

        $this->assertEquals(1, $domain->fresh()->check_count);
        $this->assertNotNull($domain->fresh()->last_checked_at);
    }

    /** @test */
    public function it_has_status_constants()
    {
        $this->assertEquals(0, Domain::STATUS_PENDING);
        $this->assertEquals(1, Domain::STATUS_ACTIVE);
        $this->assertEquals(2, Domain::STATUS_PROCESSED);
        $this->assertEquals(3, Domain::STATUS_FAILED);
        $this->assertEquals(4, Domain::STATUS_BLOCKED);
    }

    /** @test */
    public function pending_scope_works()
    {
        Domain::factory()->count(3)->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->count(2)->create(['status' => Domain::STATUS_PROCESSED]);

        $pending = Domain::pending()->get();

        $this->assertCount(3, $pending);
    }
}
```

**Create similar tests for:**
- `WebsiteTest.php`
- `ContactTest.php`
- `EmailTemplateTest.php`
- `SmtpCredentialTest.php`
- `BlacklistEntryTest.php`
- `EmailSentLogTest.php`
- `EmailReviewQueueTest.php`
- `WebsiteRequirementTest.php`

---

### 2.2 Service Tests

**File:** `tests/Unit/Services/ContactExtractionServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Website;
use App\Services\ContactExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ContactExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContactExtractionService();
    }

    /** @test */
    public function it_extracts_emails_from_html()
    {
        $website = Website::factory()->create();
        $html = '<a href="mailto:test@example.com">Contact</a>';

        $contacts = $this->service->extractFromHtml($html, $website->url, $website);

        $this->assertCount(1, $contacts);
        $this->assertEquals('test@example.com', $contacts[0]->email);
    }

    /** @test */
    public function it_determines_source_type()
    {
        $website = Website::factory()->create();
        $html = '<a href="mailto:test@example.com">Contact</a>';

        $contacts = $this->service->extractFromHtml(
            $html,
            'https://example.com/contact',
            $website
        );

        $this->assertEquals('contact_page', $contacts[0]->source_type);
    }

    /** @test */
    public function it_skips_duplicate_emails()
    {
        $website = Website::factory()->create();
        $html = '<a href="mailto:duplicate@example.com">Email</a>';

        // First extraction
        $contacts1 = $this->service->extractFromHtml($html, $website->url, $website);
        $this->assertCount(1, $contacts1);

        // Second extraction - should skip
        $contacts2 = $this->service->extractFromHtml($html, $website->url, $website);
        $this->assertCount(0, $contacts2);
    }
}
```

**Create tests for all services:**
- `EmailValidationServiceTest.php`
- `WebCrawlerServiceTest.php`
- `PlatformDetectionServiceTest.php`
- `RequirementsMatcherServiceTest.php`
- `DuplicatePreventionServiceTest.php`
- `MistralAIServiceTest.php` (with mocked API)
- `EmailTemplateServiceTest.php`
- `EmailSendingServiceTest.php`
- `SmtpRotationServiceTest.php`
- `RateLimiterServiceTest.php`
- `BlacklistServiceTest.php`
- `ReviewQueueServiceTest.php`

---

## 3. Feature Tests

### 3.1 Website Crawling Workflow

**File:** `tests/Feature/WebsiteCrawlingWorkflowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\CrawlWebsiteJob;
use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Jobs\ExtractContactsJob;
use App\Models\Domain;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebsiteCrawlingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function complete_crawl_workflow_executes_correctly()
    {
        Queue::fake();

        // 1. Create domain and website
        $domain = Domain::factory()->create(['domain' => 'example.com']);
        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        // 2. Dispatch crawl job
        CrawlWebsiteJob::dispatch($website);
        Queue::assertPushed(CrawlWebsiteJob::class);

        // 3. Simulate crawl completion
        $website->update([
            'status' => Website::STATUS_COMPLETED,
            'page_count' => 50,
            'word_count' => 10000,
            'detected_platform' => 'wordpress',
        ]);

        // 4. Verify website updated
        $this->assertEquals(Website::STATUS_COMPLETED, $website->fresh()->status);
        $this->assertEquals('wordpress', $website->fresh()->detected_platform);

        // 5. Extract contacts should be dispatched
        ExtractContactsJob::dispatch($website);
        Queue::assertPushed(ExtractContactsJob::class);

        // 6. Evaluate requirements
        $requirement = WebsiteRequirement::factory()->create([
            'criteria' => [
                'min_pages' => 10,
                'platforms' => ['wordpress'],
            ],
        ]);

        EvaluateWebsiteRequirementsJob::dispatch($website);
        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class);
    }

    /** @test */
    public function failed_crawl_updates_status()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_CRAWLING,
        ]);

        $website->failCrawl('Connection timeout');

        $this->assertEquals(Website::STATUS_FAILED, $website->fresh()->status);
        $this->assertEquals('Connection timeout', $website->fresh()->crawl_error);
    }
}
```

---

### 3.2 Email Sending Workflow

**File:** `tests/Feature/EmailSendingWorkflowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendOutreachEmailJob;
use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use App\Services\EmailSendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailSendingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function complete_email_workflow_works()
    {
        Mail::fake();
        Queue::fake();

        // 1. Create qualified website
        $website = Website::factory()->create([
            'status' => Website::STATUS_COMPLETED,
            'meets_requirements' => true,
        ]);

        // 2. Create validated contact
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'test@example.com',
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        // 3. Create email template
        $template = EmailTemplate::factory()->create([
            'is_active' => true,
        ]);

        // 4. Create SMTP credential
        $smtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 10,
            'emails_sent_today' => 0,
        ]);

        // 5. Dispatch send job
        SendOutreachEmailJob::dispatch($contact, $template);
        Queue::assertPushed(SendOutreachEmailJob::class);

        // 6. Manually process job (since queue is fake)
        $emailService = app(EmailSendingService::class);

        // Mock time to be within sending window
        $this->travel(8)->hours();

        $result = $emailService->send($contact, $template, $smtp);

        // 7. Verify email was logged
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('email_sent_log', [
            'contact_id' => $contact->id,
            'status' => EmailSentLog::STATUS_SENT,
        ]);

        // 8. Verify contact marked as contacted
        $this->assertTrue($contact->fresh()->contacted);
        $this->assertEquals(1, $contact->fresh()->contact_count);
    }

    /** @test */
    public function duplicate_prevention_works()
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        // Create existing email log
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'sent_at' => now()->subDays(5),
        ]);

        $emailService = app(EmailSendingService::class);
        $result = $emailService->send($contact, $template);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate', $result['error']);
    }

    /** @test */
    public function rate_limiting_enforces_time_window()
    {
        $this->travel(20)->hours(); // 8 PM

        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $emailService = app(EmailSendingService::class);
        $result = $emailService->send($contact, $template, $smtp);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Outside allowed sending hours', $result['error']);
    }
}
```

---

### 3.3 Requirements Matching Test

**File:** `tests/Feature/RequirementsMatchingTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use App\Services\RequirementsMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirementsMatchingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function website_matches_all_criteria()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'detected_platform' => 'wordpress',
            'word_count' => 10000,
            'status' => Website::STATUS_COMPLETED,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'min_pages' => 10,
                'max_pages' => 100,
                'platforms' => ['wordpress'],
                'min_word_count' => 5000,
            ],
        ]);

        $matcher = app(RequirementsMatcherService::class);
        $results = $matcher->evaluateWebsite($website);

        $this->assertTrue($results[0]['matches']);
        $this->assertEquals(100, $results[0]['score']);
        $this->assertTrue($website->fresh()->meets_requirements);
    }

    /** @test */
    public function website_fails_partial_criteria()
    {
        $website = Website::factory()->create([
            'page_count' => 5, // Too few
            'detected_platform' => 'wordpress',
            'status' => Website::STATUS_COMPLETED,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'min_pages' => 10,
                'platforms' => ['wordpress'],
            ],
        ]);

        $matcher = app(RequirementsMatcherService::class);
        $results = $matcher->evaluateWebsite($website);

        $this->assertFalse($results[0]['matches']);
        $this->assertEquals(50, $results[0]['score']); // 1 of 2 criteria
    }
}
```

---

## 4. Integration Tests

### 4.1 End-to-End Workflow Test

**File:** `tests/Integration/CompleteWorkflowTest.php`

```php
<?php

namespace Tests\Integration;

use App\Jobs\CrawlWebsiteJob;
use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Jobs\ExtractContactsJob;
use App\Jobs\SendOutreachEmailJob;
use App\Models\Domain;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use App\Services\EmailSendingService;
use App\Services\WebCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompleteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function complete_system_workflow_from_domain_to_email()
    {
        // This test verifies the entire pipeline works

        // 1. Setup
        $domain = Domain::factory()->create();
        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'status' => Website::STATUS_PENDING,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => ['min_pages' => 5],
        ]);

        $template = EmailTemplate::factory()->create(['is_active' => true]);
        $smtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 10,
        ]);

        // 2. Crawl website
        $website->update([
            'status' => Website::STATUS_COMPLETED,
            'page_count' => 20,
            'content_snapshot' => 'Sample content',
        ]);

        // 3. Extract contacts (simulated)
        $contact = \App\Models\Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // 4. Evaluate requirements
        $matcher = app(\App\Services\RequirementsMatcherService::class);
        $matcher->evaluateWebsite($website);

        $this->assertTrue($website->fresh()->meets_requirements);

        // 5. Send email
        $this->travel(10)->hours(); // 10 AM

        $emailService = app(EmailSendingService::class);
        $result = $emailService->send($contact, $template, $smtp);

        // 6. Verify complete workflow
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('email_sent_log', [
            'contact_id' => $contact->id,
        ]);
    }
}
```

---

## 5. Filament Tests

### 5.1 Resource Tests

**File:** `tests/Feature/Filament/DomainResourceTest.php`

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DomainResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    /** @test */
    public function can_render_domain_list_page()
    {
        $this->get(DomainResource::getUrl('index'))
            ->assertSuccessful();
    }

    /** @test */
    public function can_list_domains()
    {
        $domains = Domain::factory()->count(10)->create();

        Livewire::test(DomainResource\Pages\ListDomains::class)
            ->assertCanSeeTableRecords($domains);
    }

    /** @test */
    public function can_create_domain()
    {
        $newData = [
            'domain' => 'newdomain.com',
            'status' => Domain::STATUS_PENDING,
        ];

        Livewire::test(DomainResource\Pages\CreateDomain::class)
            ->fillForm($newData)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('domains', $newData);
    }

    /** @test */
    public function can_edit_domain()
    {
        $domain = Domain::factory()->create();

        $newData = [
            'domain' => 'updated.com',
        ];

        Livewire::test(DomainResource\Pages\EditDomain::class, [
            'record' => $domain->getKey(),
        ])
            ->fillForm($newData)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('domains', $newData);
    }

    /** @test */
    public function can_delete_domain()
    {
        $domain = Domain::factory()->create();

        Livewire::test(DomainResource\Pages\EditDomain::class, [
            'record' => $domain->getKey(),
        ])
            ->callAction('delete');

        $this->assertSoftDeleted('domains', [
            'id' => $domain->id,
        ]);
    }
}
```

**Create similar tests for:**
- `WebsiteResourceTest.php`
- `ContactResourceTest.php`
- `SmtpCredentialResourceTest.php`
- `EmailTemplateResourceTest.php`

---

## 6. Performance Tests

### 6.1 Database Performance Test

**File:** `tests/Performance/DatabasePerformanceTest.php`

```php
<?php

namespace Tests\Performance;

use App\Models\Domain;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabasePerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function can_query_large_dataset_efficiently()
    {
        // Create 10,000 domains
        Domain::factory()->count(10000)->create();

        DB::enableQueryLog();

        $start = microtime(true);
        $pending = Domain::pending()->limit(100)->get();
        $duration = microtime(true) - $start;

        $queries = DB::getQueryLog();

        // Assertions
        $this->assertCount(100, $pending);
        $this->assertLessThan(0.5, $duration, 'Query took too long');
        $this->assertCount(1, $queries, 'Should only execute 1 query');
    }

    /** @test */
    public function indexes_are_being_used()
    {
        Domain::factory()->count(100)->create();

        $indexes = DB::select('SHOW INDEX FROM domains');
        $indexNames = collect($indexes)->pluck('Key_name')->unique();

        $this->assertTrue($indexNames->contains('domains_status_index'));
        $this->assertTrue($indexNames->contains('domains_tld_index'));
    }

    /** @test */
    public function can_efficiently_chunk_through_large_dataset()
    {
        Domain::factory()->count(1000)->create();

        $processedCount = 0;
        $start = microtime(true);

        Domain::query()->chunk(100, function ($domains) use (&$processedCount) {
            $processedCount += $domains->count();
        });

        $duration = microtime(true) - $start;

        $this->assertEquals(1000, $processedCount);
        $this->assertLessThan(2, $duration, 'Chunking took too long');
    }
}
```

---

## 7. Test Execution

### 7.1 Run All Tests

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage

# Run specific test suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run specific test file
php artisan test tests/Unit/Models/DomainTest.php

# Run with parallel execution
php artisan test --parallel

# Run with detailed output
php artisan test --verbose
```

### 7.2 Continuous Integration

**File:** `.github/workflows/tests.yml`

```yaml
name: Tests

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: lm_test
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, mysql, pdo_mysql

      - name: Install Dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Copy .env
        run: cp .env.example .env.testing

      - name: Generate key
        run: php artisan key:generate --env=testing

      - name: Run Migrations
        run: php artisan migrate --env=testing

      - name: Execute tests
        run: php artisan test --coverage --min=80
```

---

## 8. Testing Checklist

### Phase 1: Unit Tests ✓
- [ ] All model tests (8 models)
- [ ] All service tests (12 services)
- [ ] All helper/utility tests
- [ ] Achieve 80%+ coverage on models
- [ ] Achieve 80%+ coverage on services

### Phase 2: Feature Tests ✓
- [ ] Website crawling workflow
- [ ] Contact extraction workflow
- [ ] Requirements matching workflow
- [ ] Email sending workflow
- [ ] Duplicate prevention
- [ ] Rate limiting
- [ ] Blacklist functionality
- [ ] Review queue workflow

### Phase 3: Integration Tests ✓
- [ ] Complete end-to-end workflow
- [ ] Multi-step processes
- [ ] Error handling and recovery
- [ ] Queue job processing

### Phase 4: Filament Tests ✓
- [ ] All resource CRUD operations (8 resources)
- [ ] Custom actions (crawl, evaluate, etc.)
- [ ] Bulk operations
- [ ] Filters and searches
- [ ] Dashboard widgets

### Phase 5: Performance Tests ✓
- [ ] Database query performance
- [ ] Index utilization
- [ ] Large dataset handling
- [ ] Concurrent operations
- [ ] Memory usage

### Phase 6: Manual Testing ✓
- [ ] Full UI walkthrough
- [ ] Mobile responsiveness
- [ ] Dark mode functionality
- [ ] Error messages display correctly
- [ ] Notifications work
- [ ] Forms validate properly

---

## 9. Test Coverage Goals

### Minimum Coverage Requirements:
- **Models:** 85%+
- **Services:** 80%+
- **Controllers/Resources:** 75%+
- **Jobs:** 80%+
- **Overall:** 80%+

### Generate Coverage Report:

```bash
# Generate HTML coverage report
php artisan test --coverage-html coverage-report

# View report
open coverage-report/index.html
```

---

## 10. Common Test Scenarios

### Critical Test Cases:

1. **Domain Processing**
   - Create domain
   - Extract TLD
   - Mark as checked
   - Soft delete

2. **Website Crawling**
   - Start crawl
   - Handle timeout
   - Parse content
   - Detect platform
   - Store snapshot
   - Handle failure

3. **Contact Extraction**
   - Find emails in HTML
   - Validate format
   - Check MX records
   - Prevent duplicates
   - Calculate priority

4. **Requirements Matching**
   - Evaluate all criteria types
   - Calculate scores
   - Store match details
   - Update website status

5. **Email Sending**
   - Check time window
   - Prevent duplicates
   - Rotate SMTP
   - Respect daily limits
   - Log sent emails
   - Handle failures

6. **Review Queue**
   - Add to queue
   - Approve email
   - Reject email
   - Bulk operations

7. **Blacklist**
   - Add entries
   - Check before crawl
   - Check before send
   - Import/export

---

## 11. Debugging Failed Tests

### Common Issues:

**Database not migrated:**
```bash
php artisan migrate:fresh --env=testing
```

**Queue jobs not executing:**
```php
// Use sync queue in tests
Queue::fake(); // Or
config(['queue.default' => 'sync']);
```

**External API calls:**
```php
// Mock HTTP calls
Http::fake([
    'api.mistral.ai/*' => Http::response(['choices' => [...]], 200),
]);
```

**Time-dependent tests:**
```php
// Use Carbon's testing helpers
$this->travel(10)->hours();
Carbon::setTestNow('2024-01-15 10:00:00');
```

---

## Conclusion

This comprehensive testing guide ensures all components of the system work correctly. Running these tests regularly will:

✅ **Catch bugs early**
✅ **Verify new features don't break existing functionality**
✅ **Provide confidence in deployments**
✅ **Document expected behavior**
✅ **Ensure performance at scale**

**Next Steps:**
1. Write unit tests for all models
2. Create feature tests for each workflow
3. Set up CI/CD pipeline
4. Achieve 80%+ code coverage
5. Run performance tests with large datasets
6. Manual UI testing in staging environment

**Estimated Testing Time:** 15-20 hours
**Coverage Goal:** 80%+ overall, 85%+ on critical paths

---

## Quick Test Commands Reference

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage --min=80

# Run specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run parallel (faster)
php artisan test --parallel

# Run specific test
php artisan test --filter=DomainTest

# Watch mode (re-run on changes)
php artisan test --watch

# Generate coverage report
php artisan test --coverage-html coverage-report
```

**Remember:** Tests are your safety net. Write them first, run them often! 🛡️
