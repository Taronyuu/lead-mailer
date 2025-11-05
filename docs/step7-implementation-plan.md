# Step 7 Implementation Plan: Rate-Limited Email Sending System

## Executive Summary

Step 7 implements a sophisticated email sending system with rate limiting, SMTP rotation, time window enforcement, and intelligent queue management.

**Key Objectives:**
- Enforce daily limits per SMTP account (default 10/day)
- Restrict sending to time windows (8AM-5PM)
- SMTP account rotation
- Retry failed sends
- Health monitoring for SMTP accounts
- Queue prioritization

**Dependencies:**
- Step 1 (SMTP credentials table)
- Step 5 (Duplicate prevention)
- Step 6 (Email templates)

---

## 1. Services

### 1.1 Email Sending Service

**File:** `app/Services/EmailSendingService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailSendingService
{
    protected SmtpRotationService $smtpRotation;
    protected RateLimiterService $rateLimiter;
    protected DuplicatePreventionService $dupeService;
    protected EmailTemplateService $templateService;

    public function __construct(
        SmtpRotationService $smtpRotation,
        RateLimiterService $rateLimiter,
        DuplicatePreventionService $dupeService,
        EmailTemplateService $templateService
    ) {
        $this->smtpRotation = $smtpRotation;
        $this->rateLimiter = $rateLimiter;
        $this->dupeService = $dupeService;
        $this->templateService = $templateService;
    }

    /**
     * Send email to contact
     */
    public function send(
        Contact $contact,
        EmailTemplate $template,
        ?SmtpCredential $smtp = null
    ): array {
        // Check if within allowed time window
        if (!$this->rateLimiter->isWithinTimeWindow()) {
            return [
                'success' => false,
                'error' => 'Outside allowed sending hours (8AM-5PM)',
            ];
        }

        // Check for duplicates
        $dupeCheck = $this->dupeService->isSafeToContact($contact);
        if (!$dupeCheck['safe']) {
            return [
                'success' => false,
                'error' => 'Duplicate prevention: ' . implode(', ', $dupeCheck['reasons']),
            ];
        }

        // Get available SMTP
        if (!$smtp) {
            $smtp = $this->smtpRotation->getAvailableSmtp();

            if (!$smtp) {
                return [
                    'success' => false,
                    'error' => 'No available SMTP accounts',
                ];
            }
        }

        // Render email
        $email = $this->templateService->render(
            $template,
            $contact->website,
            $contact
        );

        // Send email
        try {
            $this->sendViaSmtp($smtp, $contact, $email);

            // Record success
            $log = $this->dupeService->recordEmail([
                'website_id' => $contact->website_id,
                'contact_id' => $contact->id,
                'smtp_credential_id' => $smtp->id,
                'email_template_id' => $template->id,
                'recipient_email' => $contact->email,
                'recipient_name' => $contact->name,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'status' => EmailSentLog::STATUS_SENT,
            ]);

            // Update SMTP stats
            $smtp->incrementSentCount();

            // Increment template usage
            $template->incrementUsage();

            Log::info('Email sent successfully', [
                'contact_id' => $contact->id,
                'smtp_id' => $smtp->id,
                'log_id' => $log->id,
            ]);

            return [
                'success' => true,
                'log_id' => $log->id,
                'smtp_id' => $smtp->id,
            ];

        } catch (\Exception $e) {
            // Record failure
            $this->dupeService->recordEmail([
                'website_id' => $contact->website_id,
                'contact_id' => $contact->id,
                'smtp_credential_id' => $smtp->id,
                'email_template_id' => $template->id,
                'recipient_email' => $contact->email,
                'recipient_name' => $contact->name,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'status' => EmailSentLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $smtp->recordFailure();

            Log::error('Email send failed', [
                'contact_id' => $contact->id,
                'smtp_id' => $smtp->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send email via SMTP
     */
    protected function sendViaSmtp(SmtpCredential $smtp, Contact $contact, array $email): void
    {
        // Configure mailer with SMTP credentials
        config([
            'mail.mailers.smtp.host' => $smtp->host,
            'mail.mailers.smtp.port' => $smtp->port,
            'mail.mailers.smtp.encryption' => $smtp->encryption,
            'mail.mailers.smtp.username' => $smtp->username,
            'mail.mailers.smtp.password' => $smtp->password,
            'mail.from.address' => $smtp->from_address,
            'mail.from.name' => $smtp->from_name,
        ]);

        Mail::raw($email['body'], function ($message) use ($contact, $email) {
            $message->to($contact->email, $contact->name)
                ->subject($email['subject']);

            if (isset($email['preheader'])) {
                $message->getHeaders()->addTextHeader('X-Preheader', $email['preheader']);
            }
        });
    }
}
```

