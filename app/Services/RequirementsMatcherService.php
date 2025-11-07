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
                $match = $evaluator->evaluateMinPages($criteria['min_pages']);
                $results['min_pages'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: min_pages', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['max_pages'])) {
                $match = $evaluator->evaluateMaxPages($criteria['max_pages']);
                $results['max_pages'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: max_pages', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['platforms'])) {
                $match = $evaluator->evaluatePlatform($criteria['platforms']);
                $results['platforms'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: platforms', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['min_word_count'])) {
                $match = $evaluator->evaluateMinWordCount($criteria['min_word_count']);
                $results['min_word_count'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: min_word_count', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['max_word_count'])) {
                $match = $evaluator->evaluateMaxWordCount($criteria['max_word_count']);
                $results['max_word_count'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: max_word_count', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['required_keywords'])) {
                $match = $evaluator->evaluateRequiredKeywords($criteria['required_keywords']);
                $results['required_keywords'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: required_keywords', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['excluded_keywords'])) {
                $match = $evaluator->evaluateExcludedKeywords($criteria['excluded_keywords']);
                $results['excluded_keywords'] = $match;
                $allMatch = $allMatch && $match['matched'];
                Log::info('Criterion: excluded_keywords', [
                    'domain' => $domain->domain,
                    'matched' => $match['matched'] ? 'PASS' : 'FAIL',
                    'message' => $match['message'],
                ]);
            }

            if (isset($criteria['required_urls'])) {
                $match = $evaluator->evaluateRequiredUrls($criteria['required_urls']);
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
}
