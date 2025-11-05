# Step 9 Implementation Plan: Review Queue System

## Executive Summary

Step 9 implements a manual review queue system allowing quality control on email campaigns before sending, especially useful for sensitive outreach.

**Key Objectives:**
- Queue emails for manual approval
- Preview generated emails before sending
- Approve/reject individual emails
- Bulk approve/reject operations
- Track reviewers and review history
- Auto-populate for websites marked 'per_review'

**Dependencies:**
- Step 6 (Email templates)
- Step 7 (Email sending)

---

## 1. Database Schema

### 1.1 Email Review Queue Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_create_email_review_queue_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_review_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->foreignId('email_template_id')->constrained()->onDelete('cascade');

            // Generated content
            $table->string('generated_subject');
            $table->text('generated_body');
            $table->text('generated_preheader')->nullable();

            // Review status
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Priority
            $table->unsignedTinyInteger('priority')->default(50)->index();

            $table->timestamps();

            // Indexes
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_review_queue');
    }
};
```

---

## 2. Models

**File:** `app/Models/EmailReviewQueue.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailReviewQueue extends Model
{
    use HasFactory;

    protected $table = 'email_review_queue';

    protected $fillable = [
        'website_id',
        'contact_id',
        'email_template_id',
        'generated_subject',
        'generated_body',
        'generated_preheader',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'priority',
    ];

    protected $casts = [
        'priority' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

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

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 70);
    }

    /**
     * Approve email
     */
    public function approve(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Reject email
     */
    public function reject(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Check if email is ready to send
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
```

---

## 3. Services

**File:** `app/Services/ReviewQueueService.php`

```php
<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailReviewQueue;
use App\Models\EmailTemplate;
use App\Models\Website;
use Illuminate\Support\Collection;

class ReviewQueueService
{
    protected EmailTemplateService $templateService;

    public function __construct(EmailTemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Add email to review queue
     */
    public function addToQueue(
        Contact $contact,
        EmailTemplate $template,
        int $priority = 50
    ): EmailReviewQueue {
        // Generate email content
        $email = $this->templateService->render(
            $template,
            $contact->website,
            $contact
        );

        return EmailReviewQueue::create([
            'website_id' => $contact->website_id,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'generated_subject' => $email['subject'],
            'generated_body' => $email['body'],
            'generated_preheader' => $email['preheader'] ?? null,
            'priority' => $priority,
            'status' => EmailReviewQueue::STATUS_PENDING,
        ]);
    }

    /**
     * Bulk add to queue
     */
    public function bulkAddToQueue(
        Collection $contacts,
        EmailTemplate $template,
        int $priority = 50
    ): int {
        $count = 0;

        foreach ($contacts as $contact) {
            $this->addToQueue($contact, $template, $priority);
            $count++;
        }

        return $count;
    }

    /**
     * Approve email
     */
    public function approve(int $queueId, int $userId, ?string $notes = null): bool
    {
        $item = EmailReviewQueue::find($queueId);

        if (!$item || $item->status !== EmailReviewQueue::STATUS_PENDING) {
            return false;
        }

        $item->approve($userId, $notes);

        return true;
    }

    /**
     * Reject email
     */
    public function reject(int $queueId, int $userId, ?string $notes = null): bool
    {
        $item = EmailReviewQueue::find($queueId);

        if (!$item || $item->status !== EmailReviewQueue::STATUS_PENDING) {
            return false;
        }

        $item->reject($userId, $notes);

        return true;
    }

    /**
     * Bulk approve
     */
    public function bulkApprove(array $queueIds, int $userId): int
    {
        $count = 0;

        foreach ($queueIds as $id) {
            if ($this->approve($id, $userId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk reject
     */
    public function bulkReject(array $queueIds, int $userId, ?string $notes = null): int
    {
        $count = 0;

        foreach ($queueIds as $id) {
            if ($this->reject($id, $userId, $notes)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get pending review count
     */
    public function getPendingCount(): int
    {
        return EmailReviewQueue::pending()->count();
    }

    /**
     * Get pending items
     */
    public function getPending(int $limit = 50)
    {
        return EmailReviewQueue::pending()
            ->with(['contact', 'website', 'emailTemplate'])
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }
}
```

---

## 4. Controllers

**File:** `app/Http/Controllers/ReviewQueueController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\EmailReviewQueue;
use App\Services\ReviewQueueService;
use Illuminate\Http\Request;

class ReviewQueueController extends Controller
{
    protected ReviewQueueService $reviewService;

    public function __construct(ReviewQueueService $reviewService)
    {
        $this->reviewService = $reviewService;
    }

    /**
     * List pending reviews
     */
    public function index()
    {
        $pending = $this->reviewService->getPending(100);

        return view('review-queue.index', [
            'items' => $pending,
            'pendingCount' => $this->reviewService->getPendingCount(),
        ]);
    }

    /**
     * Show single review item
     */
    public function show(int $id)
    {
        $item = EmailReviewQueue::with(['contact', 'website', 'emailTemplate'])
            ->findOrFail($id);

        return view('review-queue.show', ['item' => $item]);
    }

    /**
     * Approve email
     */
    public function approve(Request $request, int $id)
    {
        $success = $this->reviewService->approve(
            $id,
            auth()->id(),
            $request->input('notes')
        );

        if ($success) {
            return response()->json(['message' => 'Email approved']);
        }

        return response()->json(['error' => 'Failed to approve'], 400);
    }

    /**
     * Reject email
     */
    public function reject(Request $request, int $id)
    {
        $success = $this->reviewService->reject(
            $id,
            auth()->id(),
            $request->input('notes')
        );

        if ($success) {
            return response()->json(['message' => 'Email rejected']);
        }

        return response()->json(['error' => 'Failed to reject'], 400);
    }

    /**
     * Bulk approve
     */
    public function bulkApprove(Request $request)
    {
        $ids = $request->input('ids', []);
        $count = $this->reviewService->bulkApprove($ids, auth()->id());

        return response()->json(['message' => "Approved {$count} emails"]);
    }

    /**
     * Bulk reject
     */
    public function bulkReject(Request $request)
    {
        $ids = $request->input('ids', []);
        $notes = $request->input('notes');

        $count = $this->reviewService->bulkReject($ids, auth()->id(), $notes);

        return response()->json(['message' => "Rejected {$count} emails"]);
    }
}
```

---

## 5. Jobs

**File:** `app/Jobs/ProcessApprovedEmailsJob.php`

```php
<?php

namespace App\Jobs;

use App\Models\EmailReviewQueue;
use App\Models\SmtpCredential;
use App\Services\EmailSendingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessApprovedEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(EmailSendingService $emailService): void
    {
        $approved = EmailReviewQueue::approved()
            ->whereDoesntHave('contact', function ($query) {
                $query->where('contacted', true);
            })
            ->limit(10)
            ->get();

        foreach ($approved as $item) {
            // Send via email service
            $result = $emailService->send(
                $item->contact,
                $item->emailTemplate
            );

            if ($result['success']) {
                // Mark as sent (delete from queue)
                $item->delete();
            }
        }
    }
}
```

---

## 6. Routes

**File:** `routes/web.php`

```php
use App\Http\Controllers\ReviewQueueController;

Route::middleware(['auth'])->prefix('review-queue')->group(function () {
    Route::get('/', [ReviewQueueController::class, 'index'])->name('review-queue.index');
    Route::get('/{id}', [ReviewQueueController::class, 'show'])->name('review-queue.show');
    Route::post('/{id}/approve', [ReviewQueueController::class, 'approve'])->name('review-queue.approve');
    Route::post('/{id}/reject', [ReviewQueueController::class, 'reject'])->name('review-queue.reject');
    Route::post('/bulk-approve', [ReviewQueueController::class, 'bulkApprove'])->name('review-queue.bulk-approve');
    Route::post('/bulk-reject', [ReviewQueueController::class, 'bulkReject'])->name('review-queue.bulk-reject');
});
```

---

## 7. Views (Example)

**File:** `resources/views/review-queue/index.blade.php`

```blade
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Email Review Queue ({{ $pendingCount }} pending)</h1>

    <form id="bulk-form">
        <div class="actions">
            <button type="button" onclick="bulkApprove()">Approve Selected</button>
            <button type="button" onclick="bulkReject()">Reject Selected</button>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>Contact</th>
                    <th>Website</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td><input type="checkbox" name="ids[]" value="{{ $item->id }}"></td>
                    <td>{{ $item->contact->email }}</td>
                    <td>{{ $item->website->url }}</td>
                    <td>{{ $item->generated_subject }}</td>
                    <td>{{ $item->priority }}</td>
                    <td>{{ $item->created_at->diffForHumans() }}</td>
                    <td>
                        <a href="{{ route('review-queue.show', $item->id) }}">Review</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </form>
</div>

<script>
function bulkApprove() {
    const ids = getSelectedIds();
    fetch('/review-queue/bulk-approve', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'},
        body: JSON.stringify({ids})
    }).then(() => location.reload());
}

function bulkReject() {
    const ids = getSelectedIds();
    const notes = prompt('Rejection reason (optional):');
    fetch('/review-queue/bulk-reject', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'},
        body: JSON.stringify({ids, notes})
    }).then(() => location.reload());
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('input[name="ids[]"]:checked')).map(el => el.value);
}
</script>
@endsection
```

---

## 8. Testing

**File:** `tests/Feature/ReviewQueueTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailReviewQueue;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\ReviewQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_adds_email_to_review_queue()
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $service = new ReviewQueueService(app(EmailTemplateService::class));
        $item = $service->addToQueue($contact, $template);

        $this->assertInstanceOf(EmailReviewQueue::class, $item);
        $this->assertEquals(EmailReviewQueue::STATUS_PENDING, $item->status);
    }

    /** @test */
    public function it_approves_email()
    {
        $user = User::factory()->create();
        $item = EmailReviewQueue::factory()->create();

        $service = new ReviewQueueService(app(EmailTemplateService::class));
        $success = $service->approve($item->id, $user->id);

        $this->assertTrue($success);
        $this->assertEquals(EmailReviewQueue::STATUS_APPROVED, $item->fresh()->status);
    }

    /** @test */
    public function it_rejects_email()
    {
        $user = User::factory()->create();
        $item = EmailReviewQueue::factory()->create();

        $service = new ReviewQueueService(app(EmailTemplateService::class));
        $success = $service->reject($item->id, $user->id, 'Not relevant');

        $this->assertTrue($success);
        $this->assertEquals(EmailReviewQueue::STATUS_REJECTED, $item->fresh()->status);
    }
}
```

---

## 9. Usage Examples

### Add to Review Queue
```php
$contact = Contact::find(1);
$template = EmailTemplate::find(1);

$reviewService = new ReviewQueueService(app(EmailTemplateService::class));
$item = $reviewService->addToQueue($contact, $template, priority: 80);
```

### Approve/Reject
```php
$reviewService->approve($itemId, $userId, 'Looks good');
$reviewService->reject($itemId, $userId, 'Wrong target');
```

### Process Approved Emails
```bash
php artisan queue:work
# ProcessApprovedEmailsJob runs periodically
```

---

## 10. Implementation Checklist

- [ ] Create email_review_queue migration
- [ ] Create EmailReviewQueue model
- [ ] Create ReviewQueueService
- [ ] Create ReviewQueueController
- [ ] Create ProcessApprovedEmailsJob
- [ ] Create routes
- [ ] Create views
- [ ] Create tests
- [ ] Add to scheduler

---

## Conclusion

**Estimated Time:** 4-5 hours
**Priority:** MEDIUM - Important for quality control
**Risk Level:** LOW - Standard CRUD with approval workflow
**Next Document:** `step10-implementation-plan.md`
