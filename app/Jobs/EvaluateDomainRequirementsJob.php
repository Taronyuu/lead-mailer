<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\RequirementsMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateDomainRequirementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    protected Domain $domain;
    protected string $htmlSnapshot;

    public function __construct(Domain $domain, string $htmlSnapshot)
    {
        $this->domain = $domain;
        $this->htmlSnapshot = $htmlSnapshot;
    }

    public function handle(RequirementsMatcherService $matcher): void
    {
        try {
            $pageCount = $this->calculatePageCount();
            $wordCount = $this->calculateWordCount();
            $detectedPlatform = $this->detectPlatform();

            Log::info('Evaluating domain requirements', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'page_count' => $pageCount,
                'word_count' => $wordCount,
                'detected_platform' => $detectedPlatform,
            ]);

            $results = $matcher->evaluateDomain(
                $this->domain,
                $this->htmlSnapshot,
                $pageCount,
                $wordCount,
                $detectedPlatform
            );

            $matchedWebsites = collect($results)->filter(fn($result) => $result['matches'])->values();

            Log::info('Domain requirements evaluation completed', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'total_websites' => count($results),
                'matched_websites' => $matchedWebsites->count(),
            ]);

            foreach ($results as $result) {
                Log::info('Website evaluation result', [
                    'domain' => $this->domain->domain,
                    'website_id' => $result['website_id'],
                    'website_name' => $result['website_name'],
                    'matches' => $result['matches'] ? 'YES' : 'NO',
                    'details' => $result['details'],
                ]);
            }

            foreach ($matchedWebsites as $match) {
                CreateReviewQueueEntriesJob::dispatch($this->domain, $match['website_id']);
            }

        } catch (\Exception $e) {
            Log::error('Domain requirements evaluation failed', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function calculatePageCount(): int
    {
        return substr_count($this->htmlSnapshot, '<!-- PAGE_SEPARATOR -->') + 1;
    }

    protected function calculateWordCount(): int
    {
        $text = strip_tags($this->htmlSnapshot);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (empty($text)) {
            return 0;
        }

        return str_word_count($text);
    }

    protected function detectPlatform(): ?string
    {
        $html = strtolower($this->htmlSnapshot);

        if (str_contains($html, 'wp-content') || str_contains($html, 'wp-includes') || str_contains($html, '/wp-json/')) {
            return 'WordPress';
        }

        if (str_contains($html, '/components/com_') || str_contains($html, '/media/jui/')) {
            return 'Joomla';
        }

        if (str_contains($html, 'cdn.shopify.com') || str_contains($html, 'shopify-cdn')) {
            return 'Shopify';
        }

        if (str_contains($html, 'wix.com') || str_contains($html, 'parastorage.com')) {
            return 'Wix';
        }

        if (str_contains($html, 'squarespace-cdn.com') || str_contains($html, 'static.squarespace.com')) {
            return 'Squarespace';
        }

        if (str_contains($html, '/sites/default/files/') || str_contains($html, 'drupal.js')) {
            return 'Drupal';
        }

        if (str_contains($html, '/skin/frontend/') || str_contains($html, 'mage/cookies')) {
            return 'Magento';
        }

        if (str_contains($html, 'next.js') || str_contains($html, '__next')) {
            return 'Next.js';
        }

        if (str_contains($html, 'nuxt') || str_contains($html, '__nuxt')) {
            return 'Nuxt.js';
        }

        if (str_contains($html, 'react') && str_contains($html, 'react-dom')) {
            return 'React';
        }

        if (str_contains($html, 'vue.js') || str_contains($html, 'vue.min.js')) {
            return 'Vue.js';
        }

        return null;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Domain requirements evaluation job failed permanently', [
            'domain_id' => $this->domain->id,
            'domain' => $this->domain->domain,
            'error' => $exception->getMessage(),
        ]);
    }
}
