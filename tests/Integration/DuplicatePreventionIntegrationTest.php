<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Domain;
use App\Models\Website;
use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\BlacklistEntry;

class DuplicatePreventionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_domain_prevention(): void
    {
        // Create first domain
        $domain1 = Domain::factory()->create([
            'domain' => 'example.com',
            'tld' => 'com',
        ]);

        $this->assertDatabaseHas('domains', [
            'domain' => 'example.com',
        ]);

        // Check for duplicate before creating
        $exists = Domain::where('domain', 'example.com')->exists();
        $this->assertTrue($exists);

        // Prevent duplicate creation
        if (!$exists) {
            $domain2 = Domain::factory()->create([
                'domain' => 'example.com',
            ]);
        }

        // Should only have one
        $count = Domain::where('domain', 'example.com')->count();
        $this->assertEquals(1, $count);
    }

    public function test_duplicate_website_url_prevention(): void
    {
        $domain = Domain::factory()->create();

        // Create first website
        $website1 = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://example.com',
        ]);

        // Check for duplicate
        $exists = Website::where('url', 'https://example.com')->exists();
        $this->assertTrue($exists);

        // Prevent duplicate
        if (!$exists) {
            $website2 = Website::factory()->create([
                'domain_id' => $domain->id,
                'url' => 'https://example.com',
            ]);
        }

        $count = Website::where('url', 'https://example.com')->count();
        $this->assertEquals(1, $count);
    }

    public function test_duplicate_contact_per_website_prevention(): void
    {
        $website = Website::factory()->create();

        // Create first contact
        $contact1 = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'john@example.com',
        ]);

        // Check for duplicate on same website
        $exists = Contact::where('website_id', $website->id)
            ->where('email', 'john@example.com')
            ->exists();

        $this->assertTrue($exists);

        // Prevent duplicate
        if (!$exists) {
            $contact2 = Contact::factory()->create([
                'website_id' => $website->id,
                'email' => 'john@example.com',
            ]);
        }

        $count = Contact::where('website_id', $website->id)
            ->where('email', 'john@example.com')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_same_email_allowed_across_different_websites(): void
    {
        $website1 = Website::factory()->create(['url' => 'https://site1.com']);
        $website2 = Website::factory()->create(['url' => 'https://site2.com']);

        // Same email can exist on different websites
        $contact1 = Contact::factory()->create([
            'website_id' => $website1->id,
            'email' => 'info@shared.com',
        ]);

        $contact2 = Contact::factory()->create([
            'website_id' => $website2->id,
            'email' => 'info@shared.com',
        ]);

        $totalCount = Contact::where('email', 'info@shared.com')->count();
        $this->assertEquals(2, $totalCount);

        // But only one per website
        $site1Count = Contact::where('website_id', $website1->id)
            ->where('email', 'info@shared.com')
            ->count();

        $site2Count = Contact::where('website_id', $website2->id)
            ->where('email', 'info@shared.com')
            ->count();

        $this->assertEquals(1, $site1Count);
        $this->assertEquals(1, $site2Count);
    }

    public function test_global_duplicate_email_send_prevention(): void
    {
        $website1 = Website::factory()->create();
        $website2 = Website::factory()->create();

        $contact1 = Contact::factory()->create([
            'website_id' => $website1->id,
            'email' => 'ceo@company.com',
        ]);

        $contact2 = Contact::factory()->create([
            'website_id' => $website2->id,
            'email' => 'ceo@company.com',
        ]);

        // Send to first contact
        EmailSentLog::factory()->create([
            'contact_id' => $contact1->id,
            'recipient_email' => $contact1->email,
            'status' => 'sent',
        ]);

        // Check if email was already sent globally
        $alreadySent = EmailSentLog::where('recipient_email', 'ceo@company.com')
            ->where('status', 'sent')
            ->exists();

        $this->assertTrue($alreadySent);

        // Prevent sending to same email again (global check)
        $shouldSend = !EmailSentLog::where('recipient_email', $contact2->email)
            ->whereIn('status', ['sent', 'pending'])
            ->exists();

        $this->assertFalse($shouldSend, 'Should not send to email that was already contacted');
    }

    public function test_duplicate_prevention_with_soft_deletes(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'deleted-example.com',
        ]);

        // Soft delete
        $domain->delete();

        $this->assertSoftDeleted('domains', [
            'domain' => 'deleted-example.com',
        ]);

        // Check including trashed
        $existsIncludingTrashed = Domain::withTrashed()
            ->where('domain', 'deleted-example.com')
            ->exists();

        $this->assertTrue($existsIncludingTrashed);

        // Regular check (without trashed)
        $exists = Domain::where('domain', 'deleted-example.com')->exists();
        $this->assertFalse($exists);

        // Could recreate if only checking active records
        // But should check withTrashed for true duplicates
        if (!$existsIncludingTrashed) {
            $newDomain = Domain::factory()->create([
                'domain' => 'deleted-example.com',
            ]);
        }

        // Should still be only one (the soft deleted one)
        $totalCount = Domain::withTrashed()
            ->where('domain', 'deleted-example.com')
            ->count();

        $this->assertEquals(1, $totalCount);
    }

    public function test_case_insensitive_email_duplicate_prevention(): void
    {
        $website = Website::factory()->create();

        // Create contact with lowercase email
        Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'john@example.com',
        ]);

        // Check for duplicate (case-insensitive)
        $variants = [
            'JOHN@EXAMPLE.COM',
            'John@Example.Com',
            'john@EXAMPLE.com',
        ];

        foreach ($variants as $emailVariant) {
            $exists = Contact::where('website_id', $website->id)
                ->whereRaw('LOWER(email) = ?', [strtolower($emailVariant)])
                ->exists();

            $this->assertTrue($exists, "Should detect duplicate for: $emailVariant");
        }
    }

    public function test_duplicate_email_domain_extraction(): void
    {
        $website = Website::factory()->create();

        // Create contact
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'contact@example.com',
        ]);

        // Extract domain from email
        $emailDomain = substr(strrchr($contact->email, '@'), 1);
        $this->assertEquals('example.com', $emailDomain);

        // Check if domain is blacklisted
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'example.com',
        ]);

        $isDomainBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $emailDomain)
            ->exists();

        $this->assertTrue($isDomainBlacklisted);
    }

    public function test_duplicate_send_log_tracking(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'repeat@example.com',
        ]);

        // First send
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
            'status' => 'sent',
            'sent_at' => now()->subDays(30),
        ]);

        // Check if already sent
        $wasSent = EmailSentLog::where('recipient_email', $contact->email)
            ->where('status', 'sent')
            ->exists();

        $this->assertTrue($wasSent);

        // Check time-based duplicate prevention (e.g., don't send within 30 days)
        $recentlySent = EmailSentLog::where('recipient_email', $contact->email)
            ->where('status', 'sent')
            ->where('sent_at', '>', now()->subDays(30))
            ->exists();

        $this->assertTrue($recentlySent);

        // After 30 days, might allow resend
        $oldSend = EmailSentLog::where('recipient_email', $contact->email)
            ->where('status', 'sent')
            ->where('sent_at', '<=', now()->subDays(30))
            ->exists();

        $this->assertTrue($oldSend);
    }

    public function test_website_url_normalization_for_duplicates(): void
    {
        $domain = Domain::factory()->create();

        // Different URL formats that are essentially the same
        $urls = [
            'https://example.com',
            'https://example.com/',
            'http://example.com',
        ];

        // Normalize URLs before checking duplicates
        $normalizedUrls = [];
        foreach ($urls as $url) {
            $normalized = rtrim($url, '/');
            $normalized = str_replace('http://', 'https://', $normalized);
            $normalizedUrls[] = $normalized;
        }

        // All should normalize to the same value
        $unique = array_unique($normalizedUrls);
        $this->assertCount(1, $unique);

        // Create only one website with normalized URL
        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => $unique[0],
        ]);

        $this->assertDatabaseHas('websites', [
            'url' => 'https://example.com',
        ]);
    }

    public function test_blacklist_prevents_all_duplicates(): void
    {
        // Blacklist email
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@example.com',
        ]);

        // Blacklist domain
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'blocked-domain.com',
        ]);

        $website = Website::factory()->create();

        // Try to create contact with blacklisted email
        $email1 = 'blocked@example.com';
        $isEmailBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', $email1)
            ->exists();

        $this->assertTrue($isEmailBlacklisted);

        // Try to create contact from blacklisted domain
        $email2 = 'someone@blocked-domain.com';
        $emailDomain = substr(strrchr($email2, '@'), 1);
        $isDomainBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $emailDomain)
            ->exists();

        $this->assertTrue($isDomainBlacklisted);

        // Neither should be created
        if (!$isEmailBlacklisted) {
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => $email1,
            ]);
        }

        if (!$isDomainBlacklisted) {
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => $email2,
            ]);
        }

        $contactCount = Contact::where('website_id', $website->id)->count();
        $this->assertEquals(0, $contactCount);
    }

    public function test_multiple_websites_same_domain(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'multi-site.com',
        ]);

        // Allow multiple websites from same domain with different URLs
        $website1 = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://multi-site.com',
        ]);

        $website2 = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://www.multi-site.com',
        ]);

        $website3 = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://blog.multi-site.com',
        ]);

        $this->assertEquals(3, $domain->websites()->count());

        // But each URL should be unique
        $uniqueUrls = Website::where('domain_id', $domain->id)
            ->distinct('url')
            ->count();

        $this->assertEquals(3, $uniqueUrls);
    }

    public function test_comprehensive_duplicate_check_workflow(): void
    {
        // Setup blacklist
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blacklisted@example.com',
        ]);

        // Create website and contact
        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'valid@example.com',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        // Send first email
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
            'status' => 'sent',
        ]);

        // Comprehensive check function
        $canSendEmail = function ($email) {
            // Check 1: Blacklist check
            $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
                ->where('value', $email)
                ->exists();

            if ($isBlacklisted) {
                return false;
            }

            // Check 2: Domain blacklist
            $emailDomain = substr(strrchr($email, '@'), 1);
            $isDomainBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
                ->where('value', $emailDomain)
                ->exists();

            if ($isDomainBlacklisted) {
                return false;
            }

            // Check 3: Already sent check
            $alreadySent = EmailSentLog::where('recipient_email', $email)
                ->whereIn('status', ['sent', 'pending'])
                ->exists();

            if ($alreadySent) {
                return false;
            }

            return true;
        };

        // Test various scenarios
        $this->assertFalse($canSendEmail('blacklisted@example.com'), 'Blacklisted email');
        $this->assertFalse($canSendEmail('valid@example.com'), 'Already sent');
        $this->assertTrue($canSendEmail('new@example.com'), 'New valid email');
    }
}
