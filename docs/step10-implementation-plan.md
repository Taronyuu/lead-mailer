# Step 10 Implementation Plan: Dashboard & Admin Interface

## Executive Summary

Step 10 implements a comprehensive administrative dashboard providing visibility and control over all system operations including statistics, management interfaces, and reporting.

**Key Objectives:**
- Main dashboard with key metrics and charts
- CRUD interfaces for all resources
- Statistics and analytics
- Activity logs and monitoring
- Search and filtering capabilities
- Export functionality
- Real-time updates (optional)

**Dependencies:**
- All previous steps (1-9)

---

## 1. Controllers

### 1.1 Dashboard Controller

**File:** `app/Http/Controllers/DashboardController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailSentLog;
use App\Models\EmailReviewQueue;
use App\Models\SmtpCredential;
use App\Models\Website;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            // Overview stats
            'total_domains' => Domain::count(),
            'total_websites' => Website::count(),
            'total_contacts' => Contact::count(),
            'qualified_leads' => Website::qualifiedLeads()->count(),

            // Crawl stats
            'websites_pending' => Website::where('status', Website::STATUS_PENDING)->count(),
            'websites_crawled' => Website::where('status', Website::STATUS_COMPLETED)->count(),
            'websites_failed' => Website::where('status', Website::STATUS_FAILED)->count(),

            // Email stats
            'emails_today' => EmailSentLog::today()->count(),
            'emails_this_week' => EmailSentLog::thisWeek()->count(),
            'emails_this_month' => EmailSentLog::whereMonth('sent_at', now()->month)->count(),
            'emails_total' => EmailSentLog::count(),

            // Email status breakdown
            'emails_successful' => EmailSentLog::successful()->count(),
            'emails_failed' => EmailSentLog::failed()->count(),

            // Review queue
            'pending_reviews' => EmailReviewQueue::pending()->count(),

            // SMTP health
            'active_smtp' => SmtpCredential::where('is_active', true)->count(),
            'smtp_capacity' => SmtpCredential::available()->sum('daily_limit'),
            'smtp_used_today' => SmtpCredential::sum('emails_sent_today'),
        ];

        // Recent activity
        $recentEmails = EmailSentLog::with(['contact', 'website'])
            ->orderByDesc('sent_at')
            ->limit(10)
            ->get();

        $recentCrawls = Website::where('status', Website::STATUS_COMPLETED)
            ->orderByDesc('crawled_at')
            ->limit(10)
            ->get();

        // Charts data
        $emailsLast30Days = $this->getEmailsLast30Days();
        $leadsByPlatform = $this->getLeadsByPlatform();

        return view('dashboard.index', compact(
            'stats',
            'recentEmails',
            'recentCrawls',
            'emailsLast30Days',
            'leadsByPlatform'
        ));
    }

    protected function getEmailsLast30Days(): array
    {
        $data = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $count = EmailSentLog::whereDate('sent_at', $date)->count();
            $data[] = [
                'date' => $date->format('M d'),
                'count' => $count,
            ];
        }
        return $data;
    }

    protected function getLeadsByPlatform(): array
    {
        return Website::qualifiedLeads()
            ->selectRaw('detected_platform, COUNT(*) as count')
            ->groupBy('detected_platform')
            ->pluck('count', 'detected_platform')
            ->toArray();
    }
}
```

---

### 1.2 Websites Controller

**File:** `app/Http/Controllers/WebsitesController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlWebsiteJob;
use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Models\Website;
use Illuminate\Http\Request;

class WebsitesController extends Controller
{
    public function index(Request $request)
    {
        $query = Website::with('domain');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('platform')) {
            $query->where('detected_platform', $request->input('platform'));
        }

        if ($request->filled('qualified')) {
            $query->where('meets_requirements', true);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('url', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $websites = $query->paginate(50);

        return view('websites.index', compact('websites'));
    }

    public function show(int $id)
    {
        $website = Website::with(['domain', 'contacts', 'requirements'])->findOrFail($id);

        return view('websites.show', compact('website'));
    }

    public function crawl(int $id)
    {
        $website = Website::findOrFail($id);
        CrawlWebsiteJob::dispatch($website);

        return redirect()->back()->with('success', 'Crawl job queued');
    }

    public function evaluate(int $id)
    {
        $website = Website::findOrFail($id);
        EvaluateWebsiteRequirementsJob::dispatch($website);

        return redirect()->back()->with('success', 'Evaluation job queued');
    }

    public function destroy(int $id)
    {
        $website = Website::findOrFail($id);
        $website->delete();

        return redirect()->route('websites.index')->with('success', 'Website deleted');
    }
}
```

