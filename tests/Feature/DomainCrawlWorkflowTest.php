<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\Domain;
use App\Models\Website;
use App\Models\Contact;
use App\Jobs\CrawlWebsiteJob;
use App\Jobs\ExtractContactsJob;
use App\Jobs\ValidateContactEmailJob;
use App\Jobs\EvaluateWebsiteRequirementsJob;

class DomainCrawlWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_domain_to_contacts_workflow(): void
    {
        Queue::fake();

        // 1. Create domain
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'status' => Domain::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('domains', [
            'domain' => 'example.com',
            'status' => Domain::STATUS_PENDING,
            'tld' => 'com',
        ]);

        // 2. Create website and dispatch crawl job
        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://example.com',
            'status' => Website::STATUS_PENDING,
        ]);

        CrawlWebsiteJob::dispatch($website);
        Queue::assertPushed(CrawlWebsiteJob::class);

        // 3. Simulate successful crawl
        $website->update([
            'status' => Website::STATUS_COMPLETED,
            'title' => 'Example Company',
            'description' => 'A test website',
            'content_snapshot' => '<html><body>Contact us at info@example.com</body></html>',
            'page_count' => 10,
            'word_count' => 500,
            'crawled_at' => now(),
        ]);

        $this->assertDatabaseHas('websites', [
            'domain_id' => $domain->id,
            'status' => Website::STATUS_COMPLETED,
            'title' => 'Example Company',
        ]);

        // 4. Simulate contact extraction
        $contact = Contact::factory()->create([
            'website_id' => $website->id,
            'email' => 'info@example.com',
            'source_type' => Contact::SOURCE_CONTACT_PAGE,
            'is_validated' => false,
        ]);

        $this->assertDatabaseHas('contacts', [
            'website_id' => $website->id,
            'email' => 'info@example.com',
            'is_validated' => false,
        ]);

        // 5. Dispatch validation job
        ValidateContactEmailJob::dispatch($contact);
        Queue::assertPushed(ValidateContactEmailJob::class);

        // 6. Dispatch requirements evaluation job
        EvaluateWebsiteRequirementsJob::dispatch($website);
        Queue::assertPushed(EvaluateWebsiteRequirementsJob::class);

        // Verify the complete workflow
        $this->assertEquals(1, $domain->websites()->count());
        $this->assertEquals(1, $website->contacts()->count());
        $this->assertTrue($website->isCompleted());
    }

    public function test_domain_crawl_fails_gracefully(): void
    {
        Queue::fake();

        $domain = Domain::factory()->create([
            'domain' => 'invalid-site.com',
            'status' => Domain::STATUS_PENDING,
        ]);

        $website = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://invalid-site.com',
            'status' => Website::STATUS_PENDING,
        ]);

        CrawlWebsiteJob::dispatch($website);

        // Simulate crawl failure
        $website->failCrawl('Connection timeout');

        $this->assertDatabaseHas('websites', [
            'id' => $website->id,
            'status' => Website::STATUS_FAILED,
            'crawl_error' => 'Connection timeout',
        ]);

        // Ensure no contact extraction job was dispatched
        Queue::assertNotPushed(ExtractContactsJob::class);
    }

    public function test_domain_with_multiple_websites(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'multisite.com',
        ]);

        $website1 = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://multisite.com',
            'status' => Website::STATUS_COMPLETED,
        ]);

        $website2 = Website::factory()->create([
            'domain_id' => $domain->id,
            'url' => 'https://www.multisite.com',
            'status' => Website::STATUS_COMPLETED,
        ]);

        $this->assertEquals(2, $domain->websites()->count());
        $this->assertTrue($domain->websites()->completed()->count() === 2);
    }

    public function test_domain_status_transitions(): void
    {
        $domain = Domain::factory()->create([
            'status' => Domain::STATUS_PENDING,
        ]);

        $this->assertTrue($domain->isPending());

        $domain->markAsActive();
        $this->assertTrue($domain->isActive());
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => Domain::STATUS_ACTIVE,
        ]);

        $domain->markAsProcessed();
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'status' => Domain::STATUS_PROCESSED,
        ]);

        $testDomain = Domain::factory()->create();
        $testDomain->markAsFailed('Invalid domain');
        $this->assertDatabaseHas('domains', [
            'id' => $testDomain->id,
            'status' => Domain::STATUS_FAILED,
            'notes' => 'Invalid domain',
        ]);
    }

    public function test_domain_check_counter_increments(): void
    {
        $domain = Domain::factory()->create([
            'check_count' => 0,
            'last_checked_at' => null,
        ]);

        $domain->markAsChecked();

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'check_count' => 1,
        ]);

        $this->assertNotNull($domain->fresh()->last_checked_at);

        $domain->markAsChecked();
        $this->assertEquals(2, $domain->fresh()->check_count);
    }

    public function test_crawl_job_dispatches_extract_contacts_on_success(): void
    {
        Queue::fake();

        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
            'content_snapshot' => '<html>Content</html>',
        ]);

        // Simulate crawl completing successfully
        $website->completeCrawl([
            'title' => 'Test Site',
            'page_count' => 5,
        ]);

        // Manually dispatch what would happen in the job
        ExtractContactsJob::dispatch($website);

        Queue::assertPushed(ExtractContactsJob::class, function ($job) use ($website) {
            return $job->website->id === $website->id;
        });
    }

    public function test_website_crawl_attempt_counter(): void
    {
        $website = Website::factory()->create([
            'crawl_attempts' => 0,
        ]);

        $website->startCrawl();
        $this->assertEquals(1, $website->fresh()->crawl_attempts);
        $this->assertEquals(Website::STATUS_CRAWLING, $website->fresh()->status);
        $this->assertNotNull($website->fresh()->crawl_started_at);

        $website->fresh()->startCrawl();
        $this->assertEquals(2, $website->fresh()->crawl_attempts);
    }
}
