# Step 1 Implementation Plan: Domains & Websites Foundation

## Executive Summary

This document outlines the complete implementation plan for Step 1 of the automated website research and outreach application. Step 1 focuses on creating the foundational database infrastructure to manage millions of domains and track website crawling status.

**Key Objectives:**
- Migrate from SQLite to MySQL for production-scale performance
- Create optimized database schema for millions of domains
- Build Eloquent models with proper relationships
- Implement testing strategy for data integrity
- Install Roach PHP for web crawling capabilities

---

## 1. Environment Setup

### 1.1 Database Migration to MySQL

**Current State:**
- Using SQLite (database.sqlite)
- MySQL 8.0 configured in docker-compose but not active

**Action Required:**
Update `.env` file to switch database connection:

```env
# Change from:
DB_CONNECTION=sqlite

# To:
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=lm
DB_USERNAME=lm
DB_PASSWORD=lm
```

**Verification Steps:**
```bash
# Test MySQL connection
php artisan db:show

# Run existing migrations on MySQL
php artisan migrate:fresh

# Verify connection
php artisan tinker
>>> DB::connection()->getPdo();
```

### 1.2 Install Dependencies

**Add Roach PHP for web crawling:**

```bash
composer require roach-php/core
```

**Why Roach PHP:**
- Most powerful crawling framework for PHP
- Built-in middleware system
- Concurrent request handling
- Extensible architecture
- Perfect for complex scraping needs at scale

---

## 2. Database Schema Design

### 2.1 Domains Table