---

### 1.3 SMTP Credentials Controller

**File:** `app/Http/Controllers/SmtpCredentialsController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\SmtpCredential;
use Illuminate\Http\Request;

class SmtpCredentialsController extends Controller
{
    public function index()
    {
        $credentials = SmtpCredential::withTrashed()->get();

        return view('smtp.index', compact('credentials'));
    }

    public function create()
    {
        return view('smtp.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:smtp_credentials',
            'host' => 'required',
            'port' => 'required|integer',
            'encryption' => 'required|in:tls,ssl',
            'username' => 'required',
            'password' => 'required',
            'from_address' => 'required|email',
            'from_name' => 'required',
            'daily_limit' => 'required|integer|min:1',
        ]);

        SmtpCredential::create($validated);

        return redirect()->route('smtp.index')->with('success', 'SMTP account created');
    }

    public function edit(int $id)
    {
        $smtp = SmtpCredential::findOrFail($id);

        return view('smtp.edit', compact('smtp'));
    }

    public function update(Request $request, int $id)
    {
        $smtp = SmtpCredential::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|unique:smtp_credentials,name,' . $id,
            'host' => 'required',
            'port' => 'required|integer',
            'encryption' => 'required|in:tls,ssl',
            'username' => 'required',
            'from_address' => 'required|email',
            'from_name' => 'required',
            'daily_limit' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        // Only update password if provided
        if ($request->filled('password')) {
            $validated['password'] = $request->input('password');
        }

        $smtp->update($validated);

        return redirect()->route('smtp.index')->with('success', 'SMTP account updated');
    }

    public function destroy(int $id)
    {
        $smtp = SmtpCredential::findOrFail($id);
        $smtp->delete();

        return redirect()->route('smtp.index')->with('success', 'SMTP account deleted');
    }

    public function resetCounter(int $id)
    {
        $smtp = SmtpCredential::findOrFail($id);
        $smtp->update([
            'emails_sent_today' => 0,
            'last_reset_date' => today(),
        ]);

        return redirect()->back()->with('success', 'Counter reset');
    }
}
```

---

### 1.4 Email Templates Controller

**File:** `app/Http/Controllers/EmailTemplatesController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;

class EmailTemplatesController extends Controller
{
    public function index()
    {
        $templates = EmailTemplate::withTrashed()->orderBy('created_at', 'desc')->get();

        return view('templates.index', compact('templates'));
    }

    public function create()
    {
        $variables = EmailTemplate::getDefaultVariables();

        return view('templates.create', compact('variables'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:email_templates',
            'description' => 'nullable',
            'subject_template' => 'required',
            'body_template' => 'required',
            'preheader' => 'nullable',
            'ai_enabled' => 'boolean',
            'ai_instructions' => 'nullable',
            'ai_tone' => 'nullable|in:professional,friendly,casual,formal',
            'ai_max_tokens' => 'nullable|integer|min:100|max:2000',
            'is_active' => 'boolean',
        ]);

        EmailTemplate::create($validated);

        return redirect()->route('templates.index')->with('success', 'Template created');
    }

    public function edit(int $id)
    {
        $template = EmailTemplate::findOrFail($id);
        $variables = EmailTemplate::getDefaultVariables();

        return view('templates.edit', compact('template', 'variables'));
    }

    public function update(Request $request, int $id)
    {
        $template = EmailTemplate::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|unique:email_templates,name,' . $id,
            'description' => 'nullable',
            'subject_template' => 'required',
            'body_template' => 'required',
            'preheader' => 'nullable',
            'ai_enabled' => 'boolean',
            'ai_instructions' => 'nullable',
            'ai_tone' => 'nullable|in:professional,friendly,casual,formal',
            'ai_max_tokens' => 'nullable|integer|min:100|max:2000',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return redirect()->route('templates.index')->with('success', 'Template updated');
    }

    public function preview(Request $request, int $id)
    {
        $template = EmailTemplate::findOrFail($id);
        $service = new EmailTemplateService(app(MistralAIService::class));

        $preview = $service->preview($template);

        return response()->json($preview);
    }

    public function destroy(int $id)
    {
        $template = EmailTemplate::findOrFail($id);
        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Template deleted');
    }
}
```

