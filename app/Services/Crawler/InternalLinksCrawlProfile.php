<?php

namespace App\Services\Crawler;

use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlProfile;

class InternalLinksCrawlProfile extends CrawlProfile
{
    protected string $baseHost;
    protected int $maxPages;
    protected int $crawledCount = 0;

    public function __construct(string $baseUrl, int $maxPages = 10)
    {
        $this->baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $this->maxPages = $maxPages;
    }

    public function shouldCrawl(UriInterface $url): bool
    {
        if ($this->crawledCount >= $this->maxPages) {
            return false;
        }

        $urlHost = $url->getHost();

        if ($urlHost !== $this->baseHost && $urlHost !== 'www.' . $this->baseHost && 'www.' . $urlHost !== $this->baseHost) {
            return false;
        }

        $path = $url->getPath();
        $skipExtensions = ['.pdf', '.jpg', '.jpeg', '.png', '.gif', '.svg', '.css', '.js', '.zip', '.doc', '.docx', '.xls', '.xlsx'];
        foreach ($skipExtensions as $ext) {
            if (str_ends_with(strtolower($path), $ext)) {
                return false;
            }
        }

        $this->crawledCount++;

        return true;
    }

    public function resetCount(): void
    {
        $this->crawledCount = 0;
    }
}