**Purpose:** Store millions of domains with efficient indexing for processing queues.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_domains_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 255)->unique()->comment('Full domain name (e.g., example.com)');
            $table->string('tld', 10)->index()->comment('Top-level domain (e.g., com, org, net)');
            $table->tinyInteger('status')->default(0)->index()->comment('0=pending, 1=active, 2=processed, 3=failed, 4=blocked');
            $table->timestamp('last_checked_at')->nullable()->index()->comment('Last time domain was processed');
            $table->unsignedInteger('check_count')->default(0)->comment('Number of times checked');
            $table->text('notes')->nullable()->comment('Admin notes or processing logs');
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for common query patterns
            $table->index(['status', 'last_checked_at'], 'domains_status_checked_idx');
            $table->index(['tld', 'status'], 'domains_tld_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
```

**Raw SQL Equivalent:**

```sql
CREATE TABLE `domains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `domain` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Full domain name (e.g., example.com)',
    `tld` VARCHAR(10) NOT NULL COMMENT 'Top-level domain (e.g., com, org, net)',
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=pending, 1=active, 2=processed, 3=failed, 4=blocked',
    `last_checked_at` TIMESTAMP NULL COMMENT 'Last time domain was processed',
    `check_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times checked',
    `notes` TEXT NULL COMMENT 'Admin notes or processing logs',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    INDEX `domains_domain_index` (`domain`),
    INDEX `domains_tld_index` (`tld`),
    INDEX `domains_status_index` (`status`),
    INDEX `domains_last_checked_at_index` (`last_checked_at`),
    INDEX `domains_status_checked_idx` (`status`, `last_checked_at`),
    INDEX `domains_tld_status_idx` (`tld`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Index Strategy Explanation:**
- `domain` (UNIQUE): Fast lookups and duplicate prevention
- `tld`: Filter by domain type (.com, .org, etc.)
- `status`: Essential for queue processing
- `last_checked_at`: Time-based processing
- `(status, last_checked_at)` composite: Optimal for "get next pending domain" queries
- `(tld, status)` composite: Filter by TLD type and status efficiently

**Estimated Storage:**
- Per row: ~350 bytes (with indexes)
- 1 million domains: ~350 MB
- 10 million domains: ~3.5 GB
- **Recommendation:** Implement table partitioning beyond 50M domains

---

### 2.2 Websites Table

**Purpose:** Track individual websites linked to domains, including crawl status and extracted data.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_websites_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->onDelete('cascade');
            $table->string('url', 500)->index()->comment('Full URL including protocol');
            $table->tinyInteger('status')->default(0)->index()->comment('0=pending, 1=crawling, 2=completed, 3=failed, 4=per_review');

            // Website metadata
            $table->string('title', 500)->nullable()->comment('Page title');
            $table->text('description')->nullable()->comment('Meta description');
            $table->string('detected_platform', 50)->nullable()->index()->comment('WordPress, Shopify, etc.');
            $table->unsignedInteger('page_count')->default(0)->comment('Number of pages crawled');
            $table->unsignedInteger('word_count')->default(0)->comment('Total word count from pages');

            // Crawl control
            $table->timestamp('crawled_at')->nullable()->index()->comment('When crawl completed');
            $table->timestamp('crawl_started_at')->nullable()->comment('When crawl began');
            $table->unsignedTinyInteger('crawl_attempts')->default(0)->comment('Number of crawl attempts');
            $table->text('crawl_error')->nullable()->comment('Last error message');

            // Requirements matching
            $table->boolean('meets_requirements')->nullable()->index()->comment('Does website meet lead criteria');
            $table->json('requirements_result')->nullable()->comment('Detailed match results');

            // Content storage (for AI processing)
            $table->longText('content_snapshot')->nullable()->comment('First 10 pages content for Mistral AI');

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for efficient queries
            $table->index(['status', 'meets_requirements'], 'websites_status_meets_idx');
            $table->index(['domain_id', 'status'], 'websites_domain_status_idx');
            $table->index(['detected_platform', 'meets_requirements'], 'websites_platform_meets_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
```

**Raw SQL Equivalent:**

```sql
CREATE TABLE `websites` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `url` VARCHAR(500) NOT NULL COMMENT 'Full URL including protocol',
    `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=pending, 1=crawling, 2=completed, 3=failed, 4=per_review',
    `title` VARCHAR(500) NULL COMMENT 'Page title',
    `description` TEXT NULL COMMENT 'Meta description',
    `detected_platform` VARCHAR(50) NULL COMMENT 'WordPress, Shopify, etc.',
    `page_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of pages crawled',
    `word_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total word count from pages',
    `crawled_at` TIMESTAMP NULL COMMENT 'When crawl completed',
    `crawl_started_at` TIMESTAMP NULL COMMENT 'When crawl began',
    `crawl_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of crawl attempts',
    `crawl_error` TEXT NULL COMMENT 'Last error message',
    `meets_requirements` BOOLEAN NULL COMMENT 'Does website meet lead criteria',
    `requirements_result` JSON NULL COMMENT 'Detailed match results',
    `content_snapshot` LONGTEXT NULL COMMENT 'First 10 pages content for Mistral AI',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE,
    INDEX `websites_url_index` (`url`),
    INDEX `websites_status_index` (`status`),
    INDEX `websites_detected_platform_index` (`detected_platform`),
    INDEX `websites_crawled_at_index` (`crawled_at`),
    INDEX `websites_meets_requirements_index` (`meets_requirements`),
    INDEX `websites_status_meets_idx` (`status`, `meets_requirements`),
    INDEX `websites_domain_status_idx` (`domain_id`, `status`),
    INDEX `websites_platform_meets_idx` (`detected_platform`, `meets_requirements`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Status Flow:**
```
0 (pending) → 1 (crawling) → 2 (completed)
                          ↓
                       3 (failed)
                          ↓
                    4 (per_review)
```

---

### 2.3 Website Requirements Table

**Purpose:** Store flexible lead qualification criteria using JSON.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_website_requirements_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Requirement set name');
            $table->text('description')->nullable()->comment('Human-readable description');
            $table->boolean('is_active')->default(true)->index()->comment('Is this requirement active');

            // Flexible criteria using JSON
            $table->json('criteria')->nullable()->comment('Matching criteria configuration');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_requirements');
    }
};
```

**Example Criteria JSON Structure:**

```json
{
  "min_pages": 10,
  "max_pages": 1000,
  "platforms": ["wordpress", "shopify", "woocommerce"],
  "required_keywords": ["ecommerce", "shop", "store"],
  "excluded_keywords": ["casino", "adult", "gambling"],
  "required_urls": ["/contact", "/about"],
  "optional_urls": ["/blog", "/news"],
  "min_word_count": 500,
  "max_word_count": 50000,
  "has_email_contact": true,
  "language": "en",
  "custom_rules": {
    "has_social_media": true,
    "has_privacy_policy": true
  }
}
```

---

### 2.4 Website-Requirement Pivot Table

**Purpose:** Track which websites match which requirement sets.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_website_website_requirement_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_website_requirement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->foreignId('website_requirement_id')->constrained()->onDelete('cascade');
            $table->boolean('matches')->default(false)->index()->comment('Does website match this requirement');
            $table->json('match_details')->nullable()->comment('Detailed matching results per criterion');
            $table->timestamps();

            $table->unique(['website_id', 'website_requirement_id'], 'website_requirement_unique');
            $table->index(['website_requirement_id', 'matches'], 'requirement_matches_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_website_requirement');
    }
};
```

**Example Match Details JSON:**

```json
{
  "min_pages": {"required": 10, "actual": 45, "matched": true},
  "platforms": {"required": ["wordpress"], "actual": "wordpress", "matched": true},
  "required_keywords": {
    "required": ["ecommerce", "shop"],
    "found": ["ecommerce", "shop", "store"],
    "matched": true
  },
  "excluded_keywords": {
    "excluded": ["casino"],
    "found": [],
    "matched": true
  },
  "overall_score": 0.95
}
```

---

### 2.5 SMTP Credentials Table

**Purpose:** Manage multiple SMTP accounts for sending emails with daily limits.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_smtp_credentials_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Friendly name for this SMTP account');
            $table->string('host')->comment('SMTP server hostname');
            $table->unsignedSmallInteger('port')->default(587)->comment('SMTP port');
            $table->string('encryption', 10)->default('tls')->comment('tls, ssl, or null');
            $table->string('username')->comment('SMTP username');
            $table->text('password')->comment('SMTP password (encrypted)');
            $table->string('from_address')->comment('From email address');
            $table->string('from_name')->comment('From name');

            // Usage tracking and limits
            $table->boolean('is_active')->default(true)->index()->comment('Is this SMTP account active');
            $table->unsignedInteger('daily_limit')->default(100)->comment('Max emails per day');
            $table->unsignedInteger('emails_sent_today')->default(0)->comment('Emails sent today');
            $table->date('last_reset_date')->nullable()->comment('Last time counter was reset');

            // Health monitoring
            $table->timestamp('last_used_at')->nullable()->comment('Last successful send');
            $table->unsignedInteger('success_count')->default(0)->comment('Total successful sends');
            $table->unsignedInteger('failure_count')->default(0)->comment('Total failed sends');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'emails_sent_today'], 'smtp_active_usage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_credentials');
    }
};
```

**Password Encryption:**
Use Laravel's `encrypt()` and `decrypt()` functions in model accessors/mutators.

---

### 2.6 Website-SMTP Assignment Pivot Table

**Purpose:** Assign SMTP credentials to specific websites.

**Migration File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_website_smtp_credential_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_smtp_credential', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->foreignId('smtp_credential_id')->constrained()->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->boolean('is_primary')->default(true)->comment('Primary SMTP for this website');

            $table->index(['website_id', 'is_primary']);
            $table->unique(['website_id', 'smtp_credential_id'], 'website_smtp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_smtp_credential');
    }
};
```