---

### 1.2 SMTP Rotation Service

**File:** `app/Services/SmtpRotationService.php`

```php
<?php

namespace App\Services;

use App\Models\SmtpCredential;
use Illuminate\Support\Facades\Cache;

class SmtpRotationService
{
    /**
     * Get available SMTP account
     */
    public function getAvailableSmtp(): ?SmtpCredential
    {
        // Get all available SMTP accounts
        $available = SmtpCredential::available()->get();

        if ($available->isEmpty()) {
            return null;
        }

        // Use round-robin or least-used strategy
        return $this->selectLeastUsed($available);
    }

    /**
     * Select SMTP with least usage today
     */
    protected function selectLeastUsed($smtpAccounts): SmtpCredential
    {
        return $smtpAccounts->sortBy('emails_sent_today')->first();
    }

    /**
     * Check if SMTP is healthy
     */
    public function isHealthy(SmtpCredential $smtp): bool
    {
        // Calculate success rate
        $total = $smtp->success_count + $smtp->failure_count;

        if ($total === 0) {
            return true; // No history yet
        }

        $successRate = ($smtp->success_count / $total) * 100;

        // Disable if success rate below 70%
        if ($successRate < 70) {
            return false;
        }

        return true;
    }

    /**
     * Auto-disable failing SMTP accounts
     */
    public function checkAndDisableUnhealthy(): void
    {
        $smtpAccounts = SmtpCredential::where('is_active', true)->get();

        foreach ($smtpAccounts as $smtp) {
            if (!$this->isHealthy($smtp)) {
                $smtp->update(['is_active' => false]);

                Log::warning('SMTP account auto-disabled due to low success rate', [
                    'smtp_id' => $smtp->id,
                    'success_count' => $smtp->success_count,
                    'failure_count' => $smtp->failure_count,
                ]);
            }
        }
    }
}
```

---

### 1.3 Rate Limiter Service

**File:** `app/Services/RateLimiterService.php`

```php
<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class RateLimiterService
{
    protected int $startHour;
    protected int $endHour;

    public function __construct()
    {
        $this->startHour = config('mail.sending_window.start', 8);
        $this->endHour = config('mail.sending_window.end', 17);
    }

    /**
     * Check if within allowed time window
     */
    public function isWithinTimeWindow(?Carbon $time = null): bool
    {
        $time = $time ?? now();
        $hour = $time->hour;

        return $hour >= $this->startHour && $hour < $this->endHour;
    }

    /**
     * Get next available sending time
     */
    public function getNextAvailableTime(): Carbon
    {
        $now = now();

        // If we're before start hour today, return start hour today
        if ($now->hour < $this->startHour) {
            return $now->copy()->setHour($this->startHour)->setMinute(0)->setSecond(0);
        }

        // If we're after end hour, return start hour tomorrow
        if ($now->hour >= $this->endHour) {
            return $now->copy()->addDay()->setHour($this->startHour)->setMinute(0)->setSecond(0);
        }

        // We're within window, return now
        return $now;
    }

    /**
     * Get remaining sends for today
     */
    public function getRemainingCapacity(): int
    {
        $smtp = SmtpCredential::available()->get();

        return $smtp->sum(function ($account) {
            return $account->daily_limit - $account->emails_sent_today;
        });
    }

    /**
     * Calculate delay between sends (throttling)
     */
    public function calculateDelay(int $totalToSend): int
    {
        if (!$this->isWithinTimeWindow()) {
            return 0;
        }

        $now = now();
        $endTime = $now->copy()->setHour($this->endHour);
        $remainingMinutes = $now->diffInMinutes($endTime);

        if ($totalToSend === 0) {
            return 0;
        }

        // Distribute sends evenly across remaining time
        $delayMinutes = $remainingMinutes / $totalToSend;

        return max(1, (int) $delayMinutes); // At least 1 minute
    }
}
```