---

### 1.5 Blacklist Controller

**File:** `app/Http/Controllers/BlacklistController.php`

```php
<?php

namespace App\Http/Controllers;

use App\Models\BlacklistEntry;
use App\Services\BlacklistService;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    protected BlacklistService $blacklistService;

    public function __construct(BlacklistService $blacklistService)
    {
        $this->blacklistService = $blacklistService;
    }

    public function index()
    {
        $domains = BlacklistEntry::domains()->latest()->paginate(50, ['*'], 'domains');
        $emails = BlacklistEntry::emails()->latest()->paginate(50, ['*'], 'emails');

        return view('blacklist.index', compact('domains', 'emails'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:domain,email',
            'value' => 'required',
            'reason' => 'nullable',
        ]);

        if ($validated['type'] === 'domain') {
            $this->blacklistService->addDomain($validated['value'], $validated['reason'] ?? null, auth()->id());
        } else {
            $this->blacklistService->addEmail($validated['value'], $validated['reason'] ?? null, auth()->id());
        }

        return redirect()->back()->with('success', 'Entry added to blacklist');
    }

    public function destroy(int $id)
    {
        $this->blacklistService->remove($id);

        return redirect()->back()->with('success', 'Entry removed from blacklist');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:txt,csv',
            'type' => 'required|in:domain,email',
        ]);

        $file = $request->file('file');
        $entries = array_map('trim', file($file->getRealPath(), FILE_IGNORE_NEW_LINES));

        $result = $this->blacklistService->bulkImport($entries, $request->input('type'), auth()->id());

        return redirect()->back()->with('success', "Imported {$result['imported']} entries");
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'domain');
        $entries = $this->blacklistService->export($type);

        $filename = "blacklist_{$type}_" . now()->format('Y-m-d') . '.txt';

        return response()
            ->streamDownload(function () use ($entries) {
                echo implode("\n", $entries);
            }, $filename);
    }
}
```

---

### 1.6 Reports Controller

**File:** `app/Http/Controllers/ReportsController.php`

```php
<?php

namespace App\Http/Controllers;

use App\Models\EmailSentLog;
use App\Models\SmtpCredential;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index()
    {
        return view('reports.index');
    }

    public function emailActivity(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        $emails = EmailSentLog::with(['contact', 'website', 'smtpCredential'])
            ->whereBetween('sent_at', [$startDate, $endDate])
            ->orderByDesc('sent_at')
            ->get();

        $stats = [
            'total' => $emails->count(),
            'successful' => $emails->where('status', EmailSentLog::STATUS_SENT)->count(),
            'failed' => $emails->where('status', EmailSentLog::STATUS_FAILED)->count(),
            'by_smtp' => $emails->groupBy('smtp_credential_id')->map->count(),
        ];

        return view('reports.email-activity', compact('emails', 'stats', 'startDate', 'endDate'));
    }

    public function leadQuality(Request $request)
    {
        $qualifiedLeads = Website::qualifiedLeads()
            ->with('domain')
            ->get();

        $platformBreakdown = $qualifiedLeads->groupBy('detected_platform')->map->count();
        $avgPageCount = $qualifiedLeads->avg('page_count');
        $avgWordCount = $qualifiedLeads->avg('word_count');

        return view('reports.lead-quality', compact(
            'qualifiedLeads',
            'platformBreakdown',
            'avgPageCount',
            'avgWordCount'
        ));
    }

    public function smtpPerformance()
    {
        $smtpAccounts = SmtpCredential::withTrashed()->get();

        $performance = $smtpAccounts->map(function ($smtp) {
            $total = $smtp->success_count + $smtp->failure_count;
            $successRate = $total > 0 ? ($smtp->success_count / $total) * 100 : 0;

            return [
                'name' => $smtp->name,
                'success_count' => $smtp->success_count,
                'failure_count' => $smtp->failure_count,
                'success_rate' => round($successRate, 2),
                'daily_usage' => $smtp->emails_sent_today,
                'daily_limit' => $smtp->daily_limit,
                'is_active' => $smtp->is_active,
            ];
        });

        return view('reports.smtp-performance', compact('performance'));
    }
}
```

