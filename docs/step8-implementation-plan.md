# Step 8 Implementation Plan: Blacklist Management System

## Executive Summary

Step 8 implements a comprehensive blacklist management system to exclude specific domains and email addresses from outreach campaigns.

**Key Objectives:**
- Maintain blacklist of domains and email addresses
- Auto-check before crawling and sending
- Support bulk import/export
- Track blacklist reasons
- Provide management interface

**Dependencies:**
- None (standalone feature)

---

## 1. Database Schema

### 1.1 Blacklist Entries Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_blacklist_entries_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index(); // 'domain' or 'email'
            $table->string('value')->index(); // domain.com or email@domain.com
            $table->text('reason')->nullable();
            $table->string('source', 50)->default('manual'); // manual, import, auto
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'value'], 'blacklist_type_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_entries');
    }
};
```

---

## 2. Models

**File:** `app/Models/BlacklistEntry.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlacklistEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'value',
        'reason',
        'source',
        'added_by_user_id',
    ];

    // Type constants
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_EMAIL = 'email';

    // Source constants
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_AUTO = 'auto';

    /**
     * Relationships
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopeDomains($query)
    {
        return $query->where('type', self::TYPE_DOMAIN);
    }

    public function scopeEmails($query)
    {
        return $query->where('type', self::TYPE_EMAIL);
    }
}
```

---

## 3. Services

**File:** `app/Services/BlacklistService.php`

```php
<?php

namespace App\Services;

use App\Models\BlacklistEntry;
use Illuminate\Support\Facades\Cache;

class BlacklistService
{
    protected const CACHE_KEY = 'blacklist';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Check if domain is blacklisted
     */
    public function isDomainBlacklisted(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return Cache::remember(
            self::CACHE_KEY . ':domain:' . $domain,
            self::CACHE_TTL,
            fn() => BlacklistEntry::domains()->where('value', $domain)->exists()
        );
    }

    /**
     * Check if email is blacklisted
     */
    public function isEmailBlacklisted(string $email): bool
    {
        $email = strtolower(trim($email));

        // Check exact email
        if ($this->isExactEmailBlacklisted($email)) {
            return true;
        }

        // Check domain
        $domain = explode('@', $email)[1] ?? '';
        return $this->isDomainBlacklisted($domain);
    }

    /**
     * Check exact email match
     */
    protected function isExactEmailBlacklisted(string $email): bool
    {
        return Cache::remember(
            self::CACHE_KEY . ':email:' . $email,
            self::CACHE_TTL,
            fn() => BlacklistEntry::emails()->where('value', $email)->exists()
        );
    }

    /**
     * Add domain to blacklist
     */
    public function addDomain(string $domain, ?string $reason = null, ?int $userId = null): BlacklistEntry
    {
        $domain = strtolower(trim($domain));

        $entry = BlacklistEntry::create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => $domain,
            'reason' => $reason,
            'source' => BlacklistEntry::SOURCE_MANUAL,
            'added_by_user_id' => $userId,
        ]);

        $this->clearCache();

        return $entry;
    }

    /**
     * Add email to blacklist
     */
    public function addEmail(string $email, ?string $reason = null, ?int $userId = null): BlacklistEntry
    {
        $email = strtolower(trim($email));

        $entry = BlacklistEntry::create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => $email,
            'reason' => $reason,
            'source' => BlacklistEntry::SOURCE_MANUAL,
            'added_by_user_id' => $userId,
        ]);

        $this->clearCache();

        return $entry;
    }

    /**
     * Remove from blacklist
     */
    public function remove(int $id): bool
    {
        $deleted = BlacklistEntry::destroy($id);
        $this->clearCache();

        return $deleted > 0;
    }

    /**
     * Bulk import from array
     */
    public function bulkImport(array $entries, string $type, ?int $userId = null): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($entries as $value) {
            try {
                BlacklistEntry::firstOrCreate([
                    'type' => $type,
                    'value' => strtolower(trim($value)),
                ], [
                    'source' => BlacklistEntry::SOURCE_IMPORT,
                    'added_by_user_id' => $userId,
                ]);

                $imported++;
            } catch (\Exception $e) {
                $skipped++;
                $errors[] = "Failed to import {$value}: " . $e->getMessage();
            }
        }

        $this->clearCache();

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Export to array
     */
    public function export(string $type): array
    {
        return BlacklistEntry::where('type', $type)
            ->pluck('value')
            ->toArray();
    }

    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        Cache::flush(); // Or use more specific cache clearing
    }
}
```

---

## 4. Integration

### 4.1 Update Website Model

Add blacklist check before crawling:

```php
// In Website model or CrawlWebsiteJob

public function isBlacklisted(): bool
{
    $blacklistService = app(BlacklistService::class);
    $domain = parse_url($this->url, PHP_URL_HOST);

    return $blacklistService->isDomainBlacklisted($domain);
}
```

### 4.2 Update Email Sending Service

Add check in `EmailSendingService::send()`:

```php
// Check blacklist
$blacklistService = app(BlacklistService::class);

