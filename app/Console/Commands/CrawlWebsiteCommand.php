<?php

namespace App\Console\Commands;

use App\Jobs\CrawlDomainJob;
use App\Models\Domain;
use Illuminate\Console\Command;

class CrawlWebsiteCommand extends Command
{
    protected $signature = 'website:crawl
                            {--queue : Queue the crawl job instead of running immediately}
                            {--limit=100 : Number of random domains to crawl}
                            {--debug : Show detailed output}';

    protected $description = 'Crawl random domains';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $verbose = $this->option('debug');

        $domains = Domain::query()
            ->whereDoesntHave('websites')
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        if ($domains->isEmpty()) {
            $this->warn('No domains found');
            return self::SUCCESS;
        }

        $this->info("Found {$domains->count()} domains to crawl");

        if ($this->option('queue')) {
            foreach ($domains as $domain) {
                CrawlDomainJob::dispatch($domain);
                if ($verbose) {
                    $this->line("Queued: {$domain->domain} (ID: {$domain->id})");
                }
            }
            $this->info("Queued {$domains->count()} crawl jobs. Run: php artisan queue:work");
        } else {
            $successCount = 0;
            $failCount = 0;
            $deletedCount = 0;

            $bar = $verbose ? null : $this->output->createProgressBar($domains->count());
            if ($bar) $bar->start();

            foreach ($domains as $domain) {
                try {
                    if ($verbose) {
                        $this->info("Crawling: {$domain->domain} (ID: {$domain->id})");
                    }

                    $job = new CrawlDomainJob($domain);
                    $job->handle(app(\App\Services\WebCrawlerService::class));
                    if ($domain->exists) {
                        $successCount++;
                        if ($verbose) {
                            $this->line("<info>✓</info> Success: {$domain->domain}");
                        }
                    } else {
                        $deletedCount++;
                        if ($verbose) {
                            $this->line("<comment>✗</comment> Deleted (crawl failed): {$domain->domain}");
                        }
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    if ($verbose) {
                        $this->line("<error>✗</error> Error: {$domain->domain} - {$e->getMessage()}");
                    } else {
                        $this->line('');
                        $this->error("Error crawling {$domain->domain}: {$e->getMessage()}");
                    }
                }
                if ($bar) $bar->advance();
            }

            if ($bar) {
                $bar->finish();
                $this->line('');
            }

            $this->info("Crawl batch completed!");
            $this->line("Success: {$successCount} | Deleted: {$deletedCount} | Errors: {$failCount}");
        }

        return self::SUCCESS;
    }
}