---

## 3. Eloquent Models

### 3.1 Domain Model

**File:** `app/Models/Domain.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'domain',
        'tld',
        'status',
        'last_checked_at',
        'check_count',
        'notes',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'check_count' => 'integer',
        'status' => 'integer',
    ];

    // Status constants
    public const STATUS_PENDING = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_PROCESSED = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_BLOCKED = 4;

    /**
     * Get all websites for this domain
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /**
     * Scope: Get only pending domains
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Get domains ready for processing
     */
    public function scopeReadyForProcessing($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereNull('last_checked_at')
            ->orWhere('last_checked_at', '<', now()->subDays(30));
    }

    /**
     * Mark domain as checked
     */
    public function markAsChecked(): void
    {
        $this->update([
            'last_checked_at' => now(),
            'check_count' => $this->check_count + 1,
        ]);
    }

    /**
     * Extract TLD from domain automatically
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($domain) {
            if (empty($domain->tld)) {
                $parts = explode('.', $domain->domain);
                $domain->tld = end($parts);
            }
        });
    }
}
```

---

### 3.2 Website Model

**File:** `app/Models/Website.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Website extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'domain_id',
        'url',
        'status',
        'title',
        'description',
        'detected_platform',
        'page_count',
        'word_count',
        'crawled_at',
        'crawl_started_at',
        'crawl_attempts',
        'crawl_error',
        'meets_requirements',
        'requirements_result',
        'content_snapshot',
    ];

    protected $casts = [
        'status' => 'integer',
        'page_count' => 'integer',
        'word_count' => 'integer',
        'crawl_attempts' => 'integer',
        'meets_requirements' => 'boolean',
        'requirements_result' => 'array',
        'crawled_at' => 'datetime',
        'crawl_started_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 0;
    public const STATUS_CRAWLING = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_FAILED = 3;
    public const STATUS_PER_REVIEW = 4;

    /**
     * Get the domain for this website
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Get all requirements this website is checked against
     */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(WebsiteRequirement::class)
            ->withPivot(['matches', 'match_details'])
            ->withTimestamps();
    }

    /**
     * Get assigned SMTP credentials
     */
    public function smtpCredentials(): BelongsToMany
    {
        return $this->belongsToMany(SmtpCredential::class)
            ->withPivot(['assigned_at', 'is_primary']);
    }

    /**
     * Get primary SMTP credential
     */
    public function primarySmtpCredential()
    {
        return $this->smtpCredentials()
            ->wherePivot('is_primary', true)
            ->first();
    }

    /**
     * Scope: Get qualifying leads
     */
    public function scopeQualifiedLeads($query)
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where('meets_requirements', true);
    }

    /**
     * Scope: Pending review
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('status', self::STATUS_PER_REVIEW);
    }

    /**
     * Start crawling
     */
    public function startCrawl(): void
    {
        $this->update([
            'status' => self::STATUS_CRAWLING,
            'crawl_started_at' => now(),
            'crawl_attempts' => $this->crawl_attempts + 1,
        ]);
    }

    /**
     * Mark crawl as completed
     */
    public function completeCrawl(array $data = []): void
    {
        $this->update(array_merge([
            'status' => self::STATUS_COMPLETED,
            'crawled_at' => now(),
        ], $data));
    }

    /**
     * Mark crawl as failed
     */
    public function failCrawl(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'crawl_error' => $error,
        ]);
    }
}
```