if ($blacklistService->isEmailBlacklisted($contact->email)) {
    return [
        'success' => false,
        'error' => 'Email is blacklisted',
    ];
}
```

---

## 5. Artisan Commands

**File:** `app/Console/Commands/BlacklistCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Services\BlacklistService;
use Illuminate\Console\Command;

class BlacklistCommand extends Command
{
    protected $signature = 'blacklist:manage
                            {action : add, remove, list, import, export}
                            {type? : domain or email}
                            {value? : value to add/remove}
                            {--file= : CSV file for import/export}';

    protected $description = 'Manage blacklist entries';

    public function handle(BlacklistService $blacklist): int
    {
        $action = $this->argument('action');

        return match($action) {
            'add' => $this->add($blacklist),
            'remove' => $this->remove($blacklist),
            'list' => $this->listEntries($blacklist),
            'import' => $this->import($blacklist),
            'export' => $this->export($blacklist),
            default => $this->error('Invalid action'),
        };
    }

    protected function add(BlacklistService $blacklist): int
    {
        $type = $this->argument('type');
        $value = $this->argument('value');

        if ($type === 'domain') {
            $blacklist->addDomain($value);
        } else {
            $blacklist->addEmail($value);
        }

        $this->info("Added {$value} to blacklist");
        return self::SUCCESS;
    }

    protected function import(BlacklistService $blacklist): int
    {
        $file = $this->option('file');
        $type = $this->argument('type');

        if (!file_exists($file)) {
            $this->error('File not found');
            return self::FAILURE;
        }

        $entries = array_map('trim', file($file, FILE_IGNORE_NEW_LINES));
        $result = $blacklist->bulkImport($entries, $type);

        $this->info("Imported: {$result['imported']}, Skipped: {$result['skipped']}");

        return self::SUCCESS;
    }

    protected function export(BlacklistService $blacklist): int
    {
        $file = $this->option('file');
        $type = $this->argument('type');

        $entries = $blacklist->export($type);
        file_put_contents($file, implode("\n", $entries));

        $this->info("Exported " . count($entries) . " entries to {$file}");

        return self::SUCCESS;
    }

    protected function listEntries(BlacklistService $blacklist): int
    {
        $domains = BlacklistEntry::domains()->count();
        $emails = BlacklistEntry::emails()->count();

        $this->info("Domains: {$domains}");
        $this->info("Emails: {$emails}");

        return self::SUCCESS;
    }
}
```

---

## 6. Testing

**File:** `tests/Unit/Services/BlacklistServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\BlacklistEntry;
use App\Services\BlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlacklistServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BlacklistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlacklistService();
    }

    /** @test */
    public function it_detects_blacklisted_domain()
    {
        BlacklistEntry::create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
        ]);

        $this->assertTrue($this->service->isDomainBlacklisted('spam.com'));
        $this->assertFalse($this->service->isDomainBlacklisted('good.com'));
    }

    /** @test */
    public function it_detects_blacklisted_email()
    {
        BlacklistEntry::create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'spam@example.com',
        ]);

        $this->assertTrue($this->service->isEmailBlacklisted('spam@example.com'));
        $this->assertFalse($this->service->isEmailBlacklisted('good@example.com'));
    }

    /** @test */
    public function it_blocks_emails_from_blacklisted_domains()
    {
        BlacklistEntry::create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
        ]);

        $this->assertTrue($this->service->isEmailBlacklisted('anyone@spam.com'));
    }

    /** @test */
    public function it_adds_entries()
    {
        $this->service->addDomain('blocked.com', 'Spam domain');

        $this->assertDatabaseHas('blacklist_entries', [
            'type' => 'domain',
            'value' => 'blocked.com',
        ]);
    }

    /** @test */
    public function it_bulk_imports()
    {
        $entries = ['spam1.com', 'spam2.com', 'spam3.com'];

        $result = $this->service->bulkImport($entries, 'domain');

        $this->assertEquals(3, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
    }
}
```

---

## 7. Usage Examples

### Check Blacklist
```php
$blacklist = new BlacklistService();

if ($blacklist->isDomainBlacklisted('example.com')) {
    // Skip crawling
}

if ($blacklist->isEmailBlacklisted('spam@example.com')) {
    // Skip sending
}
```

### Add to Blacklist
```php
$blacklist->addDomain('spam.com', 'Known spam domain');
$blacklist->addEmail('abuse@example.com', 'Complained about emails');
```

### Bulk Import
```bash
php artisan blacklist:manage import domain --file=domains.txt
php artisan blacklist:manage export email --file=blacklisted_emails.txt
```

---

## 8. Implementation Checklist

- [ ] Create blacklist_entries migration
- [ ] Create BlacklistEntry model
- [ ] Create BlacklistService
- [ ] Add integration to crawl workflow
- [ ] Add integration to email sending
- [ ] Create BlacklistCommand
- [ ] Create tests
- [ ] Test caching
- [ ] Create sample blacklist

---

## Conclusion

**Estimated Time:** 2-3 hours
**Priority:** MEDIUM - Important for compliance
**Risk Level:** LOW - Simple CRUD operations
**Next Document:** `step9-implementation-plan.md`
