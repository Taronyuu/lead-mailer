<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\Website;
use Carbon\Carbon;

class DuplicatePreventionService
{
    protected int $cooldownDays;
    protected int $maxContactsPerWebsite;
    protected int $globalCooldownDays;

    public function __construct()
    {
        $this->cooldownDays = config('mail.duplicate_prevention.cooldown_days', 90);
        $this->maxContactsPerWebsite = config('mail.duplicate_prevention.max_contacts_per_website', 3);
        $this->globalCooldownDays = config('mail.duplicate_prevention.global_cooldown_days', 30);
    }

    /**
     * Check if it's safe to contact this contact
     */
    public function isSafeToContact(Contact $contact): array
    {
        $reasons = [];

        // Check if contact was already contacted recently
        $lastContact = EmailSentLog::where('contact_id', $contact->id)
            ->where('status', EmailSentLog::STATUS_SENT)
            ->where('sent_at', '>=', now()->subDays($this->cooldownDays))
            ->first();

        if ($lastContact) {
            $reasons[] = "Contact was emailed {$lastContact->sent_at->diffForHumans()}";
        }

        // Check website-level limits
        $websiteContactCount = EmailSentLog::where('website_id', $contact->website_id)
            ->where('status', EmailSentLog::STATUS_SENT)
            ->where('sent_at', '>=', now()->subDays($this->globalCooldownDays))
            ->count();

        if ($websiteContactCount >= $this->maxContactsPerWebsite) {
            $reasons[] = "Website has been contacted {$websiteContactCount} times in the last {$this->globalCooldownDays} days";
        }

        // Check email domain limits (prevent spamming same company)
        $emailDomain = $this->extractDomain($contact->email);
        $domainContactCount = EmailSentLog::where('recipient_email', 'LIKE', "%@{$emailDomain}")
            ->where('status', EmailSentLog::STATUS_SENT)
            ->where('sent_at', '>=', now()->subDays($this->globalCooldownDays))
            ->count();

        if ($domainContactCount >= 2) {
            $reasons[] = "Domain {$emailDomain} has been contacted {$domainContactCount} times recently";
        }

        return [
            'safe' => empty($reasons),
            'reasons' => $reasons,
        ];
    }

    /**
     * Record email sent
     */
    public function recordEmail(array $data): EmailSentLog
    {
        $data['sent_at'] = $data['sent_at'] ?? now();

        return EmailSentLog::create($data);
    }

    /**
     * Extract domain from email
     */
    protected function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return $parts[1] ?? '';
    }

    /**
     * Get contacts safe to email
     */
    public function getSafeContacts(int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        // Get all contacts that haven't been contacted
        $contacts = Contact::validated()
            ->notContacted()
            ->whereHas('website', function ($query) {
                $query->where('meets_requirements', true);
            })
            ->limit($limit * 2) // Get extra to filter
            ->get();

        return $contacts->filter(function ($contact) {
            return $this->isSafeToContact($contact)['safe'];
        })->take($limit);
    }
}
