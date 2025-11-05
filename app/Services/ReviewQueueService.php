<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailReviewQueue;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use Illuminate\Support\Facades\Log;

class ReviewQueueService
{
    protected EmailTemplateService $templateService;
    protected EmailSendingService $emailService;

    public function __construct(
        EmailTemplateService $templateService,
        EmailSendingService $emailService
    ) {
        $this->templateService = $templateService;
        $this->emailService = $emailService;
    }

    /**
     * Create a review queue entry
     */
    public function createReviewEntry(
        Contact $contact,
        EmailTemplate $template,
        ?SmtpCredential $smtp = null,
        int $priority = 50,
        ?string $notes = null
    ): EmailReviewQueue {
        // Generate email content
        $email = $this->templateService->render($template, $contact->website, $contact);

        // Create review queue entry
        $entry = EmailReviewQueue::create([
            'website_id' => $contact->website_id,
            'contact_id' => $contact->id,
            'email_template_id' => $template->id,
            'smtp_credential_id' => $smtp?->id,
            'generated_subject' => $email['subject'],
            'generated_body' => $email['body'],
            'generated_preheader' => $email['preheader'] ?? null,
            'status' => EmailReviewQueue::STATUS_PENDING,
            'priority' => $priority,
            'notes' => $notes,
        ]);

        Log::info('Review queue entry created', [
            'entry_id' => $entry->id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
        ]);

        return $entry;
    }

    /**
     * Approve a review queue entry
     */
    public function approve(
        EmailReviewQueue $entry,
        ?string $reviewerNotes = null,
        ?array $modifications = []
    ): EmailReviewQueue {
        // Apply modifications if provided
        if (!empty($modifications)) {
            $updateData = [];

            if (isset($modifications['subject'])) {
                $updateData['generated_subject'] = $modifications['subject'];
            }

            if (isset($modifications['body'])) {
                $updateData['generated_body'] = $modifications['body'];
            }

            if (isset($modifications['preheader'])) {
                $updateData['generated_preheader'] = $modifications['preheader'];
            }

            if (!empty($updateData)) {
                $entry->update($updateData);
            }
        }

        // Mark as approved
        $entry->update([
            'status' => EmailReviewQueue::STATUS_APPROVED,
            'reviewed_at' => now(),
            'review_notes' => $reviewerNotes,
        ]);

        Log::info('Review queue entry approved', [
            'entry_id' => $entry->id,
            'contact_id' => $entry->contact_id,
            'has_modifications' => !empty($modifications),
        ]);

        return $entry->fresh();
    }

    /**
     * Reject a review queue entry
     */
    public function reject(
        EmailReviewQueue $entry,
        ?string $reason = null
    ): EmailReviewQueue {
        $entry->update([
            'status' => EmailReviewQueue::STATUS_REJECTED,
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ]);

        Log::info('Review queue entry rejected', [
            'entry_id' => $entry->id,
            'contact_id' => $entry->contact_id,
            'reason' => $reason,
        ]);

        return $entry->fresh();
    }

