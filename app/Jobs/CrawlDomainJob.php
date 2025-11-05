<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CrawlDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;
    public $tries = 1;

    public function __construct(
        public Domain $domain
    ) {}

    public function handle(WebCrawlerService $crawler): void
    {
        try {
            $html = $crawler->crawl($this->domain);

            Log::info('Domain crawled successfully', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
            ]);

            ExtractContactsJob::dispatch($this->domain, $html);
            EvaluateDomainRequirementsJob::dispatch($this->domain, $html);

        } catch (\Exception $e) {
            Log::info('Deleting domain due to crawl error', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'error' => $e->getMessage(),
            ]);

            $this->domain->delete();
        }
    }
}
