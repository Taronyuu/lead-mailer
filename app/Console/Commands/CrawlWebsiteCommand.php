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
                        $this->info("═══════════════════════════════════════════");
                        $this->info("Crawling: {$domain->domain} (ID: {$domain->id})");
                        $this->info("═══════════════════════════════════════════");
                    }

                    $job = new CrawlDomainJob($domain);
                    $job->handle(app(\App\Services\WebCrawlerService::class));
                    if ($domain->exists) {
                        $successCount++;
                        if ($verbose) {
                            $domain->refresh();
                            $this->line("<info>✓</info> Success: {$domain->domain}");

                            $websites = $domain->websites()->withPivot(['page_count', 'word_count', 'detected_platform', 'matches'])->get();
                            if ($websites->isNotEmpty()) {
                                $this->line("");
                                $this->line("<fg=cyan>Website Evaluation Results:</>");
                                foreach ($websites as $website) {
                                    $matches = $website->pivot->matches ? '<fg=green>✓ MATCH</>' : '<fg=red>✗ NO MATCH</>';
                                    $this->line("  Website: {$website->url} - {$matches}");
                                    $this->line("  - Pages: {$website->pivot->page_count}");
                                    $this->line("  - Words: {$website->pivot->word_count}");
                                    $this->line("  - Platform: " . ($website->pivot->detected_platform ?? 'Unknown'));

                                    $evaluationDetails = $website->pivot->evaluation_details;
                                    if ($evaluationDetails) {
                                        foreach ($evaluationDetails as $detail) {
                                            $reqMatch = $detail['matches'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
                                            $this->line("  - Requirement '{$detail['requirement_name']}': {$reqMatch}");
                                            if (isset($detail['criteria_results'])) {
                                                foreach ($detail['criteria_results'] as $criterion => $result) {
                                                    $critMatch = $result['matched'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                                                    $this->line("    {$critMatch} {$criterion}: {$result['message']}");
                                                }
                                            }
                                        }
                                    }
                                    $this->line("");
                                }
                            }
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
