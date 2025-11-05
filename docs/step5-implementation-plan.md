# Step 5 Implementation Plan: Duplicate Prevention & Email Tracking

## Executive Summary

Step 5 implements a comprehensive email tracking and duplicate prevention system to ensure contacts are never emailed multiple times and maintain complete visibility into all outreach activities.

**Key Objectives:**
- Track all sent emails with full details
- Prevent duplicate emails to same contact/domain
- Monitor delivery status
- Provide historical outreach records
- Enable reporting and analytics

**Dependencies:**
- Step 1 completed (SMTP credentials exist)
- Step 2 completed (Contacts table exists)

---

## 1. Database Schema

### 1.1 Email Sent Log Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_email_sent_log_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sent_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->foreignId('smtp_credential_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_template_id')->nullable()->constrained()->onDelete('set null');

            // Email details
            $table->string('recipient_email')->index();
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->text('body');

            // Status tracking
            $table->string('status', 50)->default('sent')->index(); // sent, delivered, bounced, failed
            $table->text('error_message')->nullable();
            $table->json('smtp_response')->nullable();

            // Timing
            $table->timestamp('sent_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('opened_at')->nullable(); // If tracking enabled
            $table->timestamp('clicked_at')->nullable(); // If tracking enabled

            // Metadata
            $table->string('message_id')->nullable()->unique();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Composite indexes
            $table->index(['contact_id', 'sent_at']);
            $table->index(['recipient_email', 'sent_at']);
            $table->index(['status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sent_log');
    }
};
```

---

## 2. Models

### 2.1 Email Sent Log Model

**File:** `app/Models/EmailSentLog.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSentLog extends Model
{
    use HasFactory;

    protected $table = 'email_sent_log';

    protected $fillable = [
        'website_id',
        'contact_id',
        'smtp_credential_id',
        'email_template_id',
        'recipient_email',
        'recipient_name',
        'subject',
        'body',
        'status',
        'error_message',
        'smtp_response',
        'sent_at',
        'delivered_at',
        'bounced_at',
        'opened_at',
        'clicked_at',
        'message_id',
        'metadata',
    ];

    protected $casts = [
        'smtp_response' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'bounced_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_FAILED = 'failed';

    /**
     * Relationships
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function smtpCredential(): BelongsTo
    {
        return $this->belongsTo(SmtpCredential::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    /**
     * Scopes
     */
    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_BOUNCED]);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('sent_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('sent_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    /**
     * Mark as delivered
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Mark as bounced
     */
    public function markAsBounced(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_BOUNCED,
            'bounced_at' => now(),
            'error_message' => $reason,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);
    }
}
```

---

## 3. Services

### 3.1 Duplicate Prevention Service

**File:** `app/Services/DuplicatePreventionService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\Website;
use Carbon\Carbon;

class DuplicatePreventionService
{
    /**
     * Check if contact has been emailed recently
     */
    public function hasBeenContactedRecently(Contact $contact, int $daysCooldown = 30): bool
    {
        $cutoffDate = Carbon::now()->subDays($daysCooldown);

        return EmailSentLog::where('contact_id', $contact->id)
            ->where('sent_at', '>=', $cutoffDate)
            ->exists();
    }

    /**
     * Check if email address has been contacted
     */
    public function hasEmailBeenContacted(string $email, int $daysCooldown = 30): bool
    {
        $cutoffDate = Carbon::now()->subDays($daysCooldown);

        return EmailSentLog::where('recipient_email', $email)
            ->where('sent_at', '>=', $cutoffDate)
            ->exists();
    }

    /**
     * Check if domain has been contacted
     */
    public function hasDomainBeenContacted(Website $website, int $daysCooldown = 30): bool
    {
        $cutoffDate = Carbon::now()->subDays($daysCooldown);

        return EmailSentLog::where('website_id', $website->id)
            ->where('sent_at', '>=', $cutoffDate)
            ->exists();
    }

    /**
     * Get last contact date for contact
     */
    public function getLastContactDate(Contact $contact): ?Carbon
    {
        $log = EmailSentLog::where('contact_id', $contact->id)
            ->orderByDesc('sent_at')
            ->first();

        return $log?->sent_at;
    }

    /**
     * Get contact count for contact
     */
    public function getContactCount(Contact $contact): int
    {
        return EmailSentLog::where('contact_id', $contact->id)->count();
    }

    /**
     * Get all emails sent to domain
     */
    public function getDomainEmailHistory(Website $website)
    {
        return EmailSentLog::where('website_id', $website->id)
            ->orderByDesc('sent_at')
            ->get();
    }

    /**
     * Check if safe to contact (no duplicates)
     */
    public function isSafeToContact(
        Contact $contact,
        int $daysCooldown = 30,
        bool $checkDomain = true
    ): array {
        $reasons = [];

        // Check contact cooldown
        if ($this->hasBeenContactedRecently($contact, $daysCooldown)) {
            $lastContact = $this->getLastContactDate($contact);
            $reasons[] = "Contact was emailed on {$lastContact->format('Y-m-d')}";
        }

        // Check domain cooldown
        if ($checkDomain && $this->hasDomainBeenContacted($contact->website, $daysCooldown)) {
            $reasons[] = "Domain was contacted within last {$daysCooldown} days";
        }

        return [
            'safe' => empty($reasons),
            'reasons' => $reasons,
        ];
    }