    /**
     * Send an approved email from the review queue
     */
    public function sendApproved(EmailReviewQueue $entry): array
    {
        if ($entry->status !== EmailReviewQueue::STATUS_APPROVED) {
            return [
                'success' => false,
                'error' => 'Entry is not approved',
            ];
        }

        if ($entry->status === EmailReviewQueue::STATUS_SENT) {
            return [
                'success' => false,
                'error' => 'Entry has already been sent',
            ];
        }

        Log::info('ReviewQueueService: sendApproved called', [
            'entry_id' => $entry->id,
            'status' => $entry->status,
        ]);

        $contact = $entry->contact;
        Log::info('ReviewQueueService: Contact loaded', [
            'entry_id' => $entry->id,
            'contact_id' => $contact->id,
        ]);

        $website = $entry->website;
        Log::info('ReviewQueueService: Website loaded', [
            'entry_id' => $entry->id,
            'website_id' => $website->id ?? 'NULL',
        ]);

        $template = $entry->emailTemplate;
        $smtp = $entry->smtpCredential;

        Log::info('ReviewQueueService: All relationships loaded, calling sendFromReviewQueue', [
            'entry_id' => $entry->id,
        ]);

        try {
            $result = $this->emailService->sendFromReviewQueue(
                $contact,
                $website,
                $template,
                $smtp,
                $entry->generated_subject,
                $entry->generated_body,
                $entry->generated_preheader
            );

            if ($result['success']) {
                $entry->update([
                    'status' => EmailReviewQueue::STATUS_SENT,
                ]);

                Log::info('Approved email sent successfully', [
                    'entry_id' => $entry->id,
                    'log_id' => $result['log_id'],
                ]);

                return [
                    'success' => true,
                    'entry_id' => $entry->id,
                    'log_id' => $result['log_id'],
                ];
            } else {
                $entry->update([
                    'status' => EmailReviewQueue::STATUS_FAILED,
                ]);

                return [
                    'success' => false,
                    'error' => $result['error'],
                ];
            }
        } catch (\Exception $e) {
            $entry->update([
                'status' => EmailReviewQueue::STATUS_FAILED,
            ]);

            Log::error('Failed to send approved email', [
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Bulk approve entries
     */
    public function bulkApprove(
        array $entryIds,
        ?string $reviewerNotes = null
    ): array {
        $results = [
            'approved' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($entryIds as $entryId) {
            try {
                $entry = EmailReviewQueue::findOrFail($entryId);
                $this->approve($entry, $reviewerNotes);
                $results['approved']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Entry {$entryId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Bulk reject entries
     */
    public function bulkReject(
        array $entryIds,
        ?string $reason = null
    ): array {
        $results = [
            'rejected' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($entryIds as $entryId) {
            try {
                $entry = EmailReviewQueue::findOrFail($entryId);
                $this->reject($entry, $reason);
                $results['rejected']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Entry {$entryId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Process approved emails (send them)
     */
    public function processApprovedQueue(int $limit = 10): array
    {
        $entries = EmailReviewQueue::where('status', EmailReviewQueue::STATUS_APPROVED)
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        $results = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($entries as $entry) {
            $results['processed']++;

            $result = $this->sendApproved($entry);

            if ($result['success']) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Entry {$entry->id}: " . $result['error'];
            }
        }

        return $results;
    }

    /**
     * Get pending review entries
     */
    public function getPendingEntries(
        int $limit = 50,
        ?string $orderBy = 'priority',
        string $direction = 'desc'
    ): \Illuminate\Database\Eloquent\Collection {
        $query = EmailReviewQueue::where('status', EmailReviewQueue::STATUS_PENDING)
            ->with(['contact', 'website', 'emailTemplate']);

        if ($orderBy === 'priority') {
            $query->orderBy('priority', $direction)
                ->orderBy('created_at', 'asc');
        } elseif ($orderBy === 'created_at') {
            $query->orderBy('created_at', $direction);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get approved entries ready to send
     */
    public function getApprovedEntries(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return EmailReviewQueue::where('status', EmailReviewQueue::STATUS_APPROVED)
            ->with(['contact', 'website', 'emailTemplate', 'smtpCredential'])
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics for the review queue
     */
    public function getStatistics(): array
    {
        return [
            'total_entries' => EmailReviewQueue::count(),
            'pending' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_PENDING)->count(),
            'approved' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_APPROVED)->count(),
            'rejected' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_REJECTED)->count(),
            'sent' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_SENT)->count(),
            'failed' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_FAILED)->count(),
            'high_priority' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_PENDING)
                ->where('priority', '>=', 75)
                ->count(),
            'oldest_pending' => EmailReviewQueue::where('status', EmailReviewQueue::STATUS_PENDING)
                ->orderBy('created_at', 'asc')
                ->first()?->created_at,
        ];
    }

    /**
     * Auto-queue emails that need review based on criteria
     */
    public function autoQueueForReview(
        Contact $contact,
        EmailTemplate $template,
        array $criteria = []
    ): ?EmailReviewQueue {
        $shouldQueue = false;
        $priority = 50;
        $notes = [];

        // Check if contact is high priority
        if ($contact->priority >= 75) {
            $shouldQueue = true;
            $priority = 75;
            $notes[] = 'High priority contact';
        }

        // Check if website is newly qualified
        if ($contact->website && !$contact->website->contacted) {
            $shouldQueue = true;
            $priority = max($priority, 60);
            $notes[] = 'First contact to this website';
        }

        // Check if AI-generated content
        if ($template->ai_enabled) {
            $shouldQueue = true;
            $priority = max($priority, 70);
            $notes[] = 'AI-generated content requires review';
        }

        // Custom criteria
        if (isset($criteria['force_review']) && $criteria['force_review']) {
            $shouldQueue = true;
            if (isset($criteria['priority'])) {
                $priority = $criteria['priority'];
            }
            if (isset($criteria['notes'])) {
                $notes[] = $criteria['notes'];
            }
        }

        if ($shouldQueue) {
            return $this->createReviewEntry(
                $contact,
                $template,
                null,
                $priority,
                implode('; ', $notes)
            );
        }

        return null;
    }

    /**
     * Requeue a rejected or failed entry
     */
    public function requeue(EmailReviewQueue $entry): EmailReviewQueue
    {
        $entry->update([
            'status' => EmailReviewQueue::STATUS_PENDING,
            'reviewed_at' => null,
        ]);

        Log::info('Review queue entry requeued', [
            'entry_id' => $entry->id,
        ]);

        return $entry->fresh();
    }

    /**
     * Update priority of an entry
     */
    public function updatePriority(EmailReviewQueue $entry, int $priority): EmailReviewQueue
    {
        $entry->update(['priority' => $priority]);

        return $entry->fresh();
    }

    /**
     * Delete old entries (cleanup)
     */
    public function cleanupOldEntries(int $daysOld = 90): int
    {
        $deleted = EmailReviewQueue::whereIn('status', [
            EmailReviewQueue::STATUS_REJECTED,
            EmailReviewQueue::STATUS_SENT,
            EmailReviewQueue::STATUS_FAILED,
        ])
            ->where('created_at', '<', now()->subDays($daysOld))
            ->delete();

        Log::info('Cleaned up old review queue entries', [
            'deleted_count' => $deleted,
            'days_old' => $daysOld,
        ]);

        return $deleted;
    }
}
