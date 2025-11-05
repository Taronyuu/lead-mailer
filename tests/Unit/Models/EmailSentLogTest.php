<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\EmailSentLog;
use App\Models\Website;
use App\Models\Contact;
use App\Models\SmtpCredential;
use App\Models\EmailTemplate;

class EmailSentLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_correct_table_name(): void
    {
        $log = new EmailSentLog();

        $this->assertEquals('email_sent_log', $log->getTable());
    }

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'website_id',
            'contact_id',
            'smtp_credential_id',
            'email_template_id',
            'recipient_email',
            'recipient_name',
            'subject',
            'body',
            'status',
            'error_message',
            'sent_at',
        ];

        $log = new EmailSentLog();

        $this->assertEquals($fillable, $log->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $log = EmailSentLog::factory()->create([
            'sent_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $log->sent_at);
    }

    public function test_it_does_not_use_soft_deletes(): void
    {
        $log = EmailSentLog::factory()->create();

        $log->delete();

        $this->assertDatabaseMissing('email_sent_log', ['id' => $log->id]);
    }

    public function test_it_belongs_to_website(): void
    {
        $website = Website::factory()->create();
        $log = EmailSentLog::factory()->create(['website_id' => $website->id]);

        $this->assertInstanceOf(Website::class, $log->website);
        $this->assertEquals($website->id, $log->website->id);
    }

    public function test_it_belongs_to_contact(): void
    {
        $contact = Contact::factory()->create();
        $log = EmailSentLog::factory()->create(['contact_id' => $contact->id]);

        $this->assertInstanceOf(Contact::class, $log->contact);
        $this->assertEquals($contact->id, $log->contact->id);
    }

    public function test_it_belongs_to_smtp_credential(): void
    {
        $credential = SmtpCredential::factory()->create();
        $log = EmailSentLog::factory()->create(['smtp_credential_id' => $credential->id]);

        $this->assertInstanceOf(SmtpCredential::class, $log->smtpCredential);
        $this->assertEquals($credential->id, $log->smtpCredential->id);
    }

    public function test_it_belongs_recipient_email_template(): void
    {
        $template = EmailTemplate::factory()->create();
        $log = EmailSentLog::factory()->create(['email_template_id' => $template->id]);

        $this->assertInstanceOf(EmailTemplate::class, $log->emailTemplate);
        $this->assertEquals($template->id, $log->emailTemplate->id);
    }

    public function test_successful_scope_works(): void
    {
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_SENT]);
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_FAILED]);
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_SENT]);

        $successful = EmailSentLog::successful()->get();

        $this->assertCount(2, $successful);
        $successful->each(fn($log) => $this->assertEquals(EmailSentLog::STATUS_SENT, $log->status));
    }

    public function test_failed_scope_works(): void
    {
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_FAILED]);
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_SENT]);
        EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_FAILED]);

        $failed = EmailSentLog::failed()->get();

        $this->assertCount(2, $failed);
        $failed->each(fn($log) => $this->assertEquals(EmailSentLog::STATUS_FAILED, $log->status));
    }

    public function test_today_scope_works(): void
    {
        EmailSentLog::factory()->create(['sent_at' => now()]);
        EmailSentLog::factory()->create(['sent_at' => now()->subDays(1)]);
        EmailSentLog::factory()->create(['sent_at' => now()]);

        $today = EmailSentLog::today()->get();

        $this->assertCount(2, $today);
        $today->each(fn($log) => $this->assertTrue($log->sent_at->isToday()));
    }

    public function test_status_constants_are_defined(): void
    {
        $this->assertEquals('sent', EmailSentLog::STATUS_SENT);
        $this->assertEquals('failed', EmailSentLog::STATUS_FAILED);
        $this->assertEquals('bounced', EmailSentLog::STATUS_BOUNCED);
    }

    public function test_factory_creates_valid_email_sent_log(): void
    {
        $log = EmailSentLog::factory()->create();

        $this->assertInstanceOf(EmailSentLog::class, $log);
        $this->assertNotNull($log->recipient_email);
        $this->assertNotNull($log->subject);
        $this->assertNotNull($log->status);
        $this->assertDatabaseHas('email_sent_log', ['id' => $log->id]);
    }

    public function test_factory_can_create_failed_log(): void
    {
        $log = EmailSentLog::factory()->create([
            'status' => EmailSentLog::STATUS_FAILED,
            'error_message' => 'SMTP connection failed',
        ]);

        $this->assertEquals(EmailSentLog::STATUS_FAILED, $log->status);
        $this->assertNotNull($log->error_message);
    }

    public function test_factory_can_create_bounced_log(): void
    {
        $log = EmailSentLog::factory()->create(['status' => EmailSentLog::STATUS_BOUNCED]);

        $this->assertEquals(EmailSentLog::STATUS_BOUNCED, $log->status);
    }

    public function test_all_relationships_can_be_null(): void
    {
        $log = EmailSentLog::factory()->create([
            'website_id' => null,
            'contact_id' => null,
            'smtp_credential_id' => null,
            'email_template_id' => null,
        ]);

        $this->assertNull($log->website_id);
        $this->assertNull($log->contact_id);
        $this->assertNull($log->smtp_credential_id);
        $this->assertNull($log->email_template_id);
    }
}
