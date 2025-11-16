<?php

namespace App\Services;

use App\Models\Domain;
use App\Services\Crawler\ContentCrawlObserver;
use App\Services\Crawler\InternalLinksCrawlProfile;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;

class WebCrawlerService
{
    public function crawl(Domain $domain, int $maxPages = 10): string
    {
        $url = 'https://' . $domain->domain;

        Log::info('Starting Spatie Crawler crawl', [
            'domain_id' => $domain->id,
            'domain' => $domain->domain,
            'max_pages' => $maxPages,
        ]);

        $observer = new ContentCrawlObserver($maxPages);
        $profile = new InternalLinksCrawlProfile($url, $maxPages);

        Crawler::create()
            ->setCrawlObserver($observer)
            ->setCrawlProfile($profile)
            ->setMaximumDepth(2)
            ->setConcurrency(5)
            ->setTotalCrawlLimit($maxPages)
            ->setDelayBetweenRequests(100)
            ->ignoreRobots()
            ->acceptNofollowLinks()
            ->setUserAgent('Mozilla/5.0 (compatible; LeadMailerBot/1.0)')
            ->startCrawling($url);

        Log::info('Crawl completed', [
            'domain_id' => $domain->id,
            'pages_crawled' => $observer->getPageCount(),
        ]);

        $pages = $observer->getPages();

        if (empty($pages)) {
            throw new \Exception('Failed to crawl any pages from domain');
        }

        $concatenated = implode("\n\n<!-- PAGE_SEPARATOR -->\n\n", $pages);

        return mb_convert_encoding($concatenated, 'UTF-8', 'UTF-8');
    }
}
