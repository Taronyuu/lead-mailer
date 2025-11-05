# Step 4 Implementation Plan: Requirements Matching Engine

## Executive Summary

This document outlines the implementation plan for Step 4: building an intelligent requirements matching engine that evaluates crawled websites against flexible, JSON-based criteria to identify qualified leads.

**Key Objectives:**
- Evaluate websites against configurable criteria
- Support multiple criteria types (page count, platform, keywords, URLs, word count)
- Calculate matching scores
- Store detailed match results
- Support multiple requirement sets per website
- Enable automated qualification flagging

**Dependencies:**
- Step 1 completed (WebsiteRequirement model exists)
- Step 3 completed (Websites have been crawled with content)

---

## 1. Services

### 1.1 Requirements Matcher Service

**File:** `app/Services/RequirementsMatcherService.php`

```php
<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Support\Facades\Log;

class RequirementsMatcherService
{
    protected ContentExtractionService $contentExtractor;

    public function __construct(ContentExtractionService $contentExtractor)
    {
        $this->contentExtractor = $contentExtractor;
    }

    /**
     * Evaluate a website against all active requirements
     */
    public function evaluateWebsite(Website $website): array
    {
        $requirements = WebsiteRequirement::active()->get();
        $results = [];

        foreach ($requirements as $requirement) {
            $evaluation = $this->evaluateAgainstRequirement($website, $requirement);

            // Store in pivot table
            $website->requirements()->syncWithoutDetaching([
                $requirement->id => [
                    'matches' => $evaluation['matches'],
                    'match_details' => json_encode($evaluation['details']),
                ]
            ]);

            $results[] = $evaluation;
        }

        // Update website's overall qualification status
        $anyMatches = collect($results)->contains('matches', true);
        $website->update(['meets_requirements' => $anyMatches]);

        return $results;
    }

    /**
     * Evaluate website against a single requirement
     */
    public function evaluateAgainstRequirement(Website $website, WebsiteRequirement $requirement): array
    {
        $criteria = $requirement->criteria ?? [];
        $evaluator = new CriteriaEvaluator($website);

        $results = [];
        $allMatch = true;

        // Page count criteria
        if (isset($criteria['min_pages'])) {
            $match = $evaluator->evaluateMinPages($criteria['min_pages']);
            $results['min_pages'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        if (isset($criteria['max_pages'])) {
            $match = $evaluator->evaluateMaxPages($criteria['max_pages']);
            $results['max_pages'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        // Platform criteria
        if (isset($criteria['platforms'])) {
            $match = $evaluator->evaluatePlatform($criteria['platforms']);
            $results['platforms'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        // Word count criteria
        if (isset($criteria['min_word_count'])) {
            $match = $evaluator->evaluateMinWordCount($criteria['min_word_count']);
            $results['min_word_count'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        if (isset($criteria['max_word_count'])) {
            $match = $evaluator->evaluateMaxWordCount($criteria['max_word_count']);
            $results['max_word_count'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        // Required keywords
        if (isset($criteria['required_keywords'])) {
            $match = $evaluator->evaluateRequiredKeywords($criteria['required_keywords']);
            $results['required_keywords'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        // Excluded keywords
        if (isset($criteria['excluded_keywords'])) {
            $match = $evaluator->evaluateExcludedKeywords($criteria['excluded_keywords']);
            $results['excluded_keywords'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        // Required URLs
        if (isset($criteria['required_urls'])) {
            $match = $evaluator->evaluateRequiredUrls($criteria['required_urls']);
            $results['required_urls'] = $match;
            $allMatch = $allMatch && $match['matched'];
        }

        // Calculate overall score
        $score = $this->calculateScore($results);

        return [
            'requirement_id' => $requirement->id,
            'requirement_name' => $requirement->name,
            'matches' => $allMatch,
            'score' => $score,
            'details' => $results,
        ];
    }

    /**
     * Calculate overall matching score (0-100)
     */
    protected function calculateScore(array $results): float
    {
        if (empty($results)) {
            return 0;
        }

        $totalCriteria = count($results);
        $matchedCriteria = 0;

        foreach ($results as $result) {
            if ($result['matched'] ?? false) {
                $matchedCriteria++;
            }
        }

        return round(($matchedCriteria / $totalCriteria) * 100, 2);
    }
}
```

---

### 1.2 Criteria Evaluator