---

## 2. Routes

**File:** `routes/web.php`

```php
use App\Http\Controllers\{
    DashboardController,
    WebsitesController,
    SmtpCredentialsController,
    EmailTemplatesController,
    BlacklistController,
    ReportsController,
    ReviewQueueController
};

Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Websites
    Route::resource('websites', WebsitesController::class)->only(['index', 'show', 'destroy']);
    Route::post('websites/{id}/crawl', [WebsitesController::class, 'crawl'])->name('websites.crawl');
    Route::post('websites/{id}/evaluate', [WebsitesController::class, 'evaluate'])->name('websites.evaluate');

    // SMTP Credentials
    Route::resource('smtp', SmtpCredentialsController::class);
    Route::post('smtp/{id}/reset-counter', [SmtpCredentialsController::class, 'resetCounter'])->name('smtp.reset-counter');

    // Email Templates
    Route::resource('templates', EmailTemplatesController::class);
    Route::get('templates/{id}/preview', [EmailTemplatesController::class, 'preview'])->name('templates.preview');

    // Blacklist
    Route::resource('blacklist', BlacklistController::class)->only(['index', 'store', 'destroy']);
    Route::post('blacklist/import', [BlacklistController::class, 'import'])->name('blacklist.import');
    Route::get('blacklist/export', [BlacklistController::class, 'export'])->name('blacklist.export');

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportsController::class, 'index'])->name('index');
        Route::get('/email-activity', [ReportsController::class, 'emailActivity'])->name('email-activity');
        Route::get('/lead-quality', [ReportsController::class, 'leadQuality'])->name('lead-quality');
        Route::get('/smtp-performance', [ReportsController::class, 'smtpPerformance'])->name('smtp-performance');
    });

    // Review Queue (from Step 9)
    Route::resource('review-queue', ReviewQueueController::class)->only(['index', 'show']);
    Route::post('review-queue/{id}/approve', [ReviewQueueController::class, 'approve'])->name('review-queue.approve');
    Route::post('review-queue/{id}/reject', [ReviewQueueController::class, 'reject'])->name('review-queue.reject');
});
```

---

## 3. Views Structure

```
resources/views/
├── layouts/
│   └── app.blade.php           # Main layout
├── dashboard/
│   └── index.blade.php         # Dashboard home
├── websites/
│   ├── index.blade.php         # List websites
│   └── show.blade.php          # Website details
├── smtp/
│   ├── index.blade.php         # List SMTP accounts
│   ├── create.blade.php        # Add SMTP account
│   └── edit.blade.php          # Edit SMTP account
├── templates/
│   ├── index.blade.php         # List templates
│   ├── create.blade.php        # Create template
│   └── edit.blade.php          # Edit template
├── blacklist/
│   └── index.blade.php         # Manage blacklist
├── reports/
│   ├── index.blade.php         # Reports home
│   ├── email-activity.blade.php
│   ├── lead-quality.blade.php
│   └── smtp-performance.blade.php
└── review-queue/
    ├── index.blade.php         # Pending reviews
    └── show.blade.php          # Review single email
```

