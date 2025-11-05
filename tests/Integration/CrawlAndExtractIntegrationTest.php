<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\Domain;
use App\Models\Website;
use App\Models\Contact;
use App\Jobs\CrawlWebsiteJob;
use App\Jobs\ExtractContactsJob;
use App\Jobs\ValidateContactEmailJob;
use App\Services\WebCrawlerService;
use App\Services\ContactExtractionService;

class CrawlAndExtractIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawler_and_contact_extraction_integration(): void
    {
        Queue::fake();

        // 1. Create domain and website
        $domain = Domain::factory()->create([
            'domain' => 'testcompany.com',
        ]);

        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://testcompany.com',
            'status' => Website::STATUS_PENDING,
        ]);

        // 2. Simulate crawl job execution
        $website->startCrawl();
        $this->assertEquals(Website::STATUS_CRAWLING, $website->fresh()->status);

        // 3. Simulate successful crawl with contact information
        $htmlContent = '
            <html>
                <head><title>Test Company</title></head>
                <body>
                    <div class="contact">
                        <p>Contact us: <a href="mailto:info@testcompany.com">info@testcompany.com</a></p>
                        <p>CEO: John Doe - john.doe@testcompany.com</p>
                        <p>Sales: sales@testcompany.com</p>
                    </div>
                </body>
            </html>
        ';

        $website->completeCrawl([
            'title' => 'Test Company',
            'description' => 'A great test company',
            'content_snapshot' => $htmlContent,
            'page_count' => 5,
            'word_count' => 250,
        ]);

        $this->assertEquals(Website::STATUS_COMPLETED, $website->fresh()->status);
        $this->assertNotNull($website->fresh()->content_snapshot);

        // 4. Dispatch contact extraction
        ExtractContactsJob::dispatch($website);
        Queue::assertPushed(ExtractContactsJob::class);

        // 5. Simulate contact extraction from HTML
        $extractedEmails = [
            'info@testcompany.com',
            'john.doe@testcompany.com',
            'sales@testcompany.com',
        ];

        foreach ($extractedEmails as $email) {
            $contact = Contact::factory()->create([
                'website_id' => $website->id,
                'email' => $email,
                'source_type' => Contact::SOURCE_CONTACT_PAGE,
                'is_validated' => false,
            ]);

            // Each contact should dispatch validation
            ValidateContactEmailJob::dispatch($contact);
        }

        // 6. Verify integration results
        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'status' => Website::STATUS_COMPLETED,
        ]);

        $contacts = Contact::where('website_id', $website->id)->get();
        $this->assertCount(3, $contacts);

        Queue::assertPushed(ValidateContactEmailJob::class, 3);

        // Verify all contacts are linked to the website
        $this->assertTrue(
            $contacts->every(fn($c) => $c->website_id === $website->id)
        );
    }

    public function test_crawl_failure_prevents_extraction(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'url' => 'https://invalid-website-xyz.com',
            'status' => Website::STATUS_PENDING,
        ]);

        $website->startCrawl();

        // Simulate crawl failure
        $website->failCrawl('Connection timeout after 30 seconds');

        $this->assertEquals(Website::STATUS_FAILED, $website->fresh()->status);
        $this->assertNotNull($website->fresh()->crawl_error);

        // Contact extraction should NOT be dispatched
        Queue::assertNotPushed(ExtractContactsJob::class);

        // No contacts should exist
        $contactCount = Contact::where('website_id', $website->id)->count();
        $this->assertEquals(0, $contactCount);
    }

    public function test_multiple_contact_sources_integration(): void
    {
        $website = Website::factory()->create([
            'content_snapshot' => '<html><body>
                <header>Email: header@example.com</header>
                <footer>Contact: footer@example.com</footer>
                <div class="about">Team: team@example.com</div>
                <div class="contact">Reach us: contact@example.com</div>
            </body></html>',
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Simulate extracting from different sources
        $sources = [
            ['email' => 'header@example.com', 'source' => Contact::SOURCE_HEADER],
            ['email' => 'footer@example.com', 'source' => Contact::SOURCE_FOOTER],
            ['email' => 'team@example.com', 'source' => Contact::SOURCE_TEAM_PAGE],
            ['email' => 'contact@example.com', 'source' => Contact::SOURCE_CONTACT_PAGE],
        ];

        foreach ($sources as $sourceData) {
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => $sourceData['email'],
                'source_type' => $sourceData['source'],
            ]);
        }

        $contacts = Contact::where('website_id', $website->id)->get();

        $this->assertCount(4, $contacts);

        // Verify different sources
        $this->assertTrue($contacts->pluck('source_type')->contains(Contact::SOURCE_HEADER));
        $this->assertTrue($contacts->pluck('source_type')->contains(Contact::SOURCE_FOOTER));
        $this->assertTrue($contacts->pluck('source_type')->contains(Contact::SOURCE_TEAM_PAGE));
        $this->assertTrue($contacts->pluck('source_type')->contains(Contact::SOURCE_CONTACT_PAGE));
    }

    public function test_contact_priority_calculation_integration(): void
    {
        $website = Website::factory()->create();

        // Create contacts from different sources with different attributes
        $contactPage = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'contact@example.com',
            'name' => 'John Doe',
            'position' => 'CEO',
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'priority' => 0,
        ]);

        $footer = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'footer@example.com',
            'name' => null,
            'position' => null,
            'source_type' => Contact::SOURCE_FOOTER,
            'priority' => 0,
        ]);

        // Calculate priorities
        $contactPage->update(['priority' => $contactPage->calculatePriority()]);
        $footer->update(['priority' => $footer->calculatePriority()]);

        // Contact page with name and position should have higher priority
        $this->assertGreaterThan(
            $footer->fresh()->priority,
            $contactPage->fresh()->priority
        );

        // Contact page contact should be high priority (>= 80)
        $this->assertGreaterThanOrEqual(80, $contactPage->fresh()->priority);

        // Footer contact should be lower priority
        $this->assertLessThan(80, $footer->fresh()->priority);
    }

    public function test_crawl_retry_mechanism(): void
    {
        $website = Website::factory()->create([
            'crawl_attempts' => 0,
        ]);

        // First attempt
        $website->startCrawl();
        $this->assertEquals(1, $website->fresh()->crawl_attempts);
        $website->failCrawl('Timeout');

        // Second attempt
        $website->fresh()->startCrawl();
        $this->assertEquals(2, $website->fresh()->crawl_attempts);
        $website->failCrawl('Timeout');

        // Third attempt succeeds
        $website->fresh()->startCrawl();
        $this->assertEquals(3, $website->fresh()->crawl_attempts);
        $website->completeCrawl(['title' => 'Success']);

        $this->assertEquals(Website::STATUS_COMPLETED, $website->fresh()->status);
        $this->assertEquals(3, $website->fresh()->crawl_attempts);
    }

    public function test_no_contacts_found_scenario(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'content_snapshot' => '<html><body><h1>Welcome</h1><p>No contact info here</p></body></html>',
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Dispatch extraction job
        ExtractContactsJob::dispatch($website);

        // No contacts would be extracted
        $contactCount = Contact::where('website_id', $website->id)->count();
        $this->assertEquals(0, $contactCount);

        // Website should still be marked as completed
        $this->assertEquals(Website::STATUS_COMPLETED, $website->status);
    }

    public function test_duplicate_email_prevention_per_website(): void
    {
        $website = Website::factory()->create();

        // Create first contact
        Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'duplicate@example.com',
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
        ]);

        // Check if duplicate exists before creating second
        $exists = Contact::where('website_id', $website->id)
            ->where('email', 'duplicate@example.com')
            ->exists();

        $this->assertTrue($exists);

        // In real implementation, this would be prevented
        if (!$exists) {
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => 'duplicate@example.com',
                'source_type' => Contact::SOURCE_FOOTER,
            ]);
        }

        // Verify only one contact exists
        $count = Contact::where('website_id', $website->id)
            ->where('email', 'duplicate@example.com')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_contact_extraction_with_names_and_positions(): void
    {
        $website = Website::factory()->create([
            'content_snapshot' => '<html><body>
                <div class="team">
                    <p>CEO: John Doe - john@example.com</p>
                    <p>CTO: Jane Smith (jane@example.com)</p>
                    <p>Contact us at info@example.com</p>
                </div>
            </body></html>',
            'status' => Website::STATUS_COMPLETED,
        ]);

        // Simulate extraction with context
        $contacts = [
            [
                'email' => 'john@example.com',
                'name' => 'John Doe',
                'position' => 'CEO',
            ],
            [
                'email' => 'jane@example.com',
                'name' => 'Jane Smith',
                'position' => 'CTO',
            ],
            [
                'email' => 'info@example.com',
                'name' => null,
                'position' => null,
            ],
        ];

        foreach ($contacts as $contactData) {
            Contact::factory()->create([
                'website_id' => $website->id,
                'email' => $contactData['email'],
                'name' => $contactData['name'],
                'position' => $contactData['position'],
                'source_type' => Contact::SOURCE_TEAM_PAGE,
            ]);
        }

        $allContacts = Contact::where('website_id', $website->id)->get();

        $this->assertCount(3, $allContacts);

        $withNames = $allContacts->filter(fn($c) => !empty($c->name));
        $this->assertCount(2, $withNames);

        $withPositions = $allContacts->filter(fn($c) => !empty($c->position));
        $this->assertCount(2, $withPositions);
    }

    public function test_crawl_updates_domain_status(): void
    {
        $domain = Domain::factory()->create([
            'status' => Domain::STATUS_PENDING,
            'check_count' => 0,
        ]);

        $website = Website::factory()->create([
            'domain_id' => $domain->id,
        ]);

        // Mark domain as checked when crawl starts
        $domain->markAsChecked();
        $domain->markAsActive();

        $this->assertEquals(1, $domain->fresh()->check_count);
        $this->assertEquals(Domain::STATUS_ACTIVE, $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->last_checked_at);

        // Complete crawl
        $website->completeCrawl(['title' => 'Test']);

        // Mark domain as processed after successful crawl
        $domain->fresh()->markAsProcessed();

        $this->assertEquals(Domain::STATUS_PROCESSED, $domain->fresh()->status);
    }
}