---

### 3.3 WebsiteRequirement Model

**File:** `app/Models/WebsiteRequirement.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WebsiteRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'criteria',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'criteria' => 'array',
    ];

    /**
     * Get all websites checked against this requirement
     */
    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class)
            ->withPivot(['matches', 'match_details'])
            ->withTimestamps();
    }

    /**
     * Scope: Get only active requirements
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get matching websites
     */
    public function matchingWebsites()
    {
        return $this->websites()->wherePivot('matches', true);
    }

    /**
     * Check if a website meets this requirement
     */
    public function checkWebsite(Website $website): array
    {
        $criteria = $this->criteria ?? [];
        $results = [];
        $overallMatch = true;

        // Check page count
        if (isset($criteria['min_pages'])) {
            $matches = $website->page_count >= $criteria['min_pages'];
            $results['min_pages'] = [
                'required' => $criteria['min_pages'],
                'actual' => $website->page_count,
                'matched' => $matches,
            ];
            $overallMatch = $overallMatch && $matches;
        }

        if (isset($criteria['max_pages'])) {
            $matches = $website->page_count <= $criteria['max_pages'];
            $results['max_pages'] = [
                'required' => $criteria['max_pages'],
                'actual' => $website->page_count,
                'matched' => $matches,
            ];
            $overallMatch = $overallMatch && $matches;
        }

        // Check platform
        if (isset($criteria['platforms']) && is_array($criteria['platforms'])) {
            $matches = in_array($website->detected_platform, $criteria['platforms']);
            $results['platforms'] = [
                'required' => $criteria['platforms'],
                'actual' => $website->detected_platform,
                'matched' => $matches,
            ];
            $overallMatch = $overallMatch && $matches;
        }

        // Additional criteria checks would go here...

        return [
            'matches' => $overallMatch,
            'details' => $results,
        ];
    }
}
```

---

### 3.4 SmtpCredential Model

**File:** `app/Models/SmtpCredential.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;

class SmtpCredential extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'is_active',
        'daily_limit',
        'emails_sent_today',
        'last_reset_date',
        'last_used_at',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'port' => 'integer',
        'is_active' => 'boolean',
        'daily_limit' => 'integer',
        'emails_sent_today' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'last_reset_date' => 'date',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Get websites using this SMTP credential
     */
    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class)
            ->withPivot(['assigned_at', 'is_primary']);
    }

    /**
     * Encrypt password when setting
     */
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt password when getting
     */
    public function getPasswordAttribute($value): string
    {
        return Crypt::decryptString($value);
    }

    /**
     * Check if daily limit reached
     */
    public function hasReachedDailyLimit(): bool
    {
        $this->resetCounterIfNeeded();
        return $this->emails_sent_today >= $this->daily_limit;
    }

    /**
     * Increment sent counter
     */
    public function incrementSentCount(): void
    {
        $this->resetCounterIfNeeded();

        $this->update([
            'emails_sent_today' => $this->emails_sent_today + 1,
            'last_used_at' => now(),
            'success_count' => $this->success_count + 1,
        ]);
    }

    /**
     * Record failure
     */
    public function recordFailure(): void
    {
        $this->increment('failure_count');
    }

    /**
     * Reset daily counter if new day
     */
    protected function resetCounterIfNeeded(): void
    {
        if (!$this->last_reset_date || $this->last_reset_date->isToday() === false) {
            $this->update([
                'emails_sent_today' => 0,
                'last_reset_date' => today(),
            ]);
        }
    }

    /**
     * Scope: Get available SMTP accounts
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereRaw('emails_sent_today < daily_limit')
                  ->orWhere('last_reset_date', '<', today());
            });
    }
}
```

