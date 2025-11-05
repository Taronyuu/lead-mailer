<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Str;

class CriteriaEvaluator
{
    protected int $pageCount;
    protected int $wordCount;
    protected ?string $detectedPlatform;
    protected string $htmlSnapshot;

    public function __construct(
        int $pageCount,
        int $wordCount,
        ?string $detectedPlatform,
        string $htmlSnapshot
    ) {
        $this->pageCount = $pageCount;
        $this->wordCount = $wordCount;
        $this->detectedPlatform = $detectedPlatform;
        $this->htmlSnapshot = $htmlSnapshot;
    }

    /**
     * Evaluate minimum pages
     */
    public function evaluateMinPages(int $minPages): array
    {
        $actual = $this->pageCount;
        $matched = $actual >= $minPages;

        return [
            'criterion' => 'min_pages',
            'required' => $minPages,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Website has {$actual} pages (required: {$minPages}+)"
                : "Website has only {$actual} pages (required: {$minPages}+)",
        ];
    }

    /**
     * Evaluate maximum pages
     */
    public function evaluateMaxPages(int $maxPages): array
    {
        $actual = $this->pageCount;
        $matched = $actual <= $maxPages;

        return [
            'criterion' => 'max_pages',
            'required' => $maxPages,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Website has {$actual} pages (limit: {$maxPages})"
                : "Website has {$actual} pages (limit: {$maxPages})",
        ];
    }

    /**
     * Evaluate platform
     */
    public function evaluatePlatform(array $allowedPlatforms): array
    {
        $actual = $this->detectedPlatform;
        $matched = in_array($actual, $allowedPlatforms);

        return [
            'criterion' => 'platform',
            'required' => $allowedPlatforms,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Platform '{$actual}' is allowed"
                : "Platform '{$actual}' not in allowed list: " . implode(', ', $allowedPlatforms),
        ];
    }

    /**
     * Evaluate minimum word count
     */
    public function evaluateMinWordCount(int $minWordCount): array
    {
        $actual = $this->wordCount;
        $matched = $actual >= $minWordCount;

        return [
            'criterion' => 'min_word_count',
            'required' => $minWordCount,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Content has {$actual} words (required: {$minWordCount}+)"
                : "Content has only {$actual} words (required: {$minWordCount}+)",
        ];
    }

    /**
     * Evaluate maximum word count
     */
    public function evaluateMaxWordCount(int $maxWordCount): array
    {
        $actual = $this->wordCount;
        $matched = $actual <= $maxWordCount;

        return [
            'criterion' => 'max_word_count',
            'required' => $maxWordCount,
            'actual' => $actual,
            'matched' => $matched,
            'message' => $matched
                ? "Content has {$actual} words (limit: {$maxWordCount})"
                : "Content has {$actual} words (exceeds limit: {$maxWordCount})",
        ];
    }

    /**
     * Evaluate required keywords
     */
    public function evaluateRequiredKeywords(array $keywords): array
    {
        $content = strtolower($this->htmlSnapshot);
        $found = [];
        $missing = [];

        foreach ($keywords as $keyword) {
            if (Str::contains($content, strtolower($keyword))) {
                $found[] = $keyword;
            } else {
                $missing[] = $keyword;
            }
        }

        $matched = empty($missing);

        return [
            'criterion' => 'required_keywords',
            'required' => $keywords,
            'found' => $found,
            'missing' => $missing,
            'matched' => $matched,
            'message' => $matched
                ? 'All required keywords found: ' . implode(', ', $found)
                : 'Missing keywords: ' . implode(', ', $missing),
        ];
    }

    /**
     * Evaluate excluded keywords
     */
    public function evaluateExcludedKeywords(array $keywords): array
    {
        $content = strtolower($this->htmlSnapshot);
        $foundExcluded = [];

        foreach ($keywords as $keyword) {
            if (Str::contains($content, strtolower($keyword))) {
                $foundExcluded[] = $keyword;
            }
        }

        $matched = empty($foundExcluded);

        return [
            'criterion' => 'excluded_keywords',
            'excluded' => $keywords,
            'found' => $foundExcluded,
            'matched' => $matched,
            'message' => $matched
                ? 'No excluded keywords found'
                : 'Found excluded keywords: ' . implode(', ', $foundExcluded),
        ];
    }

    /**
     * Evaluate required URLs
     */
    public function evaluateRequiredUrls(array $urlPatterns): array
    {
        $content = strtolower($this->htmlSnapshot);
        $found = [];
        $missing = [];

        foreach ($urlPatterns as $pattern) {
            if (Str::contains($content, strtolower($pattern))) {
                $found[] = $pattern;
            } else {
                $missing[] = $pattern;
            }
        }

        $matched = empty($missing);

        return [
            'criterion' => 'required_urls',
            'required' => $urlPatterns,
            'found' => $found,
            'missing' => $missing,
            'matched' => $matched,
            'message' => $matched
                ? 'All required URLs found: ' . implode(', ', $found)
                : 'Missing URLs: ' . implode(', ', $missing),
        ];
    }
}