---

## 4. Example Dashboard View

**File:** `resources/views/dashboard/index.blade.php`

```blade
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Dashboard</h1>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Domains</h3>
            <p class="stat-number">{{ number_format($stats['total_domains']) }}</p>
        </div>
        <div class="stat-card">
            <h3>Qualified Leads</h3>
            <p class="stat-number">{{ number_format($stats['qualified_leads']) }}</p>
        </div>
        <div class="stat-card">
            <h3>Emails Today</h3>
            <p class="stat-number">{{ number_format($stats['emails_today']) }}</p>
        </div>
        <div class="stat-card">
            <h3>Pending Reviews</h3>
            <p class="stat-number">{{ number_format($stats['pending_reviews']) }}</p>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <div class="chart-card">
            <h3>Emails Last 30 Days</h3>
            <canvas id="emails-chart"></canvas>
        </div>
        <div class="chart-card">
            <h3>Leads by Platform</h3>
            <canvas id="platforms-chart"></canvas>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="activity-section">
        <h2>Recent Emails</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Website</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Sent At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentEmails as $email)
                <tr>
                    <td>{{ $email->recipient_email }}</td>
                    <td>{{ $email->website->url }}</td>
                    <td>{{ $email->subject }}</td>
                    <td><span class="badge badge-{{ $email->status }}">{{ $email->status }}</span></td>
                    <td>{{ $email->sent_at->diffForHumans() }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Emails chart
new Chart(document.getElementById('emails-chart'), {
    type: 'line',
    data: {
        labels: @json(array_column($emailsLast30Days, 'date')),
        datasets: [{
            label: 'Emails Sent',
            data: @json(array_column($emailsLast30Days, 'count')),
            borderColor: 'rgb(75, 192, 192)',
            tension: 0.1
        }]
    }
});

// Platforms chart
new Chart(document.getElementById('platforms-chart'), {
    type: 'pie',
    data: {
        labels: @json(array_keys($leadsByPlatform)),
        datasets: [{
            data: @json(array_values($leadsByPlatform)),
            backgroundColor: [
                'rgb(255, 99, 132)',
                'rgb(54, 162, 235)',
                'rgb(255, 205, 86)',
                'rgb(75, 192, 192)',
                'rgb(153, 102, 255)',
            ]
        }]
    }
});
</script>
@endsection
```

---

## 5. Implementation Checklist

- [ ] Create all controllers
- [ ] Define routes
- [ ] Create base layout
- [ ] Create dashboard view
- [ ] Create CRUD views for websites
- [ ] Create CRUD views for SMTP
- [ ] Create CRUD views for templates
- [ ] Create blacklist management UI
- [ ] Create reports pages
- [ ] Add charts (Chart.js)
- [ ] Add search/filters
- [ ] Add export functionality
- [ ] Add pagination
- [ ] Add flash messages
- [ ] Test all CRUD operations
- [ ] Add responsive CSS

---

## 6. Optional Enhancements

### Real-Time Updates (Laravel Echo)
```bash
composer require pusher/pusher-php-server
npm install --save laravel-echo pusher-js
```

### Advanced Filtering (Laravel Query Builder)
```bash
composer require spatie/laravel-query-builder
```

### Data Tables
```html
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.css" />
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.js"></script>
```

---

## Conclusion

**Estimated Time:** 10-12 hours
**Priority:** HIGH - User interface for entire system
**Risk Level:** LOW - Standard CRUD operations
**Completion:** Marks full MVP completion!

---

## Final System Overview

After completing all 10 steps, you will have:

✓ Database foundation with millions-scale capacity
✓ Web crawling with platform detection
✓ Contact extraction and validation
✓ Intelligent requirements matching
✓ Duplicate prevention system
✓ AI-powered email personalization
✓ Rate-limited email sending
✓ Blacklist management
✓ Review queue for quality control
✓ Comprehensive admin dashboard

**Total Estimated Time:** 49-64 hours (6-8 working days)
**Result:** Production-ready automated outreach platform
