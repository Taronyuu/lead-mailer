<?php

namespace App\Jobs;

use App\Models\EmailReviewQueue;
use App\Services\ReviewQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessApprovedEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $batchSize = 10
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ReviewQueueService $reviewService): void
    {
        try {
            Log::info('Processing approved emails batch', [
                'batch_size' => $this->batchSize,
            ]);

            $results = $reviewService->processApprovedQueue($this->batchSize);

            Log::info('Approved emails batch processed', [
                'processed' => $results['processed'],
                'sent' => $results['sent'],
                'failed' => $results['failed'],
            ]);

            if ($results['failed'] > 0) {
                Log::warning('Some approved emails failed to send', [
                    'errors' => $results['errors'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Approved emails processing failed', [
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
        Log::error('Approved emails processing job failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
