<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ContactExtractionService
{
    /**
     * Email regex pattern
     */
    protected const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    protected UrlPatternService $urlPatternService;

    public function __construct(UrlPatternService $urlPatternService)
    {
        $this->urlPatternService = $urlPatternService;
    }

    /**
     * Extract contacts from HTML content
     */
    public function extractFromHtml(
        string $html,
        string $url,
        Domain $domain,
        ?string $sourceType = null
    ): array {
        $crawler = new Crawler($html);
        $contacts = [];

        if (!$sourceType) {
            $sourceType = $this->determineSourceType($url);
        }

        $emailMatches = $this->findEmailsWithContext($html, $crawler);

        foreach ($emailMatches as $match) {
            if ($this->contactExists($domain->id, $match['email'])) {
                continue;
            }

            $contact = Contact::create([
                'domain_id' => $domain->id,
                'email' => $match['email'],
                'name' => $match['name'] ?? null,
                'position' => $match['position'] ?? null,
                'source_url' => $url,
                'source_type' => $sourceType,
                'source_context' => $match['context'] ?? null,
            ]);

            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * Find emails with surrounding context
     */
    protected function findEmailsWithContext(string $html, Crawler $crawler): array
    {
        $results = [];
        $seenEmails = [];

        // First try to find emails in mailto links
        $crawler->filter('a[href^="mailto:"]')->each(function (Crawler $node) use (&$results, &$seenEmails) {
            $href = $node->attr('href');
            $email = str_replace('mailto:', '', $href);
            $email = strtolower(trim(explode('?', $email)[0])); // Remove query params

            if ($this->isValidEmailFormat($email) && !isset($seenEmails[$email])) {
                $seenEmails[$email] = true;
                $results[] = [
                    'email' => $email,
                    'context' => $node->text(),
                    'name' => $this->extractNameFromContext($node->text()),
                ];
            }
        });

        // Find emails in text content
        if (preg_match_all(self::EMAIL_PATTERN, $html, $matches)) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim($email));

                if ($this->isValidEmailFormat($email) && !isset($seenEmails[$email])) {
                    $seenEmails[$email] = true;
                    $context = $this->extractContext($html, $email);

                    $results[] = [
                        'email' => $email,
                        'context' => $context,
                        'name' => $this->extractNameFromContext($context),
                        'position' => $this->extractPositionFromContext($context),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Extract surrounding context for an email
     */
    protected function extractContext(string $html, string $email, int $contextLength = 200): string
    {
        $position = strpos($html, $email);

        if ($position === false) {
            return '';
        }

        $start = max(0, $position - $contextLength);
        $length = $contextLength * 2 + strlen($email);

        $context = substr($html, $start, $length);
        $context = strip_tags($context);
        $context = preg_replace('/\s+/', ' ', $context);

        return trim($context);
    }

    /**
     * Try to extract a name from context
     */
    protected function extractNameFromContext(?string $context): ?string
    {
        if (!$context) {
            return null;
        }

        // Common patterns: "Contact John Doe", "Email: Jane Smith", etc.
        $patterns = [
            '/(?:contact|email|reach|write to)\s+([A-Z][a-z]+\s+[A-Z][a-z]+)/i',
            '/([A-Z][a-z]+\s+[A-Z][a-z]+)\s*[-–]\s*(?:CEO|CTO|Manager|Director)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $context, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Try to extract position/title from context (multi-language support)
     */
    protected function extractPositionFromContext(?string $context): ?string
    {
        if (!$context) {
            return null;
        }

        // Multi-language job titles
        $titles = [
            // English
            'CEO', 'CTO', 'CFO', 'COO', 'Director', 'Manager', 'Founder', 'Co-Founder',
            'President', 'VP', 'Vice President', 'Head', 'Lead', 'Chief',
            // Dutch
            'Directeur', 'Oprichter', 'Medeoprichter', 'Manager', 'Hoofd',
            'Eigenaar', 'Zaakvoerder', 'Bedrijfsleider', 'Bestuurder',
            // German
            'Geschäftsführer', 'Gründer', 'Leiter', 'Direktor',
            // French
            'Directeur', 'Fondateur', 'Gérant', 'Président',
            // Spanish
            'Director', 'Fundador', 'Gerente', 'Presidente',
        ];

        foreach ($titles as $title) {
            if (stripos($context, $title) !== false) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Determine source type from URL (multi-language support)
     */
    protected function determineSourceType(string $url): string
    {
        $pageType = $this->urlPatternService->determinePageType($url);

        return match($pageType) {
            'contact' => Contact::SOURCE_CONTACT_PAGE,
            'about' => Contact::SOURCE_ABOUT_PAGE,
            'team' => Contact::SOURCE_TEAM_PAGE,
            'header' => Contact::SOURCE_HEADER,
            'footer' => Contact::SOURCE_FOOTER,
            default => Contact::SOURCE_BODY,
        };
    }

    /**
     * Check if contact already exists
     */
    protected function contactExists(int $domainId, string $email): bool
    {
        return Contact::where('domain_id', $domainId)
            ->where('email', $email)
            ->exists();
    }

    /**
     * Validate email format
     */
    protected function isValidEmailFormat(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|bmp|ico|pdf|doc|docx|xls|xlsx|zip|rar)$/i', $email)) {
            return false;
        }

        if (preg_match('/@\d+x[-.]/i', $email)) {
            return false;
        }

        return true;
    }

    /**
     * Get priority URLs to check for contacts (multi-language support)
     */
    public function getPriorityUrls(Website $website): array
    {
        $priorityUrls = $this->urlPatternService->generatePriorityUrls($website->url);

        // Add homepage
        $priorityUrls[] = rtrim($website->url, '/');

        // Limit to first 50 URLs to avoid overwhelming the crawler
        return array_slice(array_unique($priorityUrls), 0, 50);
    }
}
