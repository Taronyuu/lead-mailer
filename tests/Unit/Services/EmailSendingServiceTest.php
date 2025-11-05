<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use App\Services\BlacklistService;
use App\Services\DuplicatePreventionService;
use App\Services\EmailSendingService;
use App\Services\EmailTemplateService;
use App\Services\RateLimiterService;
use App\Services\SmtpRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class EmailSendingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EmailSendingService $service;
    protected $smtpRotationMock;
    protected $rateLimiterMock;
    protected $dupeServiceMock;
    protected $templateServiceMock;
    protected $blacklistServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smtpRotationMock = Mockery::mock(SmtpRotationService::class);
        $this->rateLimiterMock = Mockery::mock(RateLimiterService::class);
        $this->dupeServiceMock = Mockery::mock(DuplicatePreventionService::class);
        $this->templateServiceMock = Mockery::mock(EmailTemplateService::class);
        $this->blacklistServiceMock = Mockery::mock(BlacklistService::class);

        $this->service = new EmailSendingService(
            $this->smtpRotationMock,
            $this->rateLimiterMock,
            $this->dupeServiceMock,
            $this->templateServiceMock,
            $this->blacklistServiceMock
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_sends_email_successfully()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock
            ->shouldReceive('isWithinTimeWindow')
            ->once()
            ->andReturn(true);

        $this->blacklistServiceMock
            ->shouldReceive('isContactBlacklisted')
            ->once()
            ->with($contact)
            ->andReturn(['blacklisted' => false, 'reasons' => []]);

        $this->dupeServiceMock
            ->shouldReceive('isSafeToContact')
            ->once()
            ->with($contact)
            ->andReturn(['safe' => true, 'reasons' => []]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->once()
            ->with($template, $website, $contact)
            ->andReturn([
                'subject_template' => 'Test Subject',
                'body_template' => 'Test Body',
                'preheader' => 'Test Preheader',
            ]);

        $log = EmailSentLog::factory()->make(['id' => 1]);

        $this->dupeServiceMock
            ->shouldReceive('recordEmail')
            ->once()
            ->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $result = $this->service->send($contact, $template, $smtp);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['log_id']);
        $this->assertEquals($smtp->id, $result['smtp_id']);

        Mail::assertSent(function ($mail) use ($contact) {
            return true; // Mail was sent
        });
    }

    /** @test */
    public function it_fails_when_outside_time_window()
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $this->rateLimiterMock
            ->shouldReceive('isWithinTimeWindow')
            ->once()
            ->andReturn(false);

        $result = $this->service->send($contact, $template);

        $this->assertFalse($result['success']);
        $this->assertEquals('Outside allowed sending hours (8AM-5PM)', $result['error']);
    }

    /** @test */
    public function it_fails_when_contact_is_blacklisted()
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $this->rateLimiterMock
            ->shouldReceive('isWithinTimeWindow')
            ->once()
            ->andReturn(true);

        $this->blacklistServiceMock
            ->shouldReceive('isContactBlacklisted')
            ->once()
            ->with($contact)
            ->andReturn([
                'blacklisted' => true,
                'reasons' => ['Email address is blacklisted', 'Domain is blacklisted'],
            ]);

        $result = $this->service->send($contact, $template);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Blacklisted:', $result['error']);
        $this->assertStringContainsString('Email address is blacklisted', $result['error']);
    }

    /** @test */
    public function it_fails_when_duplicate_prevention_triggered()
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $this->rateLimiterMock
            ->shouldReceive('isWithinTimeWindow')
            ->once()
            ->andReturn(true);

        $this->blacklistServiceMock
            ->shouldReceive('isContactBlacklisted')
            ->once()
            ->andReturn(['blacklisted' => false, 'reasons' => []]);

        $this->dupeServiceMock
            ->shouldReceive('isSafeToContact')
            ->once()
            ->with($contact)
            ->andReturn([
                'safe' => false,
                'reasons' => ['Contact was emailed recently'],
            ]);

        $result = $this->service->send($contact, $template);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate prevention:', $result['error']);
        $this->assertStringContainsString('Contact was emailed recently', $result['error']);
    }

    /** @test */
    public function it_gets_available_smtp_when_not_provided()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);

        $this->smtpRotationMock
            ->shouldReceive('getAvailableSmtp')
            ->once()
            ->andReturn($smtp);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn(['subject_template' => 'Subject', 'body_template' => 'Body']);

        $log = EmailSentLog::factory()->make(['id' => 1]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $result = $this->service->send($contact, $template);

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function it_fails_when_no_smtp_available()
    {
        $contact = Contact::factory()->create();
        $template = EmailTemplate::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);

        $this->smtpRotationMock
            ->shouldReceive('getAvailableSmtp')
            ->once()
            ->andReturn(null);

        $result = $this->service->send($contact, $template);

        $this->assertFalse($result['success']);
        $this->assertEquals('No available SMTP accounts', $result['error']);
    }

    /** @test */
    public function it_renders_email_with_template_service()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->once()
            ->with($template, $website, $contact)
            ->andReturn([
                'subject_template' => 'Rendered Subject',
                'body_template' => 'Rendered Body',
                'preheader' => 'Rendered Preheader',
            ]);

        $log = EmailSentLog::factory()->make(['id' => 1]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $this->service->send($contact, $template, $smtp);

        Mail::assertSent(function ($mail) {
            return true;
        });
    }

    /** @test */
    public function it_configures_smtp_settings_before_sending()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'user@example.com',
            'password' => 'password123',
            'from_address' => 'from@example.com',
            'from_name' => 'John Doe',
        ]);

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);

        $log = EmailSentLog::factory()->make(['id' => 1]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $this->service->send($contact, $template, $smtp);

        $this->assertEquals('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertEquals(587, config('mail.mailers.smtp.port'));
        $this->assertEquals('tls', config('mail.mailers.smtp.encryption'));
        $this->assertEquals('user@example.com', config('mail.mailers.smtp.username'));
        $this->assertEquals('password123', config('mail.mailers.smtp.password'));
        $this->assertEquals('from@example.com', config('mail.from.address'));
        $this->assertEquals('John Doe', config('mail.from.name'));
    }

    /** @test */
    public function it_sends_email_with_preheader()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'website_id' => $website->id,
        ]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);

        $this->templateServiceMock
            ->shouldReceive('render')
            ->andReturn([
                'subject_template' => 'Test Subject',
                'body_template' => 'Test Body',
                'preheader' => 'This is a preheader',
            ]);

        $log = EmailSentLog::factory()->make(['id' => 1]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $this->service->send($contact, $template, $smtp);

        Mail::assertSent(function ($mail) {
            return true;
        });
    }

    /** @test */
    public function it_records_email_log_on_success()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);

        $log = EmailSentLog::factory()->make(['id' => 1]);

        $this->dupeServiceMock
            ->shouldReceive('recordEmail')
            ->once()
            ->withArgs(function ($data) use ($website, $contact, $smtp, $template) {
                return $data['website_id'] === $website->id &&
                    $data['contact_id'] === $contact->id &&
                    $data['smtp_credential_id'] === $smtp->id &&
                    $data['email_template_id'] === $template->id &&
                    $data['recipient_email'] === $contact->email &&
                    $data['subject'] === 'S' &&
                    $data['body'] === 'B' &&
                    $data['status'] === EmailSentLog::STATUS_SENT;
            })
            ->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $this->service->send($contact, $template, $smtp);
    }

    /** @test */
    public function it_increments_smtp_sent_count_on_success()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);

        $log = EmailSentLog::factory()->make(['id' => 1]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp
            ->shouldReceive('incrementSentCount')
            ->once();

        $template->shouldReceive('incrementUsage')->once();

        $this->service->send($contact, $template, $smtp);
    }

    /** @test */
    public function it_increments_template_usage_on_success()
    {
        Mail::fake();
        Log::shouldReceive('info')->twice();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);

        $log = EmailSentLog::factory()->make(['id' => 1]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();

        $template
            ->shouldReceive('incrementUsage')
            ->once();

        $this->service->send($contact, $template, $smtp);
    }

    /** @test */
    public function it_handles_exception_during_sending()
    {
        Mail::shouldReceive('raw')->andThrow(new \Exception('SMTP connection failed'));
        Log::shouldReceive('error')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);

        $this->dupeServiceMock
            ->shouldReceive('recordEmail')
            ->once()
            ->withArgs(function ($data) {
                return $data['status'] === EmailSentLog::STATUS_FAILED &&
                    $data['error_message'] === 'SMTP connection failed';
            })
            ->andReturn(EmailSentLog::factory()->make());

        $smtp
            ->shouldReceive('recordFailure')
            ->once();

        $result = $this->service->send($contact, $template, $smtp);

        $this->assertFalse($result['success']);
        $this->assertEquals('SMTP connection failed', $result['error']);
    }

    /** @test */
    public function it_records_failure_in_smtp_on_exception()
    {
        Mail::shouldReceive('raw')->andThrow(new \Exception('Error'));
        Log::shouldReceive('error')->once();

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn(EmailSentLog::factory()->make());

        $smtp
            ->shouldReceive('recordFailure')
            ->once();

        $this->service->send($contact, $template, $smtp);
    }

    /** @test */
    public function it_logs_success_information()
    {
        Mail::fake();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Email sent successfully' &&
                    isset($context['contact_id']) &&
                    isset($context['smtp_id']) &&
                    isset($context['log_id']);
            });

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);

        $log = EmailSentLog::factory()->make(['id' => 123]);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn($log);

        $smtp->shouldReceive('incrementSentCount')->once();
        $template->shouldReceive('incrementUsage')->once();

        $this->service->send($contact, $template, $smtp);
    }

    /** @test */
    public function it_logs_failure_information()
    {
        Mail::shouldReceive('raw')->andThrow(new \Exception('Test error'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Email send failed' &&
                    isset($context['contact_id']) &&
                    isset($context['smtp_id']) &&
                    $context['error'] === 'Test error';
            });

        $website = Website::factory()->create();
        $contact = Contact::factory()->create(['website_id' => $website->id]);
        $template = EmailTemplate::factory()->create();
        $smtp = SmtpCredential::factory()->create();

        $this->rateLimiterMock->shouldReceive('isWithinTimeWindow')->andReturn(true);
        $this->blacklistServiceMock->shouldReceive('isContactBlacklisted')->andReturn(['blacklisted' => false, 'reasons' => []]);
        $this->dupeServiceMock->shouldReceive('isSafeToContact')->andReturn(['safe' => true, 'reasons' => []]);
        $this->templateServiceMock->shouldReceive('render')->andReturn(['subject_template' => 'S', 'body_template' => 'B']);
        $this->dupeServiceMock->shouldReceive('recordEmail')->andReturn(EmailSentLog::factory()->make());
        $smtp->shouldReceive('recordFailure')->once();

        $this->service->send($contact, $template, $smtp);
    }
}