---

## 2. Queue Jobs

### 2.1 Send Outreach Email Job

**File:** `app/Jobs/SendOutreachEmailJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Services\EmailSendingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendOutreachEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;
    public $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Contact $contact,
        public EmailTemplate $template
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailSendingService $emailService): void
    {
        Log::info('Sending outreach email', [
            'contact_id' => $this->contact->id,
            'template_id' => $this->template->id,
        ]);

        $result = $emailService->send($this->contact, $this->template);

        if (!$result['success']) {
            Log::warning('Email send unsuccessful', [
                'contact_id' => $this->contact->id,
                'error' => $result['error'],
            ]);

            // Re-throw if it's a non-duplicate error
            if (!str_contains($result['error'], 'Duplicate')) {
                throw new \Exception($result['error']);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Outreach email job permanently failed', [
            'contact_id' => $this->contact->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

### 2.2 Process Email Queue Job

**File:** `app/Jobs/ProcessEmailQueueJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Services\RateLimiterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmailQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(RateLimiterService $rateLimiter): void
    {
        // Check if within time window
        if (!$rateLimiter->isWithinTimeWindow()) {
            Log::info('Outside sending window, skipping email queue');
            return;
        }

        // Get available capacity
        $capacity = $rateLimiter->getRemainingCapacity();

        if ($capacity <= 0) {
            Log::info('No SMTP capacity available');
            return;
        }

        // Get template (could be made configurable)
        $template = EmailTemplate::active()->first();

        if (!$template) {
            Log::warning('No active email template found');
            return;
        }

        // Get qualified contacts not yet contacted
        $contacts = Contact::validated()
            ->highPriority()
            ->notContacted()
            ->whereHas('website', function ($query) {
                $query->where('meets_requirements', true);
            })
            ->limit($capacity)
            ->get();

        Log::info('Processing email queue', [
            'capacity' => $capacity,
            'contacts' => $contacts->count(),
        ]);

        // Calculate delay between sends
        $delayMinutes = $rateLimiter->calculateDelay($contacts->count());

        foreach ($contacts as $index => $contact) {
            // Dispatch with delay
            $delaySeconds = $index * $delayMinutes * 60;

            SendOutreachEmailJob::dispatch($contact, $template)
                ->delay(now()->addSeconds($delaySeconds));
        }

        Log::info('Email queue processed', [
            'dispatched' => $contacts->count(),
            'delay_between' => $delayMinutes . ' minutes',
        ]);
    }
}
```

---

## 3. Scheduler Tasks

### 3.1 Schedule Email Queue Processing

**File:** `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Reset SMTP daily counters at midnight
    $schedule->call(function () {
        SmtpCredential::query()->update([
            'emails_sent_today' => 0,
            'last_reset_date' => today(),
        ]);
    })->daily();

    // Process email queue every 30 minutes during sending window (8AM-5PM)
    $schedule->job(new ProcessEmailQueueJob())
        ->everyThirtyMinutes()
        ->between('08:00', '17:00')
        ->timezone('America/New_York');

    // Check SMTP health daily
    $schedule->call(function () {
        app(SmtpRotationService::class)->checkAndDisableUnhealthy();
    })->dailyAt('23:00');
}
```

---

## 4. Configuration

**File:** `config/mail.php`

Add configuration:

```php
'sending_window' => [
    'start' => env('MAIL_WINDOW_START', 8), // 8 AM
    'end' => env('MAIL_WINDOW_END', 17), // 5 PM
    'timezone' => env('MAIL_WINDOW_TIMEZONE', 'America/New_York'),
],

