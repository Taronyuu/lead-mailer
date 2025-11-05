<?php

namespace App\Services;

class UrlPatternService
{
    protected array $patterns;
    protected array $priorityScores;
    protected array $enabledLanguages;

    public function __construct()
    {
        $this->patterns = config('url_patterns');
        $this->priorityScores = config('url_patterns.priority_scores', []);
        $this->enabledLanguages = config('url_patterns.enabled_languages', ['en', 'nl']);
    }

    /**
     * Determine the page type based on URL
     */
    public function determinePageType(string $url): string
    {
        $url = strtolower($url);
        $urlPath = parse_url($url, PHP_URL_PATH) ?? '';

        // Remove leading/trailing slashes and query parameters
        $urlPath = trim($urlPath, '/');

        // Check each page type against enabled languages
        foreach ($this->getPageTypes() as $pageType) {
            if ($this->matchesPageType($urlPath, $pageType)) {
                return $pageType;
            }
        }

        // Check if it's in header, footer, or default to body
        if ($this->isHeaderUrl($url)) {
            return 'header';
        }

        if ($this->isFooterUrl($url)) {
            return 'footer';
        }

        return 'body';
    }

    /**
     * Check if URL matches a specific page type
     */
    public function matchesPageType(string $urlPath, string $pageType): bool
    {
        $patterns = $this->getPatterns($pageType);

        foreach ($patterns as $pattern) {
            // Direct match
            if (str_contains($urlPath, $pattern)) {
                return true;
            }

            // Match with common separators
            if (str_contains($urlPath, "/{$pattern}/") ||
                str_contains($urlPath, "-{$pattern}-") ||
                str_contains($urlPath, "_{$pattern}_") ||
                str_ends_with($urlPath, "/{$pattern}") ||
                str_starts_with($urlPath, "{$pattern}/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all patterns for a page type across all enabled languages
     */
    public function getPatterns(string $pageType): array
    {
        $allPatterns = [];

        $pageTypeKey = $pageType . '_page';
        if (!isset($this->patterns[$pageTypeKey])) {
            return [];
        }

        foreach ($this->enabledLanguages as $language) {
            if (isset($this->patterns[$pageTypeKey][$language])) {
                $allPatterns = array_merge(
                    $allPatterns,
                    $this->patterns[$pageTypeKey][$language]
                );
            }
        }

        return array_unique($allPatterns);
    }

    /**
     * Get priority score for a page type
     */
    public function getPriorityScore(string $pageType): int
    {
        return $this->priorityScores[$pageType] ?? 5;
    }

    /**
     * Get all available page types
     */
    public function getPageTypes(): array
    {
        return [
            'contact',
            'about',
            'team',
            'services',
            'careers',
            'blog',
            'faq',
            'privacy',
            'terms',
        ];
    }

    /**
     * Check if URL is likely a header navigation link
     */
    protected function isHeaderUrl(string $url): bool
    {
        return str_contains($url, '#header') ||
               str_contains($url, '#nav') ||
               str_contains($url, '#menu') ||
               str_contains($url, '#top');
    }

    /**
     * Check if URL is likely a footer link
     */
    protected function isFooterUrl(string $url): bool
    {
        return str_contains($url, '#footer') ||
               str_contains($url, '#bottom');
    }

    /**
     * Detect language from URL
     */
    public function detectLanguage(string $url): ?string
    {
        $url = strtolower($url);

        // Check for language codes in URL
        foreach ($this->enabledLanguages as $language) {
            if (str_contains($url, "/{$language}/") ||
                str_starts_with($url, "{$language}/") ||
                str_contains($url, ".{$language}/") ||
                str_contains($url, "lang={$language}")) {
                return $language;
            }
        }

        // Check for country-specific TLDs
        if (str_contains($url, '.nl')) return 'nl';
        if (str_contains($url, '.de')) return 'de';
        if (str_contains($url, '.fr')) return 'fr';
        if (str_contains($url, '.es')) return 'es';
        if (str_contains($url, '.it')) return 'it';

        return null;
    }

    /**
     * Get localized patterns for a specific language
     */
    public function getLocalizedPatterns(string $pageType, string $language): array
    {
        $pageTypeKey = $pageType . '_page';

        return $this->patterns[$pageTypeKey][$language] ?? [];
    }

    /**
     * Check if a page type exists in configuration
     */
    public function hasPageType(string $pageType): bool
    {
        $pageTypeKey = $pageType . '_page';
        return isset($this->patterns[$pageTypeKey]);
    }

    /**
     * Get all contact-related patterns (useful for email extraction)
     */
    public function getContactPatterns(): array
    {
        return array_merge(
            $this->getPatterns('contact'),
            $this->getPatterns('about'),
            $this->getPatterns('team')
        );
    }

    /**
     * Batch check URLs against multiple page types
     */
    public function batchCheckUrls(array $urls): array
    {
        $results = [];

        foreach ($urls as $url) {
            $results[$url] = [
                'page_type' => $this->determinePageType($url),
                'priority' => $this->getPriorityScore($this->determinePageType($url)),
                'language' => $this->detectLanguage($url),
            ];
        }

        return $results;
    }

    /**
     * Generate priority URLs for crawling (contact, about, team pages)
     */
    public function generatePriorityUrls(string $baseUrl): array
    {
        $urls = [];
        $baseUrl = rtrim($baseUrl, '/');

        // Get high-priority page patterns
        $highPriorityTypes = ['contact', 'about', 'team'];

        foreach ($highPriorityTypes as $pageType) {
            $patterns = $this->getPatterns($pageType);

            foreach ($patterns as $pattern) {
                $urls[] = "{$baseUrl}/{$pattern}";
                $urls[] = "{$baseUrl}/{$pattern}.html";
                $urls[] = "{$baseUrl}/{$pattern}.php";
            }
        }

        return array_unique($urls);
    }
}
