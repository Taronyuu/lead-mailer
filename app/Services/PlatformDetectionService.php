<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PlatformDetectionService
{
    /**
     * Detect platform from URL
     */
    public function detect(string $url): string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; LeadBot/1.0)',
                ])
                ->get($url);

            if (!$response->successful()) {
                return 'unknown';
            }

            $html = strtolower($response->body());
            $headers = $response->headers();

            // Check response headers first
            $platform = $this->detectFromHeaders($headers);
            if ($platform !== 'custom') {
                return $platform;
            }

            // Check HTML content
            return $this->detectFromHtml($html);

        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Detect from HTTP headers
     */
    protected function detectFromHeaders(array $headers): string
    {
        $headerString = json_encode($headers);
        $headerString = strtolower($headerString);

        if (str_contains($headerString, 'x-powered-by') &&
            str_contains($headerString, 'wordpress')) {
            return 'wordpress';
        }

        if (str_contains($headerString, 'shopify')) {
            return 'shopify';
        }

        return 'custom';
    }

    /**
     * Detect from HTML content
     */
    public function detectFromHtml(string $html): string
    {
        $detectors = [
            'wordpress' => [
                'wp-content',
                'wp-includes',
                'wordpress',
                '/wp-json/',
            ],
            'shopify' => [
                'shopify',
                'cdn.shopify.com',
                'myshopify.com',
            ],
            'wix' => [
                'wix.com',
                'wixsite.com',
                'wix-code',
            ],
            'squarespace' => [
                'squarespace',
                'squarespace-cdn',
            ],
            'webflow' => [
                'webflow',
            ],
            'joomla' => [
                'joomla',
                '/components/com_',
            ],
            'drupal' => [
                'drupal',
                'sites/all/themes',
            ],
            'woocommerce' => [
                'woocommerce',
                'wc-',
            ],
        ];

        foreach ($detectors as $platform => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($html, $pattern)) {
                    return $platform;
                }
            }
        }

        return 'custom';
    }

    /**
     * Get detailed platform information
     */
    public function getDetailedInfo(string $url): array
    {
        $platform = $this->detect($url);

        return [
            'platform' => $platform,
            'is_cms' => in_array($platform, ['wordpress', 'joomla', 'drupal']),
            'is_ecommerce' => in_array($platform, ['shopify', 'woocommerce']),
            'is_website_builder' => in_array($platform, ['wix', 'squarespace', 'webflow']),
        ];
    }
}
