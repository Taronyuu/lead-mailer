<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $backoff = 60; // 1 minute between retries

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Website $website
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WebCrawlerService $crawler): void
    {
        try {
            Log::info('Starting website crawl', [
                'website_id' => $this->website->id,
                'url' => $this->website->url,
            ]);

            $crawler->crawlAndUpdate($this->website);

            $freshWebsite = $this->website->fresh();

            Log::info('Website crawl completed', [
                'website_id' => $this->website->id,
                'is_active' => $freshWebsite->is_active,
            ]);

            if ($freshWebsite->is_active) {
                ExtractContactsJob::dispatch($this->website);
            }

        } catch (\Exception $e) {
            Log::error('Website crawl failed', [
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
        Log::error('Website crawl job failed permanently - deleting website', [
            'website_id' => $this->website->id,
            'url' => $this->website->url,
            'error' => $exception->getMessage(),
        ]);

        $this->website->delete();
    }
}
