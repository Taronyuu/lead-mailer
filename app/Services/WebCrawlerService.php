<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebCrawlerService
{
    protected array $visitedUrls = [];
    protected array $htmlPages = [];
    protected int $maxPages = 10;
    protected int $timeout = 10;

    public function crawl(Domain $domain, int $maxPages = 10): string
    {
        $this->maxPages = $maxPages;
        $this->visitedUrls = [];
        $this->htmlPages = [];

        $baseUrl = 'https://' . $domain->domain;

        Log::info('Starting recursive crawl', [
            'domain_id' => $domain->id,
            'domain' => $domain->domain,
            'max_pages' => $maxPages,
        ]);

        $this->crawlUrl($baseUrl, $domain->domain);

        Log::info('Recursive crawl completed', [
            'domain_id' => $domain->id,
            'domain' => $domain->domain,
            'pages_crawled' => count($this->htmlPages),
        ]);

        if (empty($this->htmlPages)) {
            throw new \Exception('Failed to crawl any pages from domain');
        }

        $concatenated = implode("\n\n<!-- PAGE_SEPARATOR -->\n\n", $this->htmlPages);

        return mb_convert_encoding($concatenated, 'UTF-8', 'UTF-8');
    }

    protected function crawlUrl(string $url, string $baseDomain): void
    {
        if (count($this->htmlPages) >= $this->maxPages) {
            return;
        }

        if (in_array($url, $this->visitedUrls)) {
            return;
        }

        $this->visitedUrls[] = $url;

        Log::debug('Crawling URL', ['url' => $url]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; LeadBot/1.0)',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::debug('Failed to fetch URL', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return;
            }

            $html = $response->body();
            $this->htmlPages[] = $html;

            $links = $this->extractLinks($html, $url, $baseDomain);

            Log::debug('Extracted links from page', [
                'url' => $url,
                'links_found' => count($links),
                'links' => $links,
            ]);

            foreach ($links as $link) {
                if (count($this->htmlPages) >= $this->maxPages) {
                    break;
                }
                $this->crawlUrl($link, $baseDomain);
            }

        } catch (\Exception $e) {
            Log::debug('Error crawling URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function extractLinks(string $html, string $currentUrl, string $baseDomain): array
    {
        $links = [];

        preg_match_all('/<a\s+[^>]*href=["\'](.*?)["\'][^>]*>/i', $html, $matches);

        foreach ($matches[1] as $link) {
            $link = trim($link);

            if (empty($link) || $link === '#' || str_starts_with($link, '#')) {
                continue;
            }

            if (str_starts_with($link, 'mailto:') || str_starts_with($link, 'tel:') || str_starts_with($link, 'javascript:')) {
                continue;
            }

            $absoluteUrl = $this->makeAbsoluteUrl($link, $currentUrl);

            if (!$this->isSameDomain($absoluteUrl, $baseDomain)) {
                continue;
            }

            $absoluteUrl = $this->normalizeUrl($absoluteUrl);

            if (!in_array($absoluteUrl, $links) && !in_array($absoluteUrl, $this->visitedUrls)) {
                $links[] = $absoluteUrl;
            }
        }

        return $links;
    }

    protected function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }

        $basePath = $parsedBase['path'] ?? '/';
        $basePath = dirname($basePath);
        if ($basePath === '.') {
            $basePath = '';
        }

        return $scheme . '://' . $host . $basePath . '/' . $url;
    }

    protected function isSameDomain(string $url, string $baseDomain): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        return $host === $baseDomain || $host === 'www.' . $baseDomain || 'www.' . $host === $baseDomain;
    }

    protected function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        $normalized = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['path'])) {
            $normalized .= rtrim($parsed['path'], '/');
        }

        return $normalized;
    }
}
