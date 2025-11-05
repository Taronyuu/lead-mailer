<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

class ContentExtractionService
{
    /**
     * Extract main content from HTML
     */
    public function extractContent(string $html): array
    {
        $crawler = new Crawler($html);

        return [
            'title' => $this->extractTitle($crawler),
            'description' => $this->extractDescription($crawler),
            'headings' => $this->extractHeadings($crawler),
            'paragraphs' => $this->extractParagraphs($crawler),
            'links' => $this->extractLinks($crawler),
            'images' => $this->extractImages($crawler),
            'word_count' => $this->calculateWordCount($crawler),
        ];
    }

    /**
     * Extract page title
     */
    protected function extractTitle(Crawler $crawler): ?string
    {
        try {
            return $crawler->filter('title')->first()->text();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract meta description
     */
    protected function extractDescription(Crawler $crawler): ?string
    {
        try {
            return $crawler->filter('meta[name="description"]')->first()->attr('content');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract all headings
     */
    protected function extractHeadings(Crawler $crawler): array
    {
        $headings = [];

        for ($i = 1; $i <= 6; $i++) {
            $crawler->filter("h{$i}")->each(function (Crawler $node) use (&$headings) {
                $headings[] = trim($node->text());
            });
        }

        return $headings;
    }

    /**
     * Extract paragraphs
     */
    protected function extractParagraphs(Crawler $crawler): array
    {
        $paragraphs = [];

        $crawler->filter('p')->each(function (Crawler $node) use (&$paragraphs) {
            $text = trim($node->text());
            if (strlen($text) > 20) { // Only meaningful paragraphs
                $paragraphs[] = $text;
            }
        });

        return $paragraphs;
    }

    /**
     * Extract links
     */
    protected function extractLinks(Crawler $crawler): array
    {
        $links = [];

        $crawler->filter('a')->each(function (Crawler $node) use (&$links) {
            $href = $node->attr('href');
            $text = trim($node->text());

            if ($href && $text) {
                $links[] = [
                    'url' => $href,
                    'text' => $text,
                ];
            }
        });

        return $links;
    }

    /**
     * Extract images
     */
    protected function extractImages(Crawler $crawler): array
    {
        $images = [];

        $crawler->filter('img')->each(function (Crawler $node) use (&$images) {
            $images[] = [
                'src' => $node->attr('src'),
                'alt' => $node->attr('alt'),
            ];
        });

        return $images;
    }

    /**
     * Calculate word count
     */
    protected function calculateWordCount(Crawler $crawler): int
    {
        try {
            $text = $crawler->filter('body')->first()->text();
            return str_word_count($text);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if URL exists on page
     */
    public function hasUrl(string $html, string $urlPattern): bool
    {
        $crawler = new Crawler($html);

        $found = false;

        $crawler->filter('a')->each(function (Crawler $node) use ($urlPattern, &$found) {
            $href = $node->attr('href');

            if (str_contains(strtolower($href), strtolower($urlPattern))) {
                $found = true;
            }
        });

        return $found;
    }
}