---

## 4. Database Factories

### 4.1 Domain Factory

**File:** `database/factories/DomainFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $domain = fake()->domainName();
        $parts = explode('.', $domain);
        $tld = end($parts);

        return [
            'domain' => $domain,
            'tld' => $tld,
            'status' => fake()->randomElement([
                Domain::STATUS_PENDING,
                Domain::STATUS_ACTIVE,
                Domain::STATUS_PROCESSED,
            ]),
            'last_checked_at' => fake()->optional(0.6)->dateTimeBetween('-30 days'),
            'check_count' => fake()->numberBetween(0, 10),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Indicate that the domain is pending
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Domain::STATUS_PENDING,
            'last_checked_at' => null,
            'check_count' => 0,
        ]);
    }

    /**
     * Indicate that the domain has been processed
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Domain::STATUS_PROCESSED,
            'last_checked_at' => now(),
            'check_count' => fake()->numberBetween(1, 5),
        ]);
    }
}
```

---

### 4.2 Website Factory

**File:** `database/factories/WebsiteFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\Website;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    public function definition(): array
    {
        $platforms = ['wordpress', 'shopify', 'wix', 'squarespace', 'custom', null];

        return [
            'domain_id' => Domain::factory(),
            'url' => fake()->url(),
            'status' => fake()->randomElement([
                Website::STATUS_PENDING,
                Website::STATUS_COMPLETED,
                Website::STATUS_FAILED,
            ]),
            'title' => fake()->sentence(),
            'description' => fake()->optional(0.7)->paragraph(),
            'detected_platform' => fake()->randomElement($platforms),
            'page_count' => fake()->numberBetween(1, 200),
            'word_count' => fake()->numberBetween(100, 50000),
            'crawled_at' => fake()->optional(0.6)->dateTimeBetween('-7 days'),
            'crawl_started_at' => fake()->optional(0.6)->dateTimeBetween('-7 days'),
            'crawl_attempts' => fake()->numberBetween(0, 3),
            'crawl_error' => null,
            'meets_requirements' => fake()->optional(0.8)->boolean(),
            'requirements_result' => null,
            'content_snapshot' => fake()->optional(0.5)->paragraphs(10, true),
        ];
    }

    /**
     * Indicate that the website is a qualified lead
     */
    public function qualifiedLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Website::STATUS_COMPLETED,
            'meets_requirements' => true,
            'detected_platform' => 'wordpress',
            'page_count' => fake()->numberBetween(20, 100),
            'word_count' => fake()->numberBetween(5000, 30000),
            'crawled_at' => now(),
        ]);
    }

    /**
     * Indicate that the crawl failed
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Website::STATUS_FAILED,
            'crawl_error' => fake()->sentence(),
            'crawl_attempts' => 3,
        ]);
    }
}
```

---

## 5. Testing Strategy

### 5.1 Unit Tests