'daily_limit' => [
    'default' => env('MAIL_DAILY_LIMIT', 10),
],
```

**File:** `.env`

```env
MAIL_WINDOW_START=8
MAIL_WINDOW_END=17
MAIL_WINDOW_TIMEZONE=America/New_York
MAIL_DAILY_LIMIT=10
```

---

## 5. Artisan Commands

### 5.1 Send Email Command

**File:** `app/Console/Commands/SendEmailCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Jobs\SendOutreachEmailJob;
use Illuminate\Console\Command;

class SendEmailCommand extends Command
{
    protected $signature = 'email:send
                            {contact_id : ID of contact to email}
                            {template_id? : ID of template to use}';

    protected $description = 'Send email to a specific contact';

    public function handle(): int
    {
        $contact = Contact::find($this->argument('contact_id'));

        if (!$contact) {
            $this->error('Contact not found');
            return self::FAILURE;
        }

        $templateId = $this->argument('template_id');
        $template = $templateId
            ? EmailTemplate::find($templateId)
            : EmailTemplate::active()->first();

        if (!$template) {
            $this->error('Template not found');
            return self::FAILURE;
        }

        SendOutreachEmailJob::dispatch($contact, $template);

        $this->info("Email queued for {$contact->email}");

        return self::SUCCESS;
    }
}
```

---

## 6. Testing

**File:** `tests/Feature/EmailSendingTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendOutreachEmailJob;
use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Services\EmailSendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailSendingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_dispatches_email_send_job()
    {
        Queue::fake();

        $contact = Contact::factory()->validated()->create();
        $template = EmailTemplate::factory()->create();

        SendOutreachEmailJob::dispatch($contact, $template);

        Queue::assertPushed(SendOutreachEmailJob::class);
    }

    /** @test */
    public function it_prevents_sending_outside_time_window()
    {
        $this->travelTo(now()->setHour(20)); // 8 PM

        $contact = Contact::factory()->validated()->create();
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $service = app(EmailSendingService::class);
        $result = $service->send($contact, $template, $smtp);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Outside allowed sending hours', $result['error']);
    }

    /** @test */
    public function it_prevents_duplicate_sends()
    {
        $contact = Contact::factory()->validated()->create();
        $template = EmailTemplate::factory()->create();

        // Create existing log
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'sent_at' => now()->subDays(5),
        ]);

        $service = app(EmailSendingService::class);
        $result = $service->send($contact, $template);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate prevention', $result['error']);
    }

    /** @test */
    public function it_uses_smtp_rotation()
    {
        $smtp1 = SmtpCredential::factory()->create([
            'emails_sent_today' => 5,
            'daily_limit' => 10,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'emails_sent_today' => 2,
            'daily_limit' => 10,
        ]);

        $rotation = app(SmtpRotationService::class);
        $selected = $rotation->getAvailableSmtp();

        // Should select the one with fewer emails sent
        $this->assertEquals($smtp2->id, $selected->id);
    }
}
```

---

## 7. Usage Examples

### Send Single Email
```php
use App\Jobs\SendOutreachEmailJob;

$contact = Contact::find(1);
$template = EmailTemplate::find(1);

SendOutreachEmailJob::dispatch($contact, $template);
```

### Manually Process Queue
```bash
php artisan email:queue:process
```

### Send to Specific Contact
```bash
php artisan email:send 123 --template=1
```

---

## 8. Implementation Checklist

- [ ] Create EmailSendingService
- [ ] Create SmtpRotationService
- [ ] Create RateLimiterService
- [ ] Create SendOutreachEmailJob
- [ ] Create ProcessEmailQueueJob
- [ ] Add scheduler tasks
- [ ] Add configuration
- [ ] Create artisan commands
- [ ] Create tests
- [ ] Test time window enforcement
- [ ] Test SMTP rotation
- [ ] Test rate limiting

---

## Conclusion

**Estimated Time:** 6-8 hours
**Priority:** HIGH - Core sending functionality
**Risk Level:** MEDIUM - Depends on SMTP reliability
**Next Document:** `step8-implementation-plan.md`
