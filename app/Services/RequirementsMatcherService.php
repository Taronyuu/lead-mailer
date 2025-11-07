<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Support\Facades\Log;

class RequirementsMatcherService
{
    protected ContentExtractionService $contentExtractor;

    public function __construct(ContentExtractionService $contentExtractor)
    {
        $this->contentExtractor = $contentExtractor;
    }

    /**
     * Evaluate a domain against all websites with requirements
     */
    public function evaluateDomain(
        Domain $domain,
        string $htmlSnapshot,
        int $pageCount,
        int $wordCount,
        ?string $detectedPlatform
    ): array {
        $websites = Website::with('requirements')->get();
        $results = [];

        foreach ($websites as $website) {
            $evaluation = $this->evaluateAgainstWebsite(
                $domain,
                $website,
                $htmlSnapshot,
                $pageCount,
                $wordCount,
                $detectedPlatform
            );

            $results[] = $evaluation;

            $domain->markAsEvaluated(
                $website,
                $evaluation['matches'],
                $evaluation['details'],
                $pageCount,
                $wordCount,
                $detectedPlatform,
                $htmlSnapshot
            );
        }

        return $results;
    }

    /**
     * Evaluate domain against a single website's requirements
     */
    protected function evaluateAgainstWebsite(
        Domain $domain,
        Website $website,
        string $htmlSnapshot,
        int $pageCount,
        int $wordCount,
        ?string $detectedPlatform
    ): array {
        $requirements = $website->requirements;

        if ($requirements->isEmpty()) {
            return [
                'website_id' => $website->id,
                'website_name' => $website->url,
                'matches' => false,
                'reason' => 'No requirements configured',
                'details' => [],
            ];
        }

        $evaluator = new CriteriaEvaluator($pageCount, $wordCount, $detectedPlatform, $htmlSnapshot);
        $allRequirementResults = [];
        $anyRequirementMatched = false;

        Log::info('Evaluating requirements for domain', [
            'domain' => $domain->domain,
            'website' => $website->url,
            'total_requirements' => $requirements->count(),
            'page_count' => $pageCount,
            'word_count' => $wordCount,
            'detected_platform' => $detectedPlatform,
        ]);

        foreach ($requirements as $requirement) {
            $criteria = $requirement->criteria ?? [];
            $results = [];
            $allMatch = true;

            Log::info('Evaluating requirement', [
                'domain' => $domain->domain,
                'requirement_id' => $requirement->id,
                'requirement_name' => $requirement->name,
                'criteria' => $criteria,
            ]);

            if (isset($criteria['min_pages'])) {
                $minPages = $this->parseNumericValue($criteria['min_pages']);
                $match = $evaluator->evaluateMinPages($minPages);
                $results['min_pages'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: min_pages', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['max_pages'])) {
                $maxPages = $this->parseNumericValue($criteria['max_pages']);
                $match = $evaluator->evaluateMaxPages($maxPages);
                $results['max_pages'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: max_pages', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['platforms'])) {
                $platforms = $this->parseArrayValue($criteria['platforms']);
                $match = $evaluator->evaluatePlatform($platforms);
                $results['platforms'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: platforms', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['min_word_count'])) {
                $minWordCount = $this->parseNumericValue($criteria['min_word_count']);
                $match = $evaluator->evaluateMinWordCount($minWordCount);
                $results['min_word_count'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: min_word_count', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['max_word_count'])) {
                $maxWordCount = $this->parseNumericValue($criteria['max_word_count']);
                $match = $evaluator->evaluateMaxWordCount($maxWordCount);
                $results['max_word_count'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: max_word_count', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['required_keywords'])) {
                $keywords = $this->parseArrayValue($criteria['required_keywords']);
                $match = $evaluator->evaluateRequiredKeywords($keywords);
                $results['required_keywords'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: required_keywords', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['excluded_keywords'])) {
                $keywords = $this->parseArrayValue($criteria['excluded_keywords']);
                $match = $evaluator->evaluateExcludedKeywords($keywords);
                $results['excluded_keywords'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: excluded_keywords', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['required_urls'])) {
                $urls = $this->parseArrayValue($criteria['required_urls']);
                $match = $evaluator->evaluateRequiredUrls($urls);
                $results['required_urls'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: required_urls', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            $allRequirementResults[] = [
                'requirement_id' => $requirement->id,
                'requirement_name' => $requirement->name,
                'matches' => $allMatch,
                'criteria_results' => $results,
            ];

            if ($allMatch) {
                $anyRequirementMatched = true;
            }

            Log::info('Requirement evaluation result', [
                'domain' => $domain->domain,
                'requirement_id' => $requirement->id,
                'requirement_name' => $requirement->name,
                'overall_match' => $allMatch ? 'PASS' : 'FAIL',
                'criteria_evaluated' => count($results),
            ]);
        }

        return [
            'website_id' => $website->id,
            'website_name' => $website->url,
            'matches' => $anyRequirementMatched,
            'details' => $allRequirementResults,
        ];
    }

    /**
     * Calculate overall matching score (0-100)
     */
    protected function calculateScore(array $results): float
    {
        if (empty($results)) {
            return 0;
        }

        $totalCriteria = count($results);
        $matchedCriteria = 0;

        foreach ($results as $result) {
            if ($result['matched'] ?? false) {
                $matchedCriteria++;
            }
        }

        return round(($matchedCriteria / $totalCriteria) * 100, 2);
    }

    protected function parseNumericValue($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = preg_replace('/[^0-9]/', '', $value);
        }

        return (int) $value;
    }

    protected function parseArrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
