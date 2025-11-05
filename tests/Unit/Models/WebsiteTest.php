<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Website;
use App\Models\Domain;
use App\Models\Contact;
use App\Models\EmailSentLog;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'domain_id',
            'url',
            'status',
            'title',
            'description',
            'detected_platform',
            'page_count',
            'word_count',
            'content_snapshot',
            'meets_requirements',
            'requirement_match_details',
            'crawled_at',
            'crawl_started_at',
            'crawl_attempts',
            'crawl_error',
        ];

        $website = new Website();

        $this->assertEquals($fillable, $website->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $website = Website::factory()->create([
            'status' => '1',
            'page_count' => '10',
            'word_count' => '500',
            'crawl_attempts' => '2',
            'meets_requirements' => true,
            'requirement_match_details' => ['key' => 'value'],
            'crawled_at' => '2024-01-01 10:00:00',
            'crawl_started_at' => '2024-01-01 09:00:00',
        ]);

        $this->assertIsInt($website->status);
        $this->assertIsInt($website->page_count);
        $this->assertIsInt($website->word_count);
        $this->assertIsInt($website->crawl_attempts);
        $this->assertIsBool($website->meets_requirements);
        $this->assertIsArray($website->requirement_match_details);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $website->crawled_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $website->crawl_started_at);
    }

    public function test_it_uses_soft_deletes(): void
    {
        $website = Website::factory()->create();

        $website->delete();

        $this->assertSoftDeleted('websites', ['id' => $website->id]);
        $this->assertNotNull($website->fresh()->deleted_at);
    }

    public function test_it_belongs_to_domain(): void
    {
        $domain = Domain::factory()->create();
        $website = Website::factory()->create(['domain_id' => $domain->id]);

        $this->assertInstanceOf(Domain::class, $website->domain);
        $this->assertEquals($domain->id, $website->domain->id);
    }

    public function test_it_has_contacts_relationship(): void
    {
        $website = Website::factory()->create();
        $contacts = Contact::factory()->count(3)->create(['website_id' => $website->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $website->contacts);
        $this->assertCount(3, $website->contacts);
        $this->assertInstanceOf(Contact::class, $website->contacts->first());
    }

    public function test_it_has_email_sent_logs_relationship(): void
    {
        $website = Website::factory()->create();
        $logs = EmailSentLog::factory()->count(2)->create(['website_id' => $website->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $website->emailSentLogs);
        $this->assertCount(2, $website->emailSentLogs);
        $this->assertInstanceOf(EmailSentLog::class, $website->emailSentLogs->first());
    }

    public function test_pending_scope_works(): void
    {
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_COMPLETED]);
        Website::factory()->create(['status' => Website::STATUS_PENDING]);

        $pending = Website::pending()->get();

        $this->assertCount(2, $pending);
        $pending->each(fn($website) => $this->assertEquals(Website::STATUS_PENDING, $website->status));
    }

    public function test_crawling_scope_works(): void
    {
        Website::factory()->create(['status' => Website::STATUS_CRAWLING]);
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_CRAWLING]);

        $crawling = Website::crawling()->get();

        $this->assertCount(2, $crawling);
        $crawling->each(fn($website) => $this->assertEquals(Website::STATUS_CRAWLING, $website->status));
    }

    public function test_completed_scope_works(): void
    {
        Website::factory()->create(['status' => Website::STATUS_COMPLETED]);
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_COMPLETED]);

        $completed = Website::completed()->get();

        $this->assertCount(2, $completed);
        $completed->each(fn($website) => $this->assertEquals(Website::STATUS_COMPLETED, $website->status));
    }

    public function test_failed_scope_works(): void
    {
        Website::factory()->create(['status' => Website::STATUS_FAILED]);
        Website::factory()->create(['status' => Website::STATUS_PENDING]);
        Website::factory()->create(['status' => Website::STATUS_FAILED]);

        $failed = Website::failed()->get();

        $this->assertCount(2, $failed);
        $failed->each(fn($website) => $this->assertEquals(Website::STATUS_FAILED, $website->status));
    }

    public function test_qualified_leads_scope_works(): void
    {
        Website::factory()->create(['meets_requirements' => true, 'status' => Website::STATUS_COMPLETED]);
        Website::factory()->create(['meets_requirements' => false, 'status' => Website::STATUS_COMPLETED]);
        Website::factory()->create(['meets_requirements' => true, 'status' => Website::STATUS_PENDING]);
        Website::factory()->create(['meets_requirements' => true, 'status' => Website::STATUS_COMPLETED]);

        $qualified = Website::qualifiedLeads()->get();

        $this->assertCount(2, $qualified);
        $qualified->each(function($website) {
            $this->assertTrue($website->meets_requirements);
            $this->assertEquals(Website::STATUS_COMPLETED, $website->status);
        });
    }

    public function test_start_crawl_updates_status_and_timestamp(): void
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_PENDING,
            'crawl_attempts' => 0,
        ]);

        $website->startCrawl();

        $fresh = $website->fresh();
        $this->assertEquals(Website::STATUS_CRAWLING, $fresh->status);
        $this->assertNotNull($fresh->crawl_started_at);
        $this->assertEquals(1, $fresh->crawl_attempts);
    }

    public function test_complete_crawl_updates_status_and_clears_error(): void
    {
        $website = Website::factory()->create([
            'status' => Website::STATUS_CRAWLING,
            'crawl_error' => 'Some error',
        ]);

        $website->completeCrawl(['title' => 'Test Title']);

        $fresh = $website->fresh();
        $this->assertEquals(Website::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->crawled_at);
        $this->assertNull($fresh->crawl_error);
        $this->assertEquals('Test Title', $fresh->title);
    }

    public function test_fail_crawl_updates_status_and_sets_error(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_CRAWLING]);

        $website->failCrawl('Connection timeout');

        $fresh = $website->fresh();
        $this->assertEquals(Website::STATUS_FAILED, $fresh->status);
        $this->assertEquals('Connection timeout', $fresh->crawl_error);
    }

    public function test_mark_as_qualified_sets_requirements(): void
    {
        $website = Website::factory()->create(['meets_requirements' => false]);

        $matchDetails = ['criteria' => 'passed'];
        $website->markAsQualified($matchDetails);

        $fresh = $website->fresh();
        $this->assertTrue($fresh->meets_requirements);
        $this->assertEquals($matchDetails, $fresh->requirement_match_details);
    }

    public function test_mark_as_unqualified_clears_requirements(): void
    {
        $website = Website::factory()->create(['meets_requirements' => true]);

        $matchDetails = ['criteria' => 'failed'];
        $website->markAsUnqualified($matchDetails);

        $fresh = $website->fresh();
        $this->assertFalse($fresh->meets_requirements);
        $this->assertEquals($matchDetails, $fresh->requirement_match_details);
    }

    public function test_is_qualified_returns_true_when_qualified(): void
    {
        $website = Website::factory()->create(['meets_requirements' => true]);

        $this->assertTrue($website->isQualified());
    }

    public function test_is_qualified_returns_false_when_not_qualified(): void
    {
        $website = Website::factory()->create(['meets_requirements' => false]);

        $this->assertFalse($website->isQualified());
    }

    public function test_is_pending_returns_true_when_pending(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_PENDING]);

        $this->assertTrue($website->isPending());
    }

    public function test_is_pending_returns_false_when_not_pending(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_COMPLETED]);

        $this->assertFalse($website->isPending());
    }

    public function test_is_crawling_returns_true_when_crawling(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_CRAWLING]);

        $this->assertTrue($website->isCrawling());
    }

    public function test_is_crawling_returns_false_when_not_crawling(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_PENDING]);

        $this->assertFalse($website->isCrawling());
    }

    public function test_is_completed_returns_true_when_completed(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_COMPLETED]);

        $this->assertTrue($website->isCompleted());
    }

    public function test_is_completed_returns_false_when_not_completed(): void
    {
        $website = Website::factory()->create(['status' => Website::STATUS_PENDING]);

        $this->assertFalse($website->isCompleted());
    }

    public function test_status_constants_are_defined(): void
    {
        $this->assertEquals(0, Website::STATUS_PENDING);
        $this->assertEquals(1, Website::STATUS_CRAWLING);
        $this->assertEquals(2, Website::STATUS_COMPLETED);
        $this->assertEquals(3, Website::STATUS_FAILED);
        $this->assertEquals(4, Website::STATUS_PER_REVIEW);
    }

    public function test_factory_creates_valid_website(): void
    {
        $website = Website::factory()->create();

        $this->assertInstanceOf(Website::class, $website);
        $this->assertNotNull($website->url);
        $this->assertDatabaseHas('websites', ['id' => $website->id]);
    }
}
