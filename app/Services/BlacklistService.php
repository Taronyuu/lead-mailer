<?php

namespace App\Services;

use App\Models\BlacklistEntry;
use App\Models\Contact;
use App\Models\Domain;
use Illuminate\Support\Facades\Cache;

class BlacklistService
{
    /**
     * Cache duration for blacklist checks (in seconds)
     */
    protected int $cacheDuration = 3600; // 1 hour

    /**
     * Check if an email address is blacklisted
     */
    public function isEmailBlacklisted(string $email): bool
    {
        $email = strtolower(trim($email));

        $cacheKey = "blacklist:email:{$email}";

        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($email) {
            return BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
                ->where('value', $email)
                ->where('is_active', true)
                ->exists();
        });
    }

    /**
     * Check if a domain is blacklisted
     */
    public function isDomainBlacklisted(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        $cacheKey = "blacklist:domain:{$domain}";

        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($domain) {
            return BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
                ->where('value', $domain)
                ->where('is_active', true)
                ->exists();
        });
    }

    /**
     * Check if a contact's email or domain is blacklisted
     */
    public function isContactBlacklisted(Contact $contact): array
    {
        $reasons = [];

        // Check email directly
        if ($this->isEmailBlacklisted($contact->email)) {
            $reasons[] = 'Email address is blacklisted';
        }

        // Check email domain
        $emailDomain = $this->extractDomain($contact->email);
        if ($emailDomain && $this->isDomainBlacklisted($emailDomain)) {
            $reasons[] = 'Email domain is blacklisted';
        }

        // Check website domain
        if ($contact->website) {
            $websiteDomain = $contact->website->domain->domain ?? null;
            if ($websiteDomain && $this->isDomainBlacklisted($websiteDomain)) {
                $reasons[] = 'Website domain is blacklisted';
            }
        }

        return [
            'blacklisted' => !empty($reasons),
            'reasons' => $reasons,
        ];
    }

    /**
     * Add an email to the blacklist
     */
    public function blacklistEmail(
        string $email,
        string $reason,
        string $source = 'manual'
    ): BlacklistEntry {
        $email = strtolower(trim($email));

        $entry = BlacklistEntry::firstOrCreate(
            [
                'type' => BlacklistEntry::TYPE_EMAIL,
                'value' => $email,
            ],
            [
                'reason' => $reason,
                'source' => $source,
                'is_active' => true,
            ]
        );

        // Clear cache
        Cache::forget("blacklist:email:{$email}");

        return $entry;
    }

    /**
     * Add a domain to the blacklist
     */
    public function blacklistDomain(
        string $domain,
        string $reason,
        string $source = 'manual'
    ): BlacklistEntry {
        $domain = strtolower(trim($domain));

        $entry = BlacklistEntry::firstOrCreate(
            [
                'type' => BlacklistEntry::TYPE_DOMAIN,
                'value' => $domain,
            ],
            [
                'reason' => $reason,
                'source' => $source,
                'is_active' => true,
            ]
        );

        // Clear cache
        Cache::forget("blacklist:domain:{$domain}");

        return $entry;
    }

    /**
     * Remove an email from the blacklist
     */
    public function removeEmailFromBlacklist(string $email): bool
    {
        $email = strtolower(trim($email));

        $deleted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', $email)
            ->delete();

        // Clear cache
        Cache::forget("blacklist:email:{$email}");

        return $deleted > 0;
    }

    /**
     * Remove a domain from the blacklist
     */
    public function removeDomainFromBlacklist(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        $deleted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $domain)
            ->delete();

        // Clear cache
        Cache::forget("blacklist:domain:{$domain}");

        return $deleted > 0;
    }

    /**
     * Deactivate a blacklist entry (soft removal)
     */
    public function deactivateEntry(BlacklistEntry $entry): bool
    {
        $entry->update(['is_active' => false]);

        // Clear cache
        $cacheKey = "blacklist:{$entry->type}:{$entry->value}";
        Cache::forget($cacheKey);

        return true;
    }

    /**
     * Activate a blacklist entry
     */
    public function activateEntry(BlacklistEntry $entry): bool
    {
        $entry->update(['is_active' => true]);

        // Clear cache
        $cacheKey = "blacklist:{$entry->type}:{$entry->value}";
        Cache::forget($cacheKey);

        return true;
    }

    /**
     * Bulk blacklist emails from an array
     */
    public function bulkBlacklistEmails(
        array $emails,
        string $reason,
        string $source = 'bulk_import'
    ): int {
        $count = 0;

        foreach ($emails as $email) {
            $email = strtolower(trim($email));

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->blacklistEmail($email, $reason, $source);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk blacklist domains from an array
     */
    public function bulkBlacklistDomains(
        array $domains,
        string $reason,
        string $source = 'bulk_import'
    ): int {
        $count = 0;

        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));

            if ($this->isValidDomain($domain)) {
                $this->blacklistDomain($domain, $reason, $source);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Auto-blacklist based on bounce or complaint
     */
    public function autoBlacklistFromBounce(
        string $email,
        string $bounceType = 'hard'
    ): ?BlacklistEntry {
        if ($bounceType === 'hard') {
            return $this->blacklistEmail(
                $email,
                "Auto-blacklisted due to hard bounce",
                'auto_bounce'
            );
        }

        return null;
    }

    /**
     * Auto-blacklist based on spam complaint
     */
    public function autoBlacklistFromComplaint(string $email): BlacklistEntry
    {
        return $this->blacklistEmail(
            $email,
            "Auto-blacklisted due to spam complaint",
            'auto_complaint'
        );
    }

    /**
     * Get all active blacklist entries
     */
    public function getActiveEntries(string $type = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = BlacklistEntry::where('is_active', true);

        if ($type) {
            $query->where('type', $type);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Search blacklist entries
     */
    public function search(string $query, string $type = null): \Illuminate\Database\Eloquent\Collection
    {
        $searchQuery = BlacklistEntry::where('value', 'like', "%{$query}%");

        if ($type) {
            $searchQuery->where('type', $type);
        }

        return $searchQuery->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get blacklist statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_entries' => BlacklistEntry::count(),
            'active_entries' => BlacklistEntry::where('is_active', true)->count(),
            'inactive_entries' => BlacklistEntry::where('is_active', false)->count(),
            'email_entries' => BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)->count(),
            'domain_entries' => BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)->count(),
            'auto_entries' => BlacklistEntry::whereIn('source', ['auto_bounce', 'auto_complaint'])->count(),
            'manual_entries' => BlacklistEntry::where('source', 'manual')->count(),
        ];
    }

    /**
     * Clear all blacklist cache
     */
    public function clearCache(): void
    {
        Cache::flush();
    }

    /**
     * Extract domain from email address
     */
    protected function extractDomain(string $email): ?string
    {
        if (strpos($email, '@') === false) {
            return null;
        }

        return strtolower(substr(strrchr($email, "@"), 1));
    }

    /**
     * Validate domain format
     */
    protected function isValidDomain(string $domain): bool
    {
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/i', $domain);
    }

    /**
     * Import blacklist from file (CSV)
     */
    public function importFromCsv(string $filePath, string $type): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        $imported = 0;
        $skipped = 0;
        $errors = [];

        // Skip header row
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            try {
                [$value, $reason, $source] = array_pad($row, 3, '');

                if (empty($value)) {
                    $skipped++;
                    continue;
                }

                if ($type === BlacklistEntry::TYPE_EMAIL) {
                    $this->blacklistEmail(
                        $value,
                        $reason ?: 'Imported from CSV',
                        $source ?: 'csv_import'
                    );
                } else {
                    $this->blacklistDomain(
                        $value,
                        $reason ?: 'Imported from CSV',
                        $source ?: 'csv_import'
                    );
                }

                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Error importing {$value}: " . $e->getMessage();
                $skipped++;
            }
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Export blacklist to CSV
     */
    public function exportToCsv(string $filePath, string $type = null): int
    {
        $entries = $this->getActiveEntries($type);

        $handle = fopen($filePath, 'w');

        // Write header
        fputcsv($handle, ['Type', 'Value', 'Reason', 'Source', 'Created At']);

        foreach ($entries as $entry) {
            fputcsv($handle, [
                $entry->type,
                $entry->value,
                $entry->reason,
                $entry->source,
                $entry->created_at->toDateTimeString(),
            ]);
        }

        fclose($handle);

        return $entries->count();
    }
}