**File:** `app/Services/CriteriaEvaluator.php`

```php
<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Str;

class CriteriaEvaluator
{
    protected Website $website;

    public function __construct(Website $website)
    {
        $this->website = $website;
    }

    /**
     * Evaluate minimum pages
     */
    public function evaluateMinPages(int $minPages): array
    {
        $actual = $this->website->page_count ?? 0;
        $matched = $actual >= $minPages;

        return [
            'criterion' => 'min_pages',
            'required' => $minPages,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Website has {$actual} pages (required: {$minPages}+)"
                : "Website has only {$actual} pages (required: {$minPages}+)",
        ];
    }

    /**
     * Evaluate maximum pages
     */
    public function evaluateMaxPages(int $maxPages): array
    {
        $actual = $this->website->page_count ?? 0;
        $matched = $actual <= $maxPages;

        return [
            'criterion' => 'max_pages',
            'required' => $maxPages,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Website has {$actual} pages (limit: {$maxPages})"
                : "Website has {$actual} pages (limit: {$maxPages})",
        ];
    }

    /**
     * Evaluate platform
     */
    public function evaluatePlatform(array $allowedPlatforms): array
    {
        $actual = $this->website->detected_platform;
        $matched = in_array($actual, $allowedPlatforms);

        return [
            'criterion' => 'platform',
            'required' => $allowedPlatforms,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Platform '{$actual}' is allowed"
                : "Platform '{$actual}' not in allowed list: " . implode(', ', $allowedPlatforms),
        ];
    }

    /**
     * Evaluate minimum word count
     */
    public function evaluateMinWordCount(int $minWordCount): array
    {
        $actual = $this->website->word_count ?? 0;
        $matched = $actual >= $minWordCount;

        return [
            'criterion' => 'min_word_count',
            'required' => $minWordCount,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Content has {$actual} words (required: {$minWordCount}+)"
                : "Content has only {$actual} words (required: {$minWordCount}+)",
        ];
    }

    /**
     * Evaluate maximum word count
     */
    public function evaluateMaxWordCount(int $maxWordCount): array
    {
        $actual = $this->website->word_count ?? 0;
        $matched = $actual <= $maxWordCount;

        return [
            'criterion' => 'max_word_count',
            'required' => $maxWordCount,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Content has {$actual} words (limit: {$maxWordCount})"
                : "Content has {$actual} words (exceeds limit: {$maxWordCount})",
        ];
    }

    /**
     * Evaluate required keywords
     */
    public function evaluateRequiredKeywords(array $keywords): array
    {
        $content = strtolower($this->website->content_snapshot ?? '');
        $found = [];
        $missing = [];

        foreach ($keywords as $keyword) {
            if (Str::contains($content, strtolower($keyword))) {
                $found[] = $keyword;
            } else {
                $missing[] = $keyword;
            }
        }

        $matched = empty($missing);

        return [
            'criterion' => 'required_keywords',
            'required' => $keywords,
            'found' => $found,
            'missing' => $missing,
            'matched' => $matched,
            'message' => $matched
                ? 'All required keywords found: ' . implode(', ', $found)
                : 'Missing keywords: ' . implode(', ', $missing),
        ];
    }

    /**
     * Evaluate excluded keywords
     */
    public function evaluateExcludedKeywords(array $keywords): array
    {
        $content = strtolower($this->website->content_snapshot ?? '');
        $foundExcluded = [];

        foreach ($keywords as $keyword) {
            if (Str::contains($content, strtolower($keyword))) {
                $foundExcluded[] = $keyword;
            }
        }

        $matched = empty($foundExcluded);

        return [
            'criterion' => 'excluded_keywords',
            'excluded' => $keywords,
            'found' => $foundExcluded,
            'matched' => $matched,
            'message' => $matched
                ? 'No excluded keywords found'
                : 'Found excluded keywords: ' . implode(', ', $foundExcluded),
        ];
    }

    /**
     * Evaluate required URLs
     */
    public function evaluateRequiredUrls(array $urlPatterns): array
    {
        $content = strtolower($this->website->content_snapshot ?? '');
        $found = [];
        $missing = [];

        foreach ($urlPatterns as $pattern) {
            if (Str::contains($content, strtolower($pattern))) {
                $found[] = $pattern;
            } else {
                $missing[] = $pattern;
            }
        }

        $matched = empty($missing);

        return [
            'criterion' => 'required_urls',
            'required' => $urlPatterns,
            'found' => $found,
            'missing' => $missing,
            'matched' => $matched,
            'message' => $matched
                ? 'All required URLs found: ' . implode(', ', $found)
                : 'Missing URLs: ' . implode(', ', $missing),
        ];
    }
}
```

