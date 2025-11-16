<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\WebsiteRequirement;
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

    public $timeout = 300;
    public $tries = 1;

    public function __construct(
        public Domain $domain
    ) {}

    public function handle(WebCrawlerService $crawler): void
    {
        try {
            $maxPages = WebsiteRequirement::calculateRequiredMaxPages();

            Log::info('Starting domain crawl', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'max_pages' => $maxPages,
            ]);

            $html = $crawler->crawl($this->domain, $maxPages);

            $pageCount = substr_count($html, '<!-- PAGE_SEPARATOR -->') + 1;
            $textContent = strip_tags($html);
            $wordCount = str_word_count($textContent);

            Log::info('Domain crawled successfully', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'pages_found' => $pageCount,
                'total_words' => $wordCount,
                'html_size_kb' => round(strlen($html) / 1024, 2),
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
