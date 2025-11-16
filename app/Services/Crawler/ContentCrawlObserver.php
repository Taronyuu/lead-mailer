<?php

namespace App\Services\Crawler;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class ContentCrawlObserver extends CrawlObserver
{
    protected array $pages = [];
    protected int $maxPages;

    public function __construct(int $maxPages = 10)
    {
        $this->maxPages = $maxPages;
    }

    public function crawled(UriInterface $url, ResponseInterface $response, ?UriInterface $foundOnUrl = null, ?string $linkText = null): void
    {
        if (count($this->pages) >= $this->maxPages) {
            return;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $html = (string) $response->getBody();
        if (empty(trim($html))) {
            return;
        }

        $cleanedHtml = $this->cleanHtml($html);
        if (!empty(trim($cleanedHtml))) {
            $this->pages[] = $cleanedHtml;
        }
    }

    public function crawlFailed(UriInterface $url, RequestException $requestException, ?UriInterface $foundOnUrl = null, ?string $linkText = null): void
    {
    }

    public function finishedCrawling(): void
    {
    }

    public function getPages(): array
    {
        return $this->pages;
    }

    public function getPageCount(): int
    {
        return count($this->pages);
    }

    protected function cleanHtml(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

        $tagsToRemove = ['script', 'style', 'nav', 'footer', 'aside', 'iframe'];
        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];
            foreach ($elements as $element) {
                $toRemove[] = $element;
            }
            foreach ($toRemove as $element) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
            }
        }

        return $dom->saveHTML();
    }
}