---

## 2. Queue Jobs

### 2.1 Evaluate Website Requirements Job

**File:** `app/Jobs/EvaluateWebsiteRequirementsJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\RequirementsMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateWebsiteRequirementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(public Website $website) {}

    /**
     * Execute the job.
     */
    public function handle(RequirementsMatcherService $matcher): void
    {
        Log::info('Starting requirements evaluation', [
            'website_id' => $this->website->id,
            'url' => $this->website->url,
        ]);

        try {
            $results = $matcher->evaluateWebsite($this->website);

            $matchCount = collect($results)->filter(fn($r) => $r['matches'])->count();

            Log::info('Requirements evaluation completed', [
                'website_id' => $this->website->id,
                'total_requirements' => count($results),
                'matches' => $matchCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Requirements evaluation failed', [
                'website_id' => $this->website->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Requirements evaluation job permanently failed', [
            'website_id' => $this->website->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

## 3. Artisan Commands

### 3.1 Evaluate All Websites Command

**File:** `app/Console/Commands/EvaluateAllWebsitesCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Models\Website;
use Illuminate\Console\Command;

class EvaluateAllWebsitesCommand extends Command
{
    protected $signature = 'websites:evaluate
                            {--limit=100 : Number of websites to evaluate}
                            {--only-completed : Only evaluate completed crawls}';

    protected $description = 'Evaluate websites against all active requirements';

