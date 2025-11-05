<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\Website;
use App\Services\DuplicatePreventionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicatePreventionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DuplicatePreventionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test config values
        config([
            'mail.duplicate_prevention.cooldown_days' => 90,
            'mail.duplicate_prevention.max_contacts_per_website' => 3,
            'mail.duplicate_prevention.global_cooldown_days' => 30,
        ]);

        $this->service = app(DuplicatePreventionService::class);
    }

    public function test_it_allows_contact_that_has_never_been_contacted(): void
    {
        $contact = Contact::factory()->create();

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
        $this->assertEmpty($result['reasons']);
    }

    public function test_it_blocks_contact_that_was_recently_contacted(): void
    {
        $contact = Contact::factory()->create();

        // Create recent email log
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $contact->website_id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(30),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringContainsString('Contact was emailed', $result['reasons'][0]);
    }

    public function test_it_allows_contact_after_cooldown_period(): void
    {
        $contact = Contact::factory()->create();

        // Create old email log (91 days ago, cooldown is 90)
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $contact->website_id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(91),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
        $this->assertEmpty($result['reasons']);
    }

    public function test_it_ignores_failed_email_logs(): void
    {
        $contact = Contact::factory()->create();

        // Failed emails shouldn't count
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $contact->website_id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_FAILED,
            'sent_at' => now()->subDays(1),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
    }

    public function test_it_blocks_when_website_has_been_contacted_too_many_times(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        // Create 3 recent contacts to this website (max is 3)
        EmailSentLog::factory()->count(3)->create([
            'website_id' => $website->id,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(15),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringContainsString('Website has been contacted', $result['reasons'][0]);
    }

    public function test_it_allows_when_website_contacts_are_within_limit(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        // Create 2 recent contacts (under limit of 3)
        EmailSentLog::factory()->count(2)->create([
            'website_id' => $website->id,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(15),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
    }

    public function test_it_allows_when_website_contacts_are_outside_cooldown(): void
    {
        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);

        // Create 3 contacts but outside the 30-day window
        EmailSentLog::factory()->count(3)->create([
            'website_id' => $website->id,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(31),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
    }

    public function test_it_blocks_when_email_domain_has_been_contacted_too_many_times(): void
    {
        $contact = Contact::factory()->create(['email' => 'user1@example.com']);

        // Contact same domain twice
        EmailSentLog::factory()->count(2)->create([
            'recipient_email' => 'different@example.com',
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(15),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringContainsString('Domain example.com has been contacted', $result['reasons'][0]);
    }

    public function test_it_allows_first_contact_to_domain(): void
    {
        $contact = Contact::factory()->create(['email' => 'user@example.com']);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
    }

    public function test_it_allows_when_domain_has_only_one_recent_contact(): void
    {
        $contact = Contact::factory()->create(['email' => 'user1@example.com']);

        EmailSentLog::factory()->create([
            'recipient_email' => 'user2@example.com',
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(15),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
    }

    public function test_it_allows_when_domain_contacts_are_outside_cooldown(): void
    {
        $contact = Contact::factory()->create(['email' => 'user1@example.com']);

        EmailSentLog::factory()->count(2)->create([
            'recipient_email' => 'user2@example.com',
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(31),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertTrue($result['safe']);
    }

    public function test_it_accumulates_multiple_blocking_reasons(): void
    {
        $contact = Contact::factory()->create(['email' => 'user@example.com']);

        // Block reason 1: Contact was recently contacted
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $contact->website_id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(30),
        ]);

        // Block reason 2: Website contacted too many times
        EmailSentLog::factory()->count(3)->create([
            'website_id' => $contact->website_id,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(15),
        ]);

        // Block reason 3: Domain contacted too many times
        EmailSentLog::factory()->count(2)->create([
            'recipient_email' => 'other@example.com',
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(10),
        ]);

        $result = $this->service->isSafeToContact($contact);

        $this->assertFalse($result['safe']);
        $this->assertCount(3, $result['reasons']);
    }

    public function test_it_records_email_successfully(): void
    {
        $contact = Contact::factory()->create();

        $data = [
            'website_id' => $contact->website_id,
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
            'recipient_name' => $contact->name,
            'subject_template' => 'Test Subject',
            'body_template' => 'Test Body',
            'status' => EmailSentLog::STATUS_SENT,
        ];

        $log = $this->service->recordEmail($data);

        $this->assertInstanceOf(EmailSentLog::class, $log);
        $this->assertEquals($contact->id, $log->contact_id);
        $this->assertEquals($contact->email, $log->recipient_email);
        $this->assertNotNull($log->sent_at);

        $this->assertDatabaseHas('email_sent_log', [
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
        ]);
    }

    public function test_it_uses_current_time_when_sent_at_not_provided(): void
    {
        $contact = Contact::factory()->create();

        $data = [
            'website_id' => $contact->website_id,
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_SENT,
        ];

        $beforeTime = now();
        $log = $this->service->recordEmail($data);
        $afterTime = now();

        $this->assertNotNull($log->sent_at);
        $this->assertTrue($log->sent_at->between($beforeTime, $afterTime));
    }

    public function test_it_uses_provided_sent_at_time(): void
    {
        $contact = Contact::factory()->create();
        $specificTime = Carbon::parse('2024-01-15 10:00:00');

        $data = [
            'website_id' => $contact->website_id,
            'contact_id' => $contact->id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => $specificTime,
        ];

        $log = $this->service->recordEmail($data);

        $this->assertEquals($specificTime->format('Y-m-d H:i:s'), $log->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_get_safe_contacts_returns_validated_contacts_only(): void
    {
        $website = Website::factory()->create(['meets_requirements' => true]);

        $validatedContact = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $notValidatedContact = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => false,
            'contacted' => false,
        ]);

        $contacts = $this->service->getSafeContacts(10);

        $this->assertTrue($contacts->contains($validatedContact));
        $this->assertFalse($contacts->contains($notValidatedContact));
    }

    public function test_get_safe_contacts_excludes_already_contacted(): void
    {
        $website = Website::factory()->create(['meets_requirements' => true]);

        $notContactedYet = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $alreadyContacted = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => true,
        ]);

        $contacts = $this->service->getSafeContacts(10);

        $this->assertTrue($contacts->contains($notContactedYet));
        $this->assertFalse($contacts->contains($alreadyContacted));
    }

    public function test_get_safe_contacts_only_includes_qualified_websites(): void
    {
        $qualifiedWebsite = Website::factory()->create(['meets_requirements' => true]);
        $unqualifiedWebsite = Website::factory()->create(['meets_requirements' => false]);

        $contactFromQualified = Contact::factory()->create([
            'website_id' => $qualifiedWebsite->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $contactFromUnqualified = Contact::factory()->create([
            'website_id' => $unqualifiedWebsite->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $contacts = $this->service->getSafeContacts(10);

        $this->assertTrue($contacts->contains($contactFromQualified));
        $this->assertFalse($contacts->contains($contactFromUnqualified));
    }

    public function test_get_safe_contacts_filters_by_duplicate_prevention_rules(): void
    {
        $website = Website::factory()->create(['meets_requirements' => true]);

        $safeContact = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $unsafeContact = Contact::factory()->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        // Make unsafeContact unsafe by adding recent email
        EmailSentLog::factory()->create([
            'contact_id' => $unsafeContact->id,
            'website_id' => $unsafeContact->website_id,
            'recipient_email' => $unsafeContact->email,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(10),
        ]);

        $contacts = $this->service->getSafeContacts(10);

        $this->assertTrue($contacts->contains($safeContact));
        $this->assertFalse($contacts->contains($unsafeContact));
    }

    public function test_get_safe_contacts_respects_limit(): void
    {
        $website = Website::factory()->create(['meets_requirements' => true]);

        Contact::factory()->count(20)->create([
            'website_id' => $website->id,
            'is_validated' => true,
            'is_valid' => true,
            'contacted' => false,
        ]);

        $contacts = $this->service->getSafeContacts(5);

        $this->assertLessThanOrEqual(5, $contacts->count());
    }

    public function test_get_safe_contacts_returns_empty_when_no_safe_contacts(): void
    {
        $contacts = $this->service->getSafeContacts(10);

        $this->assertCount(0, $contacts);
    }

    public function test_extract_domain_returns_correct_domain(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractDomain');
        $method->setAccessible(true);

        $domain = $method->invoke($this->service, 'user@example.com');
        $this->assertEquals('example.com', $domain);

        $domain = $method->invoke($this->service, 'test@subdomain.example.com');
        $this->assertEquals('subdomain.example.com', $domain);
    }

    public function test_extract_domain_handles_email_without_at_sign(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractDomain');
        $method->setAccessible(true);

        $domain = $method->invoke($this->service, 'notanemail');
        $this->assertEquals('', $domain);
    }

    public function test_constructor_uses_config_values(): void
    {
        config([
            'mail.duplicate_prevention.cooldown_days' => 120,
            'mail.duplicate_prevention.max_contacts_per_website' => 5,
            'mail.duplicate_prevention.global_cooldown_days' => 45,
        ]);

        $service = new DuplicatePreventionService();

        $reflection = new \ReflectionClass($service);

        $cooldownProperty = $reflection->getProperty('cooldownDays');
        $cooldownProperty->setAccessible(true);
        $this->assertEquals(120, $cooldownProperty->getValue($service));

        $maxContactsProperty = $reflection->getProperty('maxContactsPerWebsite');
        $maxContactsProperty->setAccessible(true);
        $this->assertEquals(5, $maxContactsProperty->getValue($service));

        $globalCooldownProperty = $reflection->getProperty('globalCooldownDays');
        $globalCooldownProperty->setAccessible(true);
        $this->assertEquals(45, $globalCooldownProperty->getValue($service));
    }

    public function test_constructor_uses_default_values_when_config_missing(): void
    {
        config([
            'mail.duplicate_prevention.cooldown_days' => null,
            'mail.duplicate_prevention.max_contacts_per_website' => null,
            'mail.duplicate_prevention.global_cooldown_days' => null,
        ]);

        $service = new DuplicatePreventionService();

        $reflection = new \ReflectionClass($service);

        $cooldownProperty = $reflection->getProperty('cooldownDays');
        $cooldownProperty->setAccessible(true);
        $this->assertEquals(90, $cooldownProperty->getValue($service));

        $maxContactsProperty = $reflection->getProperty('maxContactsPerWebsite');
        $maxContactsProperty->setAccessible(true);
        $this->assertEquals(3, $maxContactsProperty->getValue($service));

        $globalCooldownProperty = $reflection->getProperty('globalCooldownDays');
        $globalCooldownProperty->setAccessible(true);
        $this->assertEquals(30, $globalCooldownProperty->getValue($service));
    }

    public function test_it_handles_edge_case_at_exact_cooldown_boundary(): void
    {
        $contact = Contact::factory()->create();

        // Email sent exactly 90 days ago (at the boundary)
        EmailSentLog::factory()->create([
            'contact_id' => $contact->id,
            'website_id' => $contact->website_id,
            'recipient_email' => $contact->email,
            'status' => EmailSentLog::STATUS_SENT,
            'sent_at' => now()->subDays(90),
        ]);

        $result = $this->service->isSafeToContact($contact);

        // Should still be blocked (>= check in code)
        $this->assertFalse($result['safe']);
    }
}
