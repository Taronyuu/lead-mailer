<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\WebCrawlerService;
use Illuminate\Console\Command;

class TestFirecrawlCommand extends Command
{
    protected $signature = 'test:firecrawl {domain=example.com} {--limit=5 : Number of pages to crawl}';

    protected $description = 'Test Firecrawl API integration';

    public function handle(WebCrawlerService $crawler): int
    {
        $domainName = $this->argument('domain');
        $limit = (int) $this->option('limit');

        $this->info("Testing Firecrawl integration with domain: {$domainName}");
        $this->info("Page limit: {$limit}");

        $domain = new Domain();
        $domain->domain = $domainName;
        $domain->id = 999;

        try {
            $this->info('Starting crawl...');

            $result = $crawler->crawl($domain, $limit);

            $pageCount = substr_count($result, '<!-- PAGE_SEPARATOR -->') + 1;
            $wordCount = str_word_count(strip_tags($result));

            $this->info("✓ Crawl completed successfully!");
            $this->line("  Pages crawled: {$pageCount}");
            $this->line("  Total words: {$wordCount}");
            $this->line("  Content size: " . round(strlen($result) / 1024, 2) . " KB");

            if ($this->option('verbose')) {
                $this->line("\nFirst 500 characters of content:");
                $this->line(substr($result, 0, 500) . '...');
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("✗ Crawl failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