    public function handle(): int
    {
        $this->info('Starting website evaluation...');

        $query = Website::query();

        if ($this->option('only-completed')) {
            $query->where('status', Website::STATUS_COMPLETED);
        }

        $websites = $query->limit($this->option('limit'))->get();

        $this->info("Evaluating {$websites->count()} websites...");

        $bar = $this->output->createProgressBar($websites->count());

        foreach ($websites as $website) {
            EvaluateWebsiteRequirementsJob::dispatch($website);
            $bar->advance();
        }

        $bar->finish();

        $this->newLine();
        $this->info('Evaluation jobs dispatched!');

        return self::SUCCESS;
    }
}
```

---

## 4. Testing Strategy

### 4.1 Unit Tests

**File:** `tests/Unit/Services/RequirementsMatcherServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Website;
use App\Models\WebsiteRequirement;
use App\Services\ContentExtractionService;
use App\Services\RequirementsMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirementsMatcherServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RequirementsMatcherService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $contentExtractor = new ContentExtractionService();
        $this->service = new RequirementsMatcherService($contentExtractor);
    }

    /** @test */
    public function it_evaluates_page_count_criteria()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'status' => Website::STATUS_COMPLETED,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'min_pages' => 10,
                'max_pages' => 100,
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
        $this->assertEquals(100, $result['score']);
    }

    /** @test */
    public function it_evaluates_platform_criteria()
    {
        $website = Website::factory()->create([
            'detected_platform' => 'wordpress',
            'status' => Website::STATUS_COMPLETED,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'platforms' => ['wordpress', 'drupal'],
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertTrue($result['matches']);
    }

    /** @test */
    public function it_fails_when_criteria_not_met()
    {
        $website = Website::factory()->create([
            'page_count' => 5,
            'status' => Website::STATUS_COMPLETED,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'min_pages' => 10,
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertFalse($result['matches']);
    }

    /** @test */
    public function it_calculates_partial_match_score()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'detected_platform' => 'custom',
            'status' => Website::STATUS_COMPLETED,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'min_pages' => 10, // PASS
                'platforms' => ['wordpress'], // FAIL
            ],
        ]);

        $result = $this->service->evaluateAgainstRequirement($website, $requirement);

        $this->assertFalse($result['matches']); // Not all match
        $this->assertEquals(50, $result['score']); // But 50% score
    }
}
```

---

### 4.2 Feature Tests

**File:** `tests/Feature/RequirementsEvaluationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RequirementsEvaluationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_dispatches_evaluation_job()
    {
        Queue::fake();

        $website = Website::factory()->create();

        EvaluateWebsiteRequirementsJob::dispatch($website);

        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class);
    }

    /** @test */
    public function it_stores_evaluation_results_in_pivot_table()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
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

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle(app(RequirementsMatcherService::class));

        $this->assertDatabaseHas('website_website_requirement', [
            'website_id' => $website->id,
            'website_requirement_id' => $requirement->id,
            'matches' => true,
        ]);
    }

    /** @test */
    public function it_updates_website_meets_requirements_flag()
    {
        $website = Website::factory()->create([
            'page_count' => 50,
            'detected_platform' => 'wordpress',
            'status' => Website::STATUS_COMPLETED,
            'meets_requirements' => null,
        ]);

        WebsiteRequirement::factory()->create([
            'is_active' => true,
            'criteria' => [
                'min_pages' => 10,
                'platforms' => ['wordpress'],
            ],
        ]);

        $job = new EvaluateWebsiteRequirementsJob($website);
        $job->handle(app(RequirementsMatcherService::class));

        $this->assertTrue($website->fresh()->meets_requirements);
    }
}
```

---

## 5. Example Criteria Configurations

### Basic WordPress Filter
```json
{
  "min_pages": 10,
  "max_pages": 500,
  "platforms": ["wordpress", "woocommerce"]
}
```

### E-commerce Sites
```json
{
  "min_pages": 20,
  "platforms": ["shopify", "woocommerce"],
  "required_keywords": ["shop", "store", "product"],
  "required_urls": ["/cart", "/checkout"],
  "min_word_count": 1000
}
```

### Content Sites
```json
{
  "min_pages": 50,
  "required_keywords": ["blog", "article", "news"],
  "excluded_keywords": ["casino", "gambling", "adult"],
  "min_word_count": 5000
}
```

### Professional Services
```json
{
  "min_pages": 5,
  "max_pages": 100,
  "required_urls": ["/contact", "/about"],
  "excluded_keywords": ["adult", "casino", "porn"],
  "platforms": ["wordpress", "custom"]
}
```

---

## 6. Implementation Checklist

### Phase 1: Services ✓
- [ ] Create RequirementsMatcherService
- [ ] Create CriteriaEvaluator
- [ ] Test evaluation logic

### Phase 2: Jobs ✓
- [ ] Create EvaluateWebsiteRequirementsJob
- [ ] Test job execution

### Phase 3: Commands ✓
- [ ] Create EvaluateAllWebsitesCommand
- [ ] Test bulk evaluation

### Phase 4: Testing ✓
- [ ] Create unit tests
- [ ] Create feature tests
- [ ] Run tests: `php artisan test`

### Phase 5: Integration ✓
- [ ] Integrate with crawl workflow
- [ ] Auto-evaluate after crawl completion
- [ ] Test end-to-end

---

## 7. Usage Examples

### Evaluate Single Website
```php
use App\Models\Website;
use App\Jobs\EvaluateWebsiteRequirementsJob;

$website = Website::find(1);
EvaluateWebsiteRequirementsJob::dispatch($website);
```

### Evaluate All Completed Websites
```bash
php artisan websites:evaluate --only-completed --limit=1000
```

### Query Qualified Leads
```php
use App\Models\Website;

// Get all qualified leads
$leads = Website::qualifiedLeads()->get();

// Get websites matching specific requirement
$requirement = WebsiteRequirement::find(1);
$matches = $requirement->matchingWebsites()->get();
```

---

## 8. Success Metrics

**Step 4 Completion Criteria:**

**Services:**
- ✓ All criteria types evaluating correctly
- ✓ Scoring system accurate
- ✓ Results stored in database

**Jobs:**
- ✓ Queue processing working
- ✓ Bulk evaluation efficient

**Testing:**
- ✓ Unit tests passing (90%+ coverage)
- ✓ Feature tests passing

**Performance:**
- ✓ Evaluation < 1 second per website
- ✓ Bulk processing efficient

---

## Conclusion

This implementation provides a flexible, powerful requirements matching engine capable of evaluating websites against complex criteria to identify qualified leads.

**Estimated Implementation Time:** 4-5 hours

**Priority:** HIGH - Core qualification logic

**Risk Level:** LOW - Standard business logic

**Next Document:** `step5-implementation-plan.md` (Duplicate Prevention & Tracking)