#### Test: Domain Model

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
            'tld' => 'com',
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
        $websites = Website::factory()->count(3)->create([
            'domain_id' => $domain->id,
        ]);

        $this->assertCount(3, $domain->websites);
        $this->assertInstanceOf(Website::class, $domain->websites->first());
    }

    /** @test */
    public function it_can_mark_as_checked()
    {
        $domain = Domain::factory()->create([
            'check_count' => 0,
            'last_checked_at' => null,
        ]);

        $domain->markAsChecked();

        $this->assertEquals(1, $domain->check_count);
        $this->assertNotNull($domain->last_checked_at);
    }

    /** @test */
    public function pending_scope_returns_only_pending_domains()
    {
        Domain::factory()->count(3)->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->count(2)->create(['status' => Domain::STATUS_PROCESSED]);

        $pending = Domain::pending()->get();

        $this->assertCount(3, $pending);
    }

    /** @test */
    public function it_soft_deletes()
    {
        $domain = Domain::factory()->create();
        $domain->delete();

        $this->assertSoftDeleted('domains', ['id' => $domain->id]);
    }
}
```

---

#### Test: Website Model

**File:** `tests/Unit/Models/WebsiteTest.php`

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Domain;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_belongs_to_a_domain()
    {
        $domain = Domain::factory()->create();
        $website = Website::factory()->create(['domain_id' => $domain->id]);

        $this->assertInstanceOf(Domain::class, $website->domain);
        $this->assertEquals($domain->id, $website->domain->id);
    }

    /** @test */
    public function it_can_have_many_requirements()
    {
        $website = Website::factory()->create();
        $requirements = WebsiteRequirement::factory()->count(2)->create();

        $website->requirements()->attach($requirements[0]->id, [
            'matches' => true,
            'match_details' => json_encode(['test' => 'data']),
        ]);

        $this->assertCount(1, $website->requirements);
    }

    /** @test */
    public function it_can_start_crawl()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
            'crawl_attempts' => 0,
        ]);

        $website->startCrawl();

        $this->assertEquals(Website::STATUS_CRAWLING, $website->status);
        $this->assertEquals(1, $website->crawl_attempts);
        $this->assertNotNull($website->crawl_started_at);
    }

    /** @test */
    public function it_can_complete_crawl()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_CRAWLING,
        ]);

        $website->completeCrawl([
            'page_count' => 25,
            'word_count' => 5000,
        ]);

        $this->assertEquals(Website::STATUS_COMPLETED, $website->status);
        $this->assertEquals(25, $website->page_count);
        $this->assertNotNull($website->crawled_at);
    }

    /** @test */
    public function it_can_fail_crawl()
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_CRAWLING,
        ]);

        $website->failCrawl('Connection timeout');

        $this->assertEquals(Website::STATUS_FAILED, $website->status);
        $this->assertEquals('Connection timeout', $website->crawl_error);
    }

    /** @test */
    public function qualified_leads_scope_returns_matching_websites()
    {
        Website::factory()->count(2)->qualifiedLead()->create();
        Website::factory()->count(3)->create(['meets_requirements' => false]);

        $leads = Website::qualifiedLeads()->get();

        $this->assertCount(2, $leads);
        $this->assertTrue($leads->every(fn($w) => $w->meets_requirements === true));
    }
}
```

---

### 5.2 Feature Tests

#### Test: Website Management

**File:** `tests/Feature/WebsiteManagementTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_process_a_complete_website_flow()
    {
        // Create a domain
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'status' => Domain::STATUS_PENDING,
        ]);

        // Create a website
        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        // Start crawl
        $website->startCrawl();
        $this->assertEquals(Website::STATUS_CRAWLING, $website->fresh()->status);

        // Complete crawl
        $website->completeCrawl([
            'title' => 'Example Website',
            'page_count' => 50,
            'word_count' => 10000,
            'detected_platform' => 'wordpress',
        ]);

        $website = $website->fresh();
        $this->assertEquals(Website::STATUS_COMPLETED, $website->status);
        $this->assertEquals('wordpress', $website->detected_platform);
        $this->assertEquals(50, $website->page_count);

        // Mark domain as processed
        $domain->update(['status' => Domain::STATUS_PROCESSED]);
        $domain->markAsChecked();

        $this->assertEquals(Domain::STATUS_PROCESSED, $domain->fresh()->status);
        $this->assertEquals(1, $domain->fresh()->check_count);
    }

    /** @test */
    public function it_can_match_websites_against_requirements()
    {
        $website = Website::factory()->create([
            'detected_platform' => 'wordpress',
            'page_count' => 50,
            'word_count' => 10000,
        ]);

        $requirement = WebsiteRequirement::factory()->create([
            'name' => 'WordPress Sites',
            'criteria' => [
                'min_pages' => 10,
                'max_pages' => 100,
                'platforms' => ['wordpress'],
            ],
        ]);

        $result = $requirement->checkWebsite($website);

        $this->assertTrue($result['matches']);
        $this->assertArrayHasKey('details', $result);
    }

    /** @test */
    public function cascading_delete_removes_websites_when_domain_deleted()
    {
        $domain = Domain::factory()->create();
        $website = Website::factory()->create(['domain_id' => $domain->id]);

        $domain->delete();

        $this->assertSoftDeleted('domains', ['id' => $domain->id]);
        $this->assertSoftDeleted('websites', ['id' => $website->id]);
    }
}
```

---

### 5.3 Performance Tests

#### Test: Large Dataset Query Performance