    /**
     * Record email sent
     */
    public function recordEmail(array $data): EmailSentLog
    {
        $log = EmailSentLog::create(array_merge([
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now(),
        ], $data));

        // Update contact
        if (isset($data['contact_id'])) {
            $contact = Contact::find($data['contact_id']);
            $contact?->markAsContacted();
        }

        return $log;
    }
}
```

---

## 4. Testing

### 4.1 Unit Tests

**File:** `tests/Unit/Services/DuplicatePreventionServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\Website;
use App\Services\DuplicatePreventionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicatePreventionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DuplicatePreventionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DuplicatePreventionService();
    }

    /** @test */
    public function it_detects_recently_contacted_contact()
    {
        $contact = Contact::factory()->create();

        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'sent_at' => now()->subDays(5),
        ]);

        $hasBeenContacted = $this->service->hasBeenContactedRecently($contact, 30);

        $this->assertTrue($hasBeenContacted);
    }

    /** @test */
    public function it_allows_contact_after_cooldown_period()
    {
        $contact = Contact::factory()->create();

        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'sent_at' => now()->subDays(35),
        ]);

        $hasBeenContacted = $this->service->hasBeenContactedRecently($contact, 30);

        $this->assertFalse($hasBeenContacted);
    }

    /** @test */
    public function it_checks_if_safe_to_contact()
    {
        $contact = Contact::factory()->create();

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
        $this->assertEmpty($result['reasons']);
    }

    /** @test */
    public function it_prevents_duplicate_contact()
    {
        $contact = Contact::factory()->create();

        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'sent_at' => now()->subDays(5),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['reasons']);
    }

    /** @test */
    public function it_records_email_sent()
    {
        $contact = Contact::factory()->create([
            'contacted' => false,
            'contact_count' => 0,
        ]);

        $log = $this->service->recordEmail([
            'contact_id' => $contact->id,
            'website_id' => $contact->website_id,
            'smtp_credential_id' => 1,
            'recipient_email' => $contact->email,
            'subject' => 'Test',
            'body' => 'Test body',
        ]);

        $this->assertInstanceOf(EmailSentLog::class, $log);
        $this->assertDatabaseHas('email_sent_log', [
            'contact_id' => $contact->id,
        ]);

        // Check contact was updated
        $this->assertTrue($contact->fresh()->contacted);
        $this->assertEquals(1, $contact->fresh()->contact_count);
    }
}
```

---

## 5. Database Factories

**File:** `database/factories/EmailSentLogFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\SmtpCredential;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailSentLogFactory extends Factory
{
    protected $model = EmailSentLog::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'contact_id' => Contact::factory(),
            'smtp_credential_id' => SmtpCredential::factory(),
            'recipient_email' => fake()->safeEmail(),
            'recipient_name' => fake()->name(),
            'subject' => fake()->sentence(),
            'body' => fake()->paragraphs(3, true),
            'status' => fake()->randomElement([
                EmailSentLog::STATUS_SENT,
                EmailSentLog::STATUS_DELIVERED,
            ]),
            'sent_at' => fake()->dateTimeBetween('-30 days'),
            'delivered_at' => fake()->optional(0.8)->dateTimeBetween('-29 days'),
        ];
    }
}
```

---

## 6. Usage Examples

### Check Before Sending
```php
use App\Services\DuplicatePreventionService;

$dupeService = new DuplicatePreventionService();

$contact = Contact::find(1);
$check = $dupeService->isSafeToContact($contact, daysCooldown: 30);

if ($check['safe']) {
    // Send email
    $dupeService->recordEmail([
        'contact_id' => $contact->id,
        'website_id' => $contact->website_id,
        'smtp_credential_id' => 1,
        'recipient_email' => $contact->email,
        'subject' => 'Hello',
        'body' => 'Email body',
    ]);
} else {
    // Don't send - log reasons
    Log::info('Skipping contact', $check['reasons']);
}
```

### Query Sent Emails
```php
// Today's emails
$todaysEmails = EmailSentLog::today()->count();

// This week's emails
$weeklyEmails = EmailSentLog::thisWeek()->count();

// Failed emails
$failed = EmailSentLog::failed()->get();

// Get contact history
$history = $dupeService->getDomainEmailHistory($website);
```

---

## 7. Implementation Checklist

- [ ] Create email_sent_log migration
- [ ] Run migration
- [ ] Create EmailSentLog model
- [ ] Create DuplicatePreventionService
- [ ] Create tests
- [ ] Test duplicate prevention logic
- [ ] Integrate with email sending system (Step 7)

---

## Conclusion

**Estimated Time:** 2-3 hours
**Priority:** HIGH - Critical for preventing spam
**Next Document:** `step6-implementation-plan.md`
