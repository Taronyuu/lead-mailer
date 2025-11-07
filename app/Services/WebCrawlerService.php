<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebCrawlerService
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected int $timeout;
    protected int $pollInterval;

    public function __construct()
    {
        $this->baseUrl = config('services.firecrawl.base_url', 'https://crawl.meerdevelopment.nl/firecrawl/v1');
        $this->username = config('services.firecrawl.username', 'admin');
        $this->password = config('services.firecrawl.password', 'mounted-fascism-outsell-equivocal-spokesman-scarf');
        $this->timeout = (int) config('services.firecrawl.timeout', 300);
        $this->pollInterval = (int) config('services.firecrawl.poll_interval', 10);
    }

    public function crawl(Domain $domain, int $maxPages = 10): string
    {
        $url = 'https://' . $domain->domain;

        Log::info('Starting Firecrawl crawl', [
            'domain_id' => $domain->id,
            'domain' => $domain->domain,
            'max_pages' => $maxPages,
        ]);

        $crawlId = $this->startCrawl($url, $maxPages);

        Log::info('Crawl started, monitoring progress', [
            'domain_id' => $domain->id,
            'crawl_id' => $crawlId,
        ]);

        $result = $this->pollCrawlStatus($crawlId, $domain);

        Log::info('Crawl completed', [
            'domain_id' => $domain->id,
            'pages_crawled' => count($result),
        ]);

        if (empty($result)) {
            throw new \Exception('Failed to crawl any pages from domain');
        }

        $concatenated = implode("\n\n<!-- PAGE_SEPARATOR -->\n\n", $result);

        return mb_convert_encoding($concatenated, 'UTF-8', 'UTF-8');
    }

    protected function startCrawl(string $url, int $limit): string
    {
        $response = Http::timeout($this->timeout)
            ->withBasicAuth($this->username, $this->password)
            ->post($this->baseUrl . '/crawl', [
                'url' => $url,
                'limit' => $limit,
                'maxDepth' => 2,
                'scrapeOptions' => [
                    'formats' => ['markdown', 'html'],
                    'onlyMainContent' => true,
                    'useFlaresolverr' => true,
                    'timeout' => 60000,
                    'excludeTags' => ['script', 'style', 'nav', 'footer', 'aside', 'iframe'],
                ],
            ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to start crawl: ' . $response->status() . ' - ' . $response->body());
        }

        $data = $response->json();

        if (!isset($data['success']) || !$data['success']) {
            throw new \Exception('Crawl request failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        if (!isset($data['id'])) {
            throw new \Exception('No crawl ID returned from API');
        }

        return $data['id'];
    }

    protected function pollCrawlStatus(string $crawlId, Domain $domain): array
    {
        $startTime = time();
        $maxWaitTime = $this->timeout;

        while (true) {
            if (time() - $startTime > $maxWaitTime) {
                throw new \Exception('Crawl timeout exceeded');
            }

            $response = Http::timeout(30)
                ->withBasicAuth($this->username, $this->password)
                ->get($this->baseUrl . '/crawl/' . $crawlId);

            if (!$response->successful()) {
                throw new \Exception('Failed to check crawl status: ' . $response->status());
            }

            $status = $response->json();

            Log::info('Crawl progress', [
                'domain_id' => $domain->id,
                'status' => $status['status'] ?? 'unknown',
                'completed' => $status['completed'] ?? 0,
                'total' => $status['total'] ?? 0,
            ]);

            if (isset($status['status'])) {
                if ($status['status'] === 'completed') {
                    return $this->extractContent($status);
                }

                if ($status['status'] === 'failed') {
                    throw new \Exception('Crawl failed: ' . ($status['error'] ?? 'Unknown error'));
                }
            }

            sleep($this->pollInterval);
        }
    }

    protected function extractContent(array $status): array
    {
        if (!isset($status['data']) || !is_array($status['data'])) {
            throw new \Exception('No data returned from crawl');
        }

        $pages = [];

        foreach ($status['data'] as $page) {
            if (!isset($page['markdown']) && !isset($page['html'])) {
                continue;
            }

            $content = $page['markdown'] ?? $page['html'] ?? '';

            if (empty(trim($content))) {
                continue;
            }

            $pages[] = $content;
        }

        return $pages;
    }
}
