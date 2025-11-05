<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\RequirementsMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateWebsiteRequirementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Website $website
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RequirementsMatcherService $matcher): void
    {
        try {
            Log::info('Evaluating website requirements', [
                'website_id' => $this->website->id,
                'url' => $this->website->url,
            ]);

            $results = $matcher->evaluateWebsite($this->website);

            $this->website->refresh();

            Log::info('Website requirements evaluation completed', [
                'website_id' => $this->website->id,
                'meets_requirements' => $this->website->meets_requirements,
                'evaluation_count' => count($results),
            ]);

        } catch (\Exception $e) {
            Log::error('Website requirements evaluation failed', [
                'website_id' => $this->website->id,
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
        Log::error('Website requirements evaluation job failed permanently', [
            'website_id' => $this->website->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
