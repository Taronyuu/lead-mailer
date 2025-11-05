<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;

class EmailValidationService
{
    /**
     * Validate a contact's email address
     */
    public function validate(Contact $contact): bool
    {
        // Format validation
        if (!$this->isValidFormat($contact->email)) {
            $contact->markAsValidated(false, 'Invalid email format');
            return false;
        }

        // Check if disposable email domain
        if ($this->isDisposableEmail($contact->email)) {
            $contact->markAsValidated(false, 'Disposable email domain');
            return false;
        }

        // MX record validation
        $mxResult = $this->validateMxRecords($contact->email);

        if (!$mxResult['valid']) {
            $contact->markAsValidated(false, $mxResult['error'], $mxResult);
            return false;
        }

        // All validations passed
        $contact->markAsValidated(true, null, $mxResult);
        return true;
    }

    /**
     * Validate email format
     */
    protected function isValidFormat(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if email is from disposable domain
     */
    protected function isDisposableEmail(string $email): bool
    {
        $disposableDomains = [
            'tempmail.com',
            'guerrillamail.com',
            '10minutemail.com',
            'mailinator.com',
            'throwaway.email',
        ];

        $domain = strtolower(explode('@', $email)[1] ?? '');

        return in_array($domain, $disposableDomains);
    }

    /**
     * Validate MX records for email domain
     */
    protected function validateMxRecords(string $email): array
    {
        $domain = explode('@', $email)[1] ?? '';

        if (empty($domain)) {
            return [
                'valid' => false,
                'error' => 'Invalid domain',
                'mx_valid' => false,
                'mx_host' => null,
            ];
        }

        try {
            // Check MX records
            if (getmxrr($domain, $mxHosts, $weights)) {
                // Sort by priority (weight)
                array_multisort($weights, SORT_ASC, $mxHosts);

                return [
                    'valid' => true,
                    'error' => null,
                    'mx_valid' => true,
                    'mx_host' => $mxHosts[0] ?? null,
                ];
            }

            // No MX records, but check if A record exists (fallback)
            if (checkdnsrr($domain, 'A')) {
                return [
                    'valid' => true,
                    'error' => null,
                    'mx_valid' => false,
                    'mx_host' => $domain,
                ];
            }

            return [
                'valid' => false,
                'error' => 'No MX or A records found',
                'mx_valid' => false,
                'mx_host' => null,
            ];
        } catch (\Exception $e) {
            Log::error('MX validation error', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return [
                'valid' => false,
                'error' => 'DNS lookup failed: ' . $e->getMessage(),
                'mx_valid' => false,
                'mx_host' => null,
            ];
        }
    }

    /**
     * Batch validate multiple contacts
     */
    public function batchValidate(array $contacts): array
    {
        $results = [
            'validated' => 0,
            'valid' => 0,
            'invalid' => 0,
        ];

        foreach ($contacts as $contact) {
            $results['validated']++;

            if ($this->validate($contact)) {
                $results['valid']++;
            } else {
                $results['invalid']++;
            }
        }

        return $results;
    }
}
