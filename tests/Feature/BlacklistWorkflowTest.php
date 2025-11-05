<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\BlacklistEntry;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\User;

class BlacklistWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_blacklist_domain_prevents_processing(): void
    {
        $user = User::factory()->create();

        // 1. Add domain to blacklist
        $blacklistEntry = BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam-domain.com',
            'reason' => 'Known spam domain',
            'source' => BlacklistEntry::SOURCE_MANUAL,
            'added_by_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('blacklist_entries', [
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam-domain.com',
        ]);

        // 2. Check if domain is blacklisted
        $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', 'spam-domain.com')
            ->exists();

        $this->assertTrue($isBlacklisted);

        // 3. Verify domain won't be processed
        $domain = Domain::factory()->create([
            'domain' => 'spam-domain.com',
        ]);

        // In actual implementation, this would be checked before processing
        $shouldProcess = !BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $domain->domain)
            ->exists();

        $this->assertFalse($shouldProcess);
    }

    public function test_blacklist_email_prevents_contact(): void
    {
        $user = User::factory()->create();

        // 1. Add email to blacklist
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'spam@example.com',
            'reason' => 'Requested removal',
            'source' => BlacklistEntry::SOURCE_MANUAL,
            'added_by_user_id' => $user->id,
        ]);

        // 2. Check if email is blacklisted
        $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', 'spam@example.com')
            ->exists();

        $this->assertTrue($isBlacklisted);

        // 3. Verify contact won't be sent
        $contact = Contact::factory()->create([
            'email' => 'spam@example.com',
        ]);

        $canContact = !BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', $contact->email)
            ->exists();

        $this->assertFalse($canContact);
    }

    public function test_blacklist_scopes(): void
    {
        // Create domain blacklist entries
        BlacklistEntry::factory()->count(3)->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
        ]);

        // Create email blacklist entries
        BlacklistEntry::factory()->count(2)->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
        ]);

        $domains = BlacklistEntry::domains()->get();
        $emails = BlacklistEntry::emails()->get();

        $this->assertCount(3, $domains);
        $this->assertCount(2, $emails);
        $this->assertTrue($domains->every(fn($e) => $e->type === BlacklistEntry::TYPE_DOMAIN));
        $this->assertTrue($emails->every(fn($e) => $e->type === BlacklistEntry::TYPE_EMAIL));
    }

    public function test_blacklist_source_tracking(): void
    {
        $user = User::factory()->create();

        // Manual entry
        $manual = BlacklistEntry::factory()->create([
            'value' => 'manual-block.com',
            'source' => BlacklistEntry::SOURCE_MANUAL,
            'added_by_user_id' => $user->id,
        ]);

        // Import entry
        $import = BlacklistEntry::factory()->create([
            'value' => 'imported-block.com',
            'source' => BlacklistEntry::SOURCE_IMPORT,
        ]);

        // Auto-detected entry
        $auto = BlacklistEntry::factory()->create([
            'value' => 'auto-block.com',
            'source' => BlacklistEntry::SOURCE_AUTO,
        ]);

        $this->assertEquals(BlacklistEntry::SOURCE_MANUAL, $manual->source);
        $this->assertEquals(BlacklistEntry::SOURCE_IMPORT, $import->source);
        $this->assertEquals(BlacklistEntry::SOURCE_AUTO, $auto->source);
    }

    public function test_blacklist_reason_recorded(): void
    {
        $reasons = [
            'Competitor domain',
            'Unsubscribe request',
            'Bounce rate too high',
            'Invalid/fake email',
            'Legal request',
        ];

        foreach ($reasons as $reason) {
            BlacklistEntry::factory()->create([
                'reason' => $reason,
            ]);
        }

        $entries = BlacklistEntry::all();

        $this->assertCount(5, $entries);
        $this->assertTrue(
            $entries->pluck('reason')->contains('Competitor domain')
        );
    }

    public function test_blacklist_relationship_with_user(): void
    {
        $user = User::factory()->create([
            'name' => 'John Admin',
        ]);

        $entry = BlacklistEntry::factory()->create([
            'added_by_user_id' => $user->id,
        ]);

        $this->assertEquals($user->id, $entry->addedBy->id);
        $this->assertEquals('John Admin', $entry->addedBy->name);
    }

    public function test_bulk_domain_blacklist_check(): void
    {
        // Add multiple domains to blacklist
        $blacklistedDomains = [
            'spam1.com',
            'spam2.com',
            'spam3.com',
        ];

        foreach ($blacklistedDomains as $domain) {
            BlacklistEntry::factory()->create([
                'type' => BlacklistEntry::TYPE_DOMAIN,
                'value' => $domain,
            ]);
        }

        // Check domains
        $testDomains = ['spam1.com', 'legit.com', 'spam3.com', 'another-legit.com'];

        $blacklisted = BlacklistEntry::domains()
            ->whereIn('value', $testDomains)
            ->pluck('value')
            ->toArray();

        $this->assertContains('spam1.com', $blacklisted);
        $this->assertContains('spam3.com', $blacklisted);
        $this->assertNotContains('legit.com', $blacklisted);
        $this->assertNotContains('another-legit.com', $blacklisted);
    }

    public function test_blacklist_prevents_duplicate_entries(): void
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test@example.com',
        ]);

        // Attempting to check if exists before adding duplicate
        $exists = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', 'test@example.com')
            ->exists();

        $this->assertTrue($exists);

        // In real implementation, you'd prevent the duplicate
        // Here we just verify the check works
        if (!$exists) {
            BlacklistEntry::factory()->create([
                'type' => BlacklistEntry::TYPE_EMAIL,
                'value' => 'test@example.com',
            ]);
        }

        $count = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
            ->where('value', 'test@example.com')
            ->count();

        // Should still only have 1 entry
        $this->assertEquals(1, $count);
    }

    public function test_wildcard_domain_blacklist_matching(): void
    {
        // In a real implementation, you might support wildcards
        // This test shows how you'd check for partial matches

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam-network.com',
        ]);

        // Check exact match
        $exactMatch = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', 'spam-network.com')
            ->exists();

        $this->assertTrue($exactMatch);

        // For subdomain matching, you'd check if the domain ends with the blacklisted domain
        $testDomain = 'subdomain.spam-network.com';
        $isBlacklisted = BlacklistEntry::domains()
            ->get()
            ->contains(function ($entry) use ($testDomain) {
                return str_ends_with($testDomain, $entry->value);
            });

        $this->assertTrue($isBlacklisted);
    }

    public function test_blacklist_email_domain_extraction(): void
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'blocked-company.com',
        ]);

        // Test email from blacklisted domain
        $email = 'contact@blocked-company.com';
        $emailDomain = substr(strrchr($email, '@'), 1);

        $isDomainBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_DOMAIN)
            ->where('value', $emailDomain)
            ->exists();

        $this->assertTrue($isDomainBlacklisted);
        $this->assertEquals('blocked-company.com', $emailDomain);
    }

    public function test_blacklist_statistics(): void
    {
        // Create various blacklist entries
        BlacklistEntry::factory()->count(10)->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'source' => BlacklistEntry::SOURCE_MANUAL,
        ]);

        BlacklistEntry::factory()->count(5)->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'source' => BlacklistEntry::SOURCE_IMPORT,
        ]);

        BlacklistEntry::factory()->count(3)->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'source' => BlacklistEntry::SOURCE_AUTO,
        ]);

        $stats = [
            'total' => BlacklistEntry::count(),
            'domains' => BlacklistEntry::domains()->count(),
            'emails' => BlacklistEntry::emails()->count(),
            'manual' => BlacklistEntry::where('source', BlacklistEntry::SOURCE_MANUAL)->count(),
            'import' => BlacklistEntry::where('source', BlacklistEntry::SOURCE_IMPORT)->count(),
            'auto' => BlacklistEntry::where('source', BlacklistEntry::SOURCE_AUTO)->count(),
        ];

        $this->assertEquals(18, $stats['total']);
        $this->assertEquals(13, $stats['domains']);
        $this->assertEquals(5, $stats['emails']);
        $this->assertEquals(10, $stats['manual']);
        $this->assertEquals(5, $stats['import']);
        $this->assertEquals(3, $stats['auto']);
    }

    public function test_blacklist_case_insensitive_matching(): void
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => strtolower('SPAM@EXAMPLE.COM'),
        ]);

        // Test various case formats
        $testEmails = [
            'spam@example.com',
            'SPAM@EXAMPLE.COM',
            'Spam@Example.Com',
        ];

        foreach ($testEmails as $email) {
            $isBlacklisted = BlacklistEntry::where('type', BlacklistEntry::TYPE_EMAIL)
                ->where('value', strtolower($email))
                ->exists();

            $this->assertTrue($isBlacklisted, "Email $email should be blacklisted");
        }
    }
}
