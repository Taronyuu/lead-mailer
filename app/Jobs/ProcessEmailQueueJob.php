<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmailQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $batchSize = 50
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Processing email queue batch', [
                'batch_size' => $this->batchSize,
            ]);

            // Get default active template
            $template = EmailTemplate::where('is_active', true)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$template) {
                Log::warning('No active email template found');
                return;
            }

            // Get uncontacted valid contacts from qualified websites
            $contacts = Contact::whereHas('website', function ($query) {
                    $query->where('meets_requirements', true);
                })
                ->where('is_validated', true)
                ->where('is_valid', true)
                ->where('contacted', false)
                ->orderBy('priority', 'desc')
                ->limit($this->batchSize)
                ->get();

            Log::info('Found uncontacted contacts for outreach', [
                'count' => $contacts->count(),
            ]);

            $queued = 0;

            foreach ($contacts as $contact) {
                // Dispatch individual send job
                SendOutreachEmailJob::dispatch($contact, $template)
                    ->delay(now()->addMinutes($queued * 2)); // Stagger sends

                $queued++;
            }

            Log::info('Email queue batch processed', [
                'contacts_processed' => $contacts->count(),
                'emails_queued' => $queued,
            ]);

        } catch (\Exception $e) {
            Log::error('Email queue processing failed', [
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
        Log::error('Email queue processing job failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
