<?php

namespace Tests\Unit\Services;

use App\Models\BlacklistEntry;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Website;
use App\Services\BlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BlacklistServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BlacklistService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BlacklistService();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test */
    public function it_checks_if_email_is_blacklisted()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@example.com',
            'is_active' => true,
        ]);

        $result = $this->service->isEmailBlacklisted('blocked@example.com');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_for_non_blacklisted_email()
    {
        $result = $this->service->isEmailBlacklisted('clean@example.com');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_checks_email_case_insensitively()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@example.com',
            'is_active' => true,
        ]);

        $result = $this->service->isEmailBlacklisted('BLOCKED@EXAMPLE.COM');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_ignores_inactive_email_entries()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@example.com',
            'is_active' => false,
        ]);

        $result = $this->service->isEmailBlacklisted('blocked@example.com');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_caches_email_blacklist_check()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@example.com',
            'is_active' => true,
        ]);

        // First call - hits database
        $this->service->isEmailBlacklisted('blocked@example.com');

        // Verify cache was set
        $this->assertTrue(Cache::has('blacklist:email:blocked@example.com'));

        // Delete from database
        BlacklistEntry::where('value', 'blocked@example.com')->delete();

        // Second call - should still return true from cache
        $result = $this->service->isEmailBlacklisted('blocked@example.com');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_checks_if_domain_is_blacklisted()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
            'is_active' => true,
        ]);

        $result = $this->service->isDomainBlacklisted('spam.com');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_for_non_blacklisted_domain()
    {
        $result = $this->service->isDomainBlacklisted('clean.com');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_checks_domain_case_insensitively()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
            'is_active' => true,
        ]);

        $result = $this->service->isDomainBlacklisted('SPAM.COM');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_caches_domain_blacklist_check()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
            'is_active' => true,
        ]);

        $this->service->isDomainBlacklisted('spam.com');

        $this->assertTrue(Cache::has('blacklist:domain:spam.com'));
    }

    /** @test */
    public function it_checks_contact_with_blacklisted_email()
    {
        $contact = Contact::factory()->create([
            'email' => 'blocked@example.com',
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@example.com',
            'is_active' => true,
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertTrue($result['blacklisted']);
        $this->assertContains('Email address is blacklisted', $result['reasons']);
    }

    /** @test */
    public function it_checks_contact_with_blacklisted_email_domain()
    {
        $contact = Contact::factory()->create([
            'email' => 'user@spam.com',
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
            'is_active' => true,
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertTrue($result['blacklisted']);
        $this->assertContains('Email domain is blacklisted', $result['reasons']);
    }

    /** @test */
    public function it_checks_contact_with_blacklisted_website_domain()
    {
        $domain = Domain::factory()->create(['domain' => 'badwebsite.com']);
        $website = Website::factory()->create(['domain_id' => $domain->id]);
        $contact = Contact::factory()->create([
            'email' => 'user@example.com',
            'website_id' => $website->id,
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'badwebsite.com',
            'is_active' => true,
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertTrue($result['blacklisted']);
        $this->assertContains('Website domain is blacklisted', $result['reasons']);
    }

    /** @test */
    public function it_returns_multiple_blacklist_reasons()
    {
        $domain = Domain::factory()->create(['domain' => 'spam.com']);
        $website = Website::factory()->create(['domain_id' => $domain->id]);
        $contact = Contact::factory()->create([
            'email' => 'blocked@spam.com',
            'website_id' => $website->id,
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'blocked@spam.com',
            'is_active' => true,
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
            'is_active' => true,
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertTrue($result['blacklisted']);
        $this->assertCount(3, $result['reasons']); // Email, email domain, website domain
    }

    /** @test */
    public function it_returns_not_blacklisted_for_clean_contact()
    {
        $contact = Contact::factory()->create([
            'email' => 'clean@example.com',
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertFalse($result['blacklisted']);
        $this->assertEmpty($result['reasons']);
    }

    /** @test */
    public function it_adds_email_to_blacklist()
    {
        $entry = $this->service->blacklistEmail(
            'spam@example.com',
            'Spam complaint',
            'manual'
        );

        $this->assertDatabaseHas('blacklist_entries', [
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'spam@example.com',
            'reason' => 'Spam complaint',
            'source' => 'manual',
            'is_active' => true,
        ]);

        $this->assertInstanceOf(BlacklistEntry::class, $entry);
    }

    /** @test */
    public function it_normalizes_email_when_blacklisting()
    {
        $this->service->blacklistEmail(
            ' SPAM@EXAMPLE.COM ',
            'Reason',
            'manual'
        );

        $this->assertDatabaseHas('blacklist_entries', [
            'value' => 'spam@example.com',
        ]);
    }

    /** @test */
    public function it_does_not_duplicate_email_entries()
    {
        $this->service->blacklistEmail('spam@example.com', 'Reason 1', 'manual');
        $this->service->blacklistEmail('spam@example.com', 'Reason 2', 'manual');

        $count = BlacklistEntry::where('value', 'spam@example.com')->count();

        $this->assertEquals(1, $count);
    }

    /** @test */
    public function it_clears_cache_when_blacklisting_email()
    {
        Cache::put('blacklist:email:spam@example.com', true, 3600);

        $this->service->blacklistEmail('spam@example.com', 'Reason', 'manual');

        $this->assertFalse(Cache::has('blacklist:email:spam@example.com'));
    }

    /** @test */
    public function it_adds_domain_to_blacklist()
    {
        $entry = $this->service->blacklistDomain(
            'spam.com',
            'Known spam domain',
            'auto'
        );

        $this->assertDatabaseHas('blacklist_entries', [
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
            'reason' => 'Known spam domain',
            'source' => 'auto',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_normalizes_domain_when_blacklisting()
    {
        $this->service->blacklistDomain(' SPAM.COM ', 'Reason', 'manual');

        $this->assertDatabaseHas('blacklist_entries', [
            'value' => 'spam.com',
        ]);
    }

    /** @test */
    public function it_clears_cache_when_blacklisting_domain()
    {
        Cache::put('blacklist:domain:spam.com', true, 3600);

        $this->service->blacklistDomain('spam.com', 'Reason', 'manual');

        $this->assertFalse(Cache::has('blacklist:domain:spam.com'));
    }

    /** @test */
    public function it_removes_email_from_blacklist()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'spam@example.com',
        ]);

        $result = $this->service->removeEmailFromBlacklist('spam@example.com');

        $this->assertTrue($result);
        $this->assertDatabaseMissing('blacklist_entries', [
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'spam@example.com',
        ]);
    }

    /** @test */
    public function it_returns_false_when_removing_non_existent_email()
    {
        $result = $this->service->removeEmailFromBlacklist('nonexistent@example.com');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_clears_cache_when_removing_email()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'spam@example.com',
        ]);

        Cache::put('blacklist:email:spam@example.com', true, 3600);

        $this->service->removeEmailFromBlacklist('spam@example.com');

        $this->assertFalse(Cache::has('blacklist:email:spam@example.com'));
    }

    /** @test */
    public function it_removes_domain_from_blacklist()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
        ]);

        $result = $this->service->removeDomainFromBlacklist('spam.com');

        $this->assertTrue($result);
        $this->assertDatabaseMissing('blacklist_entries', [
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'spam.com',
        ]);
    }

    /** @test */
    public function it_deactivates_blacklist_entry()
    {
        $entry = BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test@example.com',
            'is_active' => true,
        ]);

        $result = $this->service->deactivateEntry($entry);

        $this->assertTrue($result);
        $this->assertFalse($entry->fresh()->is_active);
    }

    /** @test */
    public function it_clears_cache_when_deactivating_entry()
    {
        $entry = BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test@example.com',
            'is_active' => true,
        ]);

        Cache::put('blacklist:email:test@example.com', true, 3600);

        $this->service->deactivateEntry($entry);

        $this->assertFalse(Cache::has('blacklist:email:test@example.com'));
    }

    /** @test */
    public function it_activates_blacklist_entry()
    {
        $entry = BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test@example.com',
            'is_active' => false,
        ]);

        $result = $this->service->activateEntry($entry);

        $this->assertTrue($result);
        $this->assertTrue($entry->fresh()->is_active);
    }

    /** @test */
    public function it_bulk_blacklists_valid_emails()
    {
        $emails = ['spam1@example.com', 'spam2@example.com', 'spam3@example.com'];

        $count = $this->service->bulkBlacklistEmails($emails, 'Bulk spam', 'bulk_import');

        $this->assertEquals(3, $count);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam1@example.com']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam2@example.com']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam3@example.com']);
    }

    /** @test */
    public function it_skips_invalid_emails_in_bulk_blacklist()
    {
        $emails = ['valid@example.com', 'invalid-email', 'another@valid.com'];

        $count = $this->service->bulkBlacklistEmails($emails, 'Bulk', 'bulk');

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'valid@example.com']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'another@valid.com']);
        $this->assertDatabaseMissing('blacklist_entries', ['value' => 'invalid-email']);
    }

    /** @test */
    public function it_bulk_blacklists_valid_domains()
    {
        $domains = ['spam1.com', 'spam2.com', 'spam3.org'];

        $count = $this->service->bulkBlacklistDomains($domains, 'Bulk spam', 'bulk_import');

        $this->assertEquals(3, $count);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam1.com']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam2.com']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam3.org']);
    }

    /** @test */
    public function it_skips_invalid_domains_in_bulk_blacklist()
    {
        $domains = ['valid.com', 'invalid domain', 'another.org'];

        $count = $this->service->bulkBlacklistDomains($domains, 'Bulk', 'bulk');

        $this->assertEquals(2, $count);
    }

    /** @test */
    public function it_auto_blacklists_hard_bounce()
    {
        $entry = $this->service->autoBlacklistFromBounce('bounce@example.com', 'hard');

        $this->assertInstanceOf(BlacklistEntry::class, $entry);
        $this->assertDatabaseHas('blacklist_entries', [
            'value' => 'bounce@example.com',
            'source' => 'auto_bounce',
            'reason' => 'Auto-blacklisted due to hard bounce',
        ]);
    }

    /** @test */
    public function it_does_not_blacklist_soft_bounce()
    {
        $entry = $this->service->autoBlacklistFromBounce('bounce@example.com', 'soft');

        $this->assertNull($entry);
    }

    /** @test */
    public function it_auto_blacklists_spam_complaint()
    {
        $entry = $this->service->autoBlacklistFromComplaint('complaint@example.com');

        $this->assertInstanceOf(BlacklistEntry::class, $entry);
        $this->assertDatabaseHas('blacklist_entries', [
            'value' => 'complaint@example.com',
            'source' => 'auto_complaint',
            'reason' => 'Auto-blacklisted due to spam complaint',
        ]);
    }

    /** @test */
    public function it_gets_active_entries()
    {
        BlacklistEntry::factory()->create(['is_active' => true]);
        BlacklistEntry::factory()->create(['is_active' => true]);
        BlacklistEntry::factory()->create(['is_active' => false]);

        $entries = $this->service->getActiveEntries();

        $this->assertCount(2, $entries);
    }

    /** @test */
    public function it_filters_active_entries_by_type()
    {
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_EMAIL, 'is_active' => true]);
        BlacklistEntry::factory()->create(['type' => BlacklistEntry::TYPE_DOMAIN, 'is_active' => true]);

        $emails = $this->service->getActiveEntries(BlacklistEntry::TYPE_EMAIL);

        $this->assertCount(1, $emails);
        $this->assertEquals(BlacklistEntry::TYPE_EMAIL, $emails->first()->type);
    }

    /** @test */
    public function it_searches_blacklist_entries()
    {
        BlacklistEntry::factory()->create(['value' => 'spam@example.com']);
        BlacklistEntry::factory()->create(['value' => 'test@spam.com']);
        BlacklistEntry::factory()->create(['value' => 'clean@example.com']);

        $results = $this->service->search('spam');

        $this->assertCount(2, $results);
    }

    /** @test */
    public function it_searches_by_type()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test@example.com',
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'test.com',
        ]);

        $results = $this->service->search('test', BlacklistEntry::TYPE_EMAIL);

        $this->assertCount(1, $results);
        $this->assertEquals(BlacklistEntry::TYPE_EMAIL, $results->first()->type);
    }

    /** @test */
    public function it_gets_blacklist_statistics()
    {
        BlacklistEntry::factory()->create(['is_active' => true, 'type' => BlacklistEntry::TYPE_EMAIL]);
        BlacklistEntry::factory()->create(['is_active' => false, 'type' => BlacklistEntry::TYPE_EMAIL]);
        BlacklistEntry::factory()->create(['is_active' => true, 'type' => BlacklistEntry::TYPE_DOMAIN]);
        BlacklistEntry::factory()->create(['is_active' => true, 'type' => BlacklistEntry::TYPE_EMAIL, 'source' => 'auto_bounce']);
        BlacklistEntry::factory()->create(['is_active' => true, 'type' => BlacklistEntry::TYPE_EMAIL, 'source' => 'manual']);

        $stats = $this->service->getStatistics();

        $this->assertEquals(5, $stats['total_entries']);
        $this->assertEquals(4, $stats['active_entries']);
        $this->assertEquals(1, $stats['inactive_entries']);
        $this->assertEquals(4, $stats['email_entries']);
        $this->assertEquals(1, $stats['domain_entries']);
        $this->assertEquals(1, $stats['auto_entries']);
        $this->assertEquals(1, $stats['manual_entries']);
    }

    /** @test */
    public function it_clears_all_cache()
    {
        Cache::put('test_key', 'value', 3600);

        $this->service->clearCache();

        $this->assertFalse(Cache::has('test_key'));
    }

    /** @test */
    public function it_extracts_domain_from_email()
    {
        $contact = Contact::factory()->create(['email' => 'user@example.com']);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'example.com',
            'is_active' => true,
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertTrue($result['blacklisted']);
    }

    /** @test */
    public function it_handles_email_without_at_symbol()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractDomain');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'invalid-email');

        $this->assertNull($result);
    }

    /** @test */
    public function it_validates_domain_format()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isValidDomain');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, 'example.com'));
        $this->assertTrue($method->invoke($this->service, 'sub.example.com'));
        $this->assertFalse($method->invoke($this->service, 'invalid domain'));
        $this->assertFalse($method->invoke($this->service, '-invalid.com'));
    }

    /** @test */
    public function it_imports_from_csv_file()
    {
        $csvPath = storage_path('test-blacklist.csv');
        $handle = fopen($csvPath, 'w');
        fputcsv($handle, ['Value', 'Reason', 'Source']);
        fputcsv($handle, ['spam1@example.com', 'Spam', 'import']);
        fputcsv($handle, ['spam2@example.com', 'Spam', 'import']);
        fclose($handle);

        $result = $this->service->importFromCsv($csvPath, BlacklistEntry::TYPE_EMAIL);

        $this->assertEquals(2, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam1@example.com']);
        $this->assertDatabaseHas('blacklist_entries', ['value' => 'spam2@example.com']);

        unlink($csvPath);
    }

    /** @test */
    public function it_skips_empty_rows_in_csv()
    {
        $csvPath = storage_path('test-blacklist.csv');
        $handle = fopen($csvPath, 'w');
        fputcsv($handle, ['Value', 'Reason', 'Source']);
        fputcsv($handle, ['spam@example.com', 'Spam', 'import']);
        fputcsv($handle, ['', '', '']); // Empty row
        fclose($handle);

        $result = $this->service->importFromCsv($csvPath, BlacklistEntry::TYPE_EMAIL);

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(1, $result['skipped']);

        unlink($csvPath);
    }

    /** @test */
    public function it_throws_exception_for_missing_csv_file()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->service->importFromCsv('/nonexistent/file.csv', BlacklistEntry::TYPE_EMAIL);
    }

    /** @test */
    public function it_exports_to_csv_file()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test1@example.com',
            'is_active' => true,
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test2@example.com',
            'is_active' => true,
        ]);

        $csvPath = storage_path('export-blacklist.csv');

        $count = $this->service->exportToCsv($csvPath);

        $this->assertEquals(2, $count);
        $this->assertFileExists($csvPath);

        $content = file_get_contents($csvPath);
        $this->assertStringContainsString('test1@example.com', $content);
        $this->assertStringContainsString('test2@example.com', $content);

        unlink($csvPath);
    }

    /** @test */
    public function it_exports_only_specified_type()
    {
        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_EMAIL,
            'value' => 'test@example.com',
            'is_active' => true,
        ]);

        BlacklistEntry::factory()->create([
            'type' => BlacklistEntry::TYPE_DOMAIN,
            'value' => 'test.com',
            'is_active' => true,
        ]);

        $csvPath = storage_path('export-blacklist.csv');

        $count = $this->service->exportToCsv($csvPath, BlacklistEntry::TYPE_EMAIL);

        $this->assertEquals(1, $count);

        unlink($csvPath);
    }

    /** @test */
    public function it_handles_contact_without_website()
    {
        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
            'website_id' => null,
        ]);

        $result = $this->service->isContactBlacklisted($contact);

        $this->assertFalse($result['blacklisted']);
    }

    /** @test */
    public function it_trims_whitespace_from_email_and_domain()
    {
        $this->service->blacklistEmail('  test@example.com  ', 'Reason', 'manual');

        $this->assertDatabaseHas('blacklist_entries', [
            'value' => 'test@example.com',
        ]);
    }

    /** @test */
    public function it_uses_default_reason_in_csv_import()
    {
        $csvPath = storage_path('test-blacklist.csv');
        $handle = fopen($csvPath, 'w');
        fputcsv($handle, ['Value', 'Reason', 'Source']);
        fputcsv($handle, ['spam@example.com', '', '']); // Empty reason and source
        fclose($handle);

        $this->service->importFromCsv($csvPath, BlacklistEntry::TYPE_EMAIL);

        $this->assertDatabaseHas('blacklist_entries', [
            'value' => 'spam@example.com',
            'reason' => 'Imported from CSV',
            'source' => 'csv_import',
        ]);

        unlink($csvPath);
    }
}