**File:** `tests/Feature/PerformanceTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_efficiently_query_pending_domains_from_large_dataset()
    {
        // Create 10,000 test domains
        Domain::factory()->count(5000)->create(['status' => Domain::STATUS_PENDING]);
        Domain::factory()->count(5000)->create(['status' => Domain::STATUS_PROCESSED]);

        // Enable query logging
        DB::enableQueryLog();

        // Query pending domains
        $start = microtime(true);
        $pending = Domain::pending()->limit(100)->get();
        $duration = microtime(true) - $start;

        $queries = DB::getQueryLog();

        // Assertions
        $this->assertCount(100, $pending);
        $this->assertLessThan(0.1, $duration, 'Query took too long');
        $this->assertCount(1, $queries, 'Should only execute 1 query');
    }

    /** @test */
    public function it_can_efficiently_chunk_through_millions_of_domains()
    {
        Domain::factory()->count(1000)->create();

        $processedCount = 0;

        $start = microtime(true);

        Domain::query()->chunk(100, function ($domains) use (&$processedCount) {
            $processedCount += $domains->count();
        });

        $duration = microtime(true) - $start;

        $this->assertEquals(1000, $processedCount);
        $this->assertLessThan(1, $duration, 'Chunking took too long');
    }

    /** @test */
    public function indexes_are_being_used_for_common_queries()
    {
        Domain::factory()->count(100)->create();

        DB::enableQueryLog();

        // Query that should use indexes
        Domain::where('status', Domain::STATUS_PENDING)
            ->where('tld', 'com')
            ->get();

        $queries = DB::getQueryLog();
        $query = $queries[0]['query'];

        // Verify indexes exist (this is a basic check)
        $indexes = DB::select('SHOW INDEX FROM domains');
        $indexNames = collect($indexes)->pluck('Key_name')->unique();

        $this->assertTrue($indexNames->contains('domains_status_index'));
        $this->assertTrue($indexNames->contains('domains_tld_index'));
    }
}
```

---

## 6. Implementation Checklist

### Phase 1: Database Setup ✓
- [ ] Update `.env` file to use MySQL connection
- [ ] Test MySQL connection via `php artisan db:show`
- [ ] Run `php artisan migrate:fresh` to reset database
- [ ] Create domains table migration
- [ ] Create websites table migration
- [ ] Create website_requirements table migration
- [ ] Create website_website_requirement pivot migration
- [ ] Create smtp_credentials table migration
- [ ] Create website_smtp_credential pivot migration
- [ ] Run migrations: `php artisan migrate`
- [ ] Verify all tables exist: `php artisan db:table domains`

### Phase 2: Models ✓
- [ ] Create Domain model with relationships
- [ ] Create Website model with relationships
- [ ] Create WebsiteRequirement model
- [ ] Create SmtpCredential model with encryption
- [ ] Test model creation in tinker
- [ ] Verify all relationships work correctly

### Phase 3: Factories ✓
- [ ] Create DomainFactory
- [ ] Create WebsiteFactory
- [ ] Test factories: `Domain::factory()->count(10)->create()`
- [ ] Verify factory states work (pending, processed, qualified lead, etc.)

### Phase 4: Testing ✓
- [ ] Create DomainTest unit test
- [ ] Create WebsiteTest unit test
- [ ] Create WebsiteManagementTest feature test
- [ ] Create PerformanceTest
- [ ] Run tests: `php artisan test`
- [ ] Aim for 80%+ code coverage on models

### Phase 5: Dependencies ✓
- [ ] Install Roach PHP: `composer require roach-php/core`
- [ ] Verify installation: `composer show roach-php/core`

### Phase 6: Verification ✓
- [ ] Create 1,000 test domains using factory
- [ ] Create 5,000 test websites using factory
- [ ] Run performance queries
- [ ] Check query execution time
- [ ] Verify indexes are being used (EXPLAIN queries)
- [ ] Test soft deletes
- [ ] Test cascading deletes

---

## 7. Database Optimization Guidelines

### 7.1 Index Strategy

**Always Index:**
- Foreign keys
- Status columns (used in WHERE clauses)
- Timestamp columns used for ordering/filtering
- Unique constraints

**Composite Indexes for Common Queries:**
```sql
-- Example: Finding next domain to process
SELECT * FROM domains
WHERE status = 0
ORDER BY last_checked_at ASC
LIMIT 1;

-- Requires composite index:
INDEX (status, last_checked_at)
```

### 7.2 Query Optimization

**Use Chunking for Large Datasets:**
```php
// Bad - loads everything into memory
Domain::all()->each(function($domain) {
    // process
});

// Good - processes in chunks
Domain::query()->chunk(1000, function($domains) {
    foreach ($domains as $domain) {
        // process
    }
});

// Better - lazy loading
Domain::query()->lazy(1000)->each(function($domain) {
    // process
});
```

