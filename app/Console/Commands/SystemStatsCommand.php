<?php

namespace App\Console\Commands;

use App\Models\BlacklistEntry;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailReviewQueue;
use App\Models\EmailSentLog;
use App\Models\SmtpCredential;
use App\Models\Website;
use App\Services\BlacklistService;
use App\Services\ReviewQueueService;
use Illuminate\Console\Command;

class SystemStatsCommand extends Command
{
    protected $signature = 'system:stats
                            {--json : Output as JSON}';

    protected $description = 'Display system statistics';

    public function handle(
        BlacklistService $blacklistService,
        ReviewQueueService $reviewService
    ): int {
        $stats = $this->gatherStats($blacklistService, $reviewService);

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->displayStats($stats);

        return self::SUCCESS;
    }

    protected function gatherStats(
        BlacklistService $blacklistService,
        ReviewQueueService $reviewService
    ): array {
        return [
            'domains' => [
                'total' => Domain::count(),
            ],
            'websites' => [
                'total' => Website::count(),
                'active' => Website::where('is_active', true)->count(),
                'inactive' => Website::where('is_active', false)->count(),
                'qualified_leads' => Website::where('meets_requirements', true)->count(),
            ],
            'contacts' => [
                'total' => Contact::count(),
                'validated' => Contact::where('is_validated', true)->count(),
                'valid' => Contact::where('is_valid', true)->count(),
                'contacted' => Contact::where('contacted', true)->count(),
                'pending_contact' => Contact::where('is_valid', true)
                    ->where('contacted', false)
                    ->count(),
            ],
            'emails' => [
                'total_sent' => EmailSentLog::count(),
                'sent_success' => EmailSentLog::where('status', EmailSentLog::STATUS_SENT)->count(),
                'sent_failed' => EmailSentLog::where('status', EmailSentLog::STATUS_FAILED)->count(),
                'sent_today' => EmailSentLog::whereDate('sent_at', today())->count(),
                'sent_this_week' => EmailSentLog::where('sent_at', '>=', now()->subWeek())->count(),
            ],
            'review_queue' => $reviewService->getStatistics(),
            'blacklist' => $blacklistService->getStatistics(),
            'smtp' => [
                'total' => SmtpCredential::count(),
                'active' => SmtpCredential::where('is_active', true)->count(),
                'available' => SmtpCredential::available()->count(),
            ],
        ];
    }

    protected function displayStats(array $stats): void
    {
        $this->info('=== System Statistics ===');
        $this->line('');

        $this->line('<fg=cyan>DOMAINS</>');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['domains']['total']],
                ['Pending', $stats['domains']['pending']],
                ['Active', $stats['domains']['active']],
                ['Processed', $stats['domains']['processed']],
                ['Failed', $stats['domains']['failed']],
                ['Blocked', $stats['domains']['blocked']],
            ]
        );

        $this->line('<fg=cyan>WEBSITES</>');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['websites']['total']],
                ['Active', $stats['websites']['active']],
                ['Inactive', $stats['websites']['inactive']],
                ['Qualified Leads', $stats['websites']['qualified_leads']],
            ]
        );

        $this->line('<fg=cyan>CONTACTS</>');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['contacts']['total']],
                ['Validated', $stats['contacts']['validated']],
                ['Valid', $stats['contacts']['valid']],
                ['Contacted', $stats['contacts']['contacted']],
                ['Pending Contact', $stats['contacts']['pending_contact']],
            ]
        );

        $this->line('<fg=cyan>EMAILS</>');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sent', $stats['emails']['total_sent']],
                ['Successful', $stats['emails']['sent_success']],
                ['Failed', $stats['emails']['sent_failed']],
                ['Sent Today', $stats['emails']['sent_today']],
                ['Sent This Week', $stats['emails']['sent_this_week']],
            ]
        );

        $this->line('<fg=cyan>REVIEW QUEUE</>');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['review_queue']['total_entries']],
                ['Pending', $stats['review_queue']['pending']],
                ['Approved', $stats['review_queue']['approved']],
                ['Rejected', $stats['review_queue']['rejected']],
                ['Sent', $stats['review_queue']['sent']],
                ['Failed', $stats['review_queue']['failed']],
            ]
        );

        $this->line('<fg=cyan>BLACKLIST</>');
        $this->table(
            ['Type', 'Count'],
            [
                ['Total Entries', $stats['blacklist']['total_entries']],
                ['Active', $stats['blacklist']['active_entries']],
                ['Email Entries', $stats['blacklist']['email_entries']],
                ['Domain Entries', $stats['blacklist']['domain_entries']],
                ['Auto-Added', $stats['blacklist']['auto_entries']],
            ]
        );

        $this->line('<fg=cyan>SMTP ACCOUNTS</>');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total', $stats['smtp']['total']],
                ['Active', $stats['smtp']['active']],
                ['Available', $stats['smtp']['available']],
            ]
        );
    }
}
