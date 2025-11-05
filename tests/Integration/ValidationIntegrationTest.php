<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\Contact;
use App\Models\Website;
use App\Models\BlacklistEntry;
use App\Models\EmailSentLog;
use App\Jobs\ValidateContactEmailJob;
use App\Jobs\SendOutreachEmailJob;

class ValidationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_validation_plus_blacklist_check(): void
    {
        Queue::fake();

        $website = Website::factory()->create();

        // Create contact
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'test@example.com',
            'is_validated' => false,
        ]);

        // Dispatch validation
        ValidateContactEmailJob::dispatch($contact);
        Queue::assertPushed(ValidateContactEmailJob::class);

        // Simulate validation success
        $contact->markAsValidated(true, null);

        $this->assertTrue($contact->fresh()->is_validated);
        $this->assertTrue($contact->fresh()->is_valid);
        $this->assertNotNull($contact->fresh()->validated_at);

        // Check blacklist
        $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', $contact->email)
            ->exists();

        $this->assertFalse($isBlacklisted);

        // Both validation and blacklist passed - can proceed
        $canContact = $contact->isValidated() && !$isBlacklisted;
        $this->assertTrue($canContact);
    }

    public function test_validation_fails_for_invalid_email(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'invalid@fake-domain-xyz123.com',
            'is_validated' => false,
        ]);

        // Simulate validation failure
        $contact->markAsValidated(false, 'Domain does not exist');

        $this->assertTrue($contact->fresh()->is_validated);
        $this->assertFalse($contact->fresh()->is_valid);
        $this->assertEquals('Domain does not exist', $contact->fresh()->validation_error);

        // Should not be able to contact
        $this->assertFalse($contact->fresh()->isValidated());
    }

    public function test_blacklist_check_prevents_validated_email(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'valid@example.com',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Email passes validation
        $this->assertTrue($contact->isValidated());

        // But is blacklisted
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'valid@example.com',
        ]);

        $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', $contact->email)
            ->exists();

        $this->assertTrue($isBlacklisted);

        // Should not send even though validated
        $canSend = $contact->isValidated() && !$isBlacklisted;
        $this->assertFalse($canSend);
    }

    public function test_domain_blacklist_check_for_validated_email(): void
    {
        // Blacklist entire domain
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'blocked-company.com',
        ]);

        $contact = Contact::factory()->create([
            'email' => 'ceo@blocked-company.com',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Extract domain
        $emailDomain = substr(strrchr($contact->email, '@'), 1);

        // Check domain blacklist
        $isDomainBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $emailDomain)
            ->exists();

        $this->assertTrue($isDomainBlacklisted);

        // Should not send
        $canSend = $contact->isValidated() && !$isDomainBlacklisted;
        $this->assertFalse($canSend);
    }

    public function test_duplicate_send_check_with_validation(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'contacted@example.com',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Email already sent
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
            'status' => 'sent',
        ]);

        // Check duplicate
        $alreadyContacted = EmailSentLog::where('recipient_email', $contact->email)
            ->where('status', 'sent')
            ->exists();

        $this->assertTrue($alreadyContacted);

        // Should not send again
        $canSend = $contact->isValidated() && !$alreadyContacted;
        $this->assertFalse($canSend);
    }

    public function test_complete_validation_pipeline(): void
    {
        Queue::fake();

        $website = Website::factory()->create();

        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'new-lead@company.com',
            'is_validated' => false,
        ]);

        // Step 1: Validate email
        ValidateContactEmailJob::dispatch($contact);
        $contact->markAsValidated(true, null);

        $this->assertTrue($contact->fresh()->isValidated());

        // Step 2: Check blacklist (email)
        $emailBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', $contact->email)
            ->exists();

        $this->assertFalse($emailBlacklisted);

        // Step 3: Check blacklist (domain)
        $emailDomain = substr(strrchr($contact->email, '@'), 1);
        $domainBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $emailDomain)
            ->exists();

        $this->assertFalse($domainBlacklisted);

        // Step 4: Check duplicate send
        $alreadySent = EmailSentLog::where('recipient_email', $contact->email)
            ->whereIn('status', ['sent', 'pending'])
            ->exists();

        $this->assertFalse($alreadySent);

        // All checks passed
        $allChecksPassed = $contact->isValidated()
            && !$emailBlacklisted
            && !$domainBlacklisted
            && !$alreadySent;

        $this->assertTrue($allChecksPassed);
    }

    public function test_batch_validation_with_mixed_results(): void
    {
        $website = Website::factory()->create();

        // Create multiple contacts
        $contacts = [
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => 'valid1@example.com',
            ]),
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => 'invalid@fake.com',
            ]),
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => 'valid2@example.com',
            ]),
        ];

        // Simulate validation results
        $contacts[0]->markAsValidated(true, null);
        $contacts[1]->markAsValidated(false, 'Invalid domain');
        $contacts[2]->markAsValidated(true, null);

        // Filter valid contacts
        $validContacts = Contact::where('website_id', $website->id)
            ->validated()
            ->get();

        $this->assertCount(2, $validContacts);

        // Invalid contact should be filtered out
        $invalidContact = Contact::find($contacts[1]->id);
        $this->assertFalse($invalidContact->isValidated());
    }

    public function test_validation_with_website_qualification(): void
    {
        // Website meets requirements
        $qualifiedWebsite = Website::factory()->create([
            'meets_requirements' => true,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Website doesn't meet requirements
        $unqualifiedWebsite = Website::factory()->create([
            'meets_requirements' => false,
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Create contacts
        $qualifiedContact = Contact::factory()->create([
            'website_id' => $qualifiedWebsite->id,
            'email' => 'contact@qualified.com',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $unqualifiedContact = Contact::factory()->create([
            'website_id' => $unqualifiedWebsite->id,
            'email' => 'contact@unqualified.com',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Only send to contacts from qualified websites
        $canSendQualified = $qualifiedContact->isValidated()
            && $qualifiedContact->website->isQualified();

        $canSendUnqualified = $unqualifiedContact->isValidated()
            && $unqualifiedContact->website->isQualified();

        $this->assertTrue($canSendQualified);
        $this->assertFalse($canSendUnqualified);
    }

    public function test_validation_retry_for_temporary_failures(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'retry@example.com',
            'is_validated' => false,
        ]);

        // First attempt - temporary failure
        $contact->markAsValidated(false, 'Temporary DNS error');

        $this->assertTrue($contact->fresh()->is_validated);
        $this->assertFalse($contact->fresh()->is_valid);

        // Reset for retry
        $contact->update([
            'is_validated' => false,
            'validation_error' => null,
        ]);

        // Second attempt - success
        $contact->fresh()->markAsValidated(true, null);

        $this->assertTrue($contact->fresh()->isValidated());
        $this->assertNull($contact->fresh()->validation_error);
    }

    public function test_combined_validation_blacklist_duplicate_check(): void
    {
        // Setup scenarios
        $scenarios = [
            [
                'email' => 'valid-new@example.com',
                'validated' => true,
                'valid' => true,
                'blacklisted' => false,
                'sent' => false,
                'expected' => true,
            ],
            [
                'email' => 'invalid@fake.com',
                'validated' => true,
                'valid' => false,
                'blacklisted' => false,
                'sent' => false,
                'expected' => false,
            ],
            [
                'email' => 'blacklisted@example.com',
                'validated' => true,
                'valid' => true,
                'blacklisted' => true,
                'sent' => false,
                'expected' => false,
            ],
            [
                'email' => 'already-sent@example.com',
                'validated' => true,
                'valid' => true,
                'blacklisted' => false,
                'sent' => true,
                'expected' => false,
            ],
        ];

        foreach ($scenarios as $scenario) {
            $contact = Contact::factory()->create([
                'email' => $scenario['email'],
                'is_validated' => $scenario['validated'],
                'is_valid' => $scenario['valid'],
            ]);

            if ($scenario['blacklisted']) {
                BlacklistEntry::factory()->create([
                    'type' => BlacklistEntry::TYPE_EMAIL,
                    'value' => $scenario['email'],
                ]);
            }

            if ($scenario['sent']) {
                EmailSentLog::factory()->create([
                    'recipient_email' => $scenario['email'],
                    'status' => 'sent',
                ]);
            }

            // Perform all checks
            $isValid = $contact->isValidated();
            $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
                ->where('value', $contact->email)
                ->exists();
            $alreadySent = EmailSentLog::where('recipient_email', $contact->email)
                ->where('status', 'sent')
                ->exists();

            $canSend = $isValid && !$isBlacklisted && !$alreadySent;

            $this->assertEquals(
                $scenario['expected'],
                $canSend,
                "Failed for email: {$scenario['email']}"
            );
        }
    }

    public function test_validation_status_before_sending(): void
    {
        Queue::fake();

        $validContact = Contact::factory()->create([
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $unvalidatedContact = Contact::factory()->create([
            'is_validated' => false,
        ]);

        $invalidContact = Contact::factory()->create([
            'is_validated' => true,
            'is_valid' => false,
        ]);

        // Only valid contact should trigger send job
        $contacts = [$validContact, $unvalidatedContact, $invalidContact];

        foreach ($contacts as $contact) {
            if ($contact->isValidated()) {
                SendOutreachEmailJob::dispatch(
                    $contact,
                    EmailTemplate::factory()->create(),
                    SmtpCredential::factory()->create()
                );
            }
        }

        // Only one send job should be dispatched
        Queue::assertPushed(SendOutreachEmailJob::class, 1);
    }

    public function test_validation_error_tracking(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'error@test.com',
        ]);

        $errors = [
            'SMTP timeout during validation',
            'Invalid MX records',
            'Mailbox does not exist',
            'Domain not found',
        ];

        foreach ($errors as $error) {
            $contact->markAsValidated(false, $error);

            $this->assertEquals($error, $contact->fresh()->validation_error);
            $this->assertFalse($contact->fresh()->is_valid);
        }
    }

    public function test_case_insensitive_validation_checks(): void
    {
        $email = 'Test@Example.Com';

        // Create contact with mixed case
        $contact = Contact::factory()->create([
            'email' => $email,
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Blacklist with lowercase
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => strtolower($email),
        ]);

        // Check should be case-insensitive
        $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->whereRaw('LOWER(value) = ?', [strtolower($contact->email)])
            ->exists();

        $this->assertTrue($isBlacklisted);

        // Send log with different case
        EmailSentLog::factory()->create([
            'recipient_email' => strtolower($email),
            'status' => 'sent',
        ]);

        // Duplicate check should be case-insensitive
        $alreadySent = EmailSentLog::whereRaw('LOWER(recipient_email) = ?', [strtolower($contact->email)])
            ->where('status', 'sent')
            ->exists();

        $this->assertTrue($alreadySent);
    }
}