**Use Select to Limit Columns:**
```php
// Bad - selects all columns
Domain::where('status', 0)->get();

// Good - only selects what you need
Domain::where('status', 0)->select('id', 'domain', 'status')->get();
```

**Eager Loading to Prevent N+1:**
```php
// Bad - N+1 query problem
$websites = Website::all();
foreach ($websites as $website) {
    echo $website->domain->name; // Queries database each time
}

// Good - eager loading
$websites = Website::with('domain')->get();
foreach ($websites as $website) {
    echo $website->domain->name; // Already loaded
}
```

### 7.3 Scaling Considerations

**Partitioning Strategy (for 10M+ domains):**
```sql
-- Partition domains table by status
ALTER TABLE domains
PARTITION BY LIST (status) (
    PARTITION p_pending VALUES IN (0),
    PARTITION p_active VALUES IN (1),
    PARTITION p_processed VALUES IN (2),
    PARTITION p_failed VALUES IN (3)
);
```

**Archive Old Data:**
```php
// Move old processed domains to archive table
DB::statement('
    INSERT INTO domains_archive
    SELECT * FROM domains
    WHERE status = 2
    AND updated_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
');

DB::table('domains')
    ->where('status', 2)
    ->where('updated_at', '<', now()->subMonths(6))
    ->delete();
```

---

## 8. Next Steps After Step 1

Once Step 1 is complete and verified, the following components will be needed:

### Immediate Next Steps:
1. **Email/Contact Extraction System** (Step 1.5)
   - Email extraction service
   - Contact information models
   - Validation and deduplication

2. **Web Crawling Implementation** (Step 2)
   - Roach PHP spider configuration
   - Crawl job implementation
   - Platform detection logic
   - Content extraction

3. **Requirements Matching Engine** (Step 3)
   - RequirementsMatcherService
   - Evaluation jobs
   - Scoring system

4. **Email Infrastructure** (Step 4)
   - Email template system
   - Mistral AI integration
   - SMTP rotation logic
   - Rate limiting (10/day, 8AM-5PM)

5. **Queue Management** (Step 5)
   - Queue jobs for all operations
   - Job prioritization
   - Failure handling
   - Laravel Horizon setup

6. **Dashboard & UI** (Step 6)
   - Admin panel
   - Statistics dashboard
   - Review queue interface
   - Blacklist management

---

## 9. Success Metrics

### Step 1 Completion Criteria:

**Database:**
- ✓ MySQL connection working
- ✓ All 6 tables created with proper schemas
- ✓ All indexes created and verified
- ✓ Foreign key constraints working

**Models:**
- ✓ All 4 models created (Domain, Website, WebsiteRequirement, SmtpCredential)
- ✓ Relationships working bidirectionally
- ✓ Accessors/mutators functioning (password encryption)
- ✓ Scopes defined and tested

**Testing:**
- ✓ Unit tests passing (80%+ coverage)
- ✓ Feature tests passing
- ✓ Performance tests passing
- ✓ Can handle 10K+ records efficiently

**Dependencies:**
- ✓ Roach PHP installed
- ✓ All composer dependencies resolved

**Performance:**
- ✓ Queries on 10K records < 100ms
- ✓ Chunk processing working
- ✓ Indexes being utilized (verify with EXPLAIN)

---

## 10. Troubleshooting

### Common Issues:

**MySQL Connection Failed:**
```bash
# Check if MySQL container is running
docker-compose ps

# Check MySQL logs
docker-compose logs mysql

# Restart MySQL
docker-compose restart mysql
```

**Migration Errors:**
```bash
# Reset database completely
php artisan migrate:fresh

# Check migration status
php artisan migrate:status

# Rollback specific migration
php artisan migrate:rollback --step=1
```

**Factory Errors:**
```bash
# Clear application cache
php artisan optimize:clear

# Regenerate autoload files
composer dump-autoload
```

**Performance Issues:**
```bash
# Verify indexes
php artisan db:table domains --show-indexes

# Check query performance
php artisan tinker
>>> DB::enableQueryLog();
>>> Domain::pending()->limit(100)->get();
>>> DB::getQueryLog();
```

---

## Conclusion

This implementation plan provides a complete roadmap for Step 1 of the automated website research and outreach system. Following this plan will establish a solid foundation capable of handling millions of domains efficiently.

**Estimated Implementation Time:** 4-6 hours for experienced Laravel developer

**Priority:** HIGH - This is the foundation for all subsequent features

**Risk Level:** LOW - Standard Laravel patterns with proven scalability

**Next Document:** `step2-implementation-plan.md` (Web Crawling System)
