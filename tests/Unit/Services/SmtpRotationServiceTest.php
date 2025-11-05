<?php

namespace Tests\Unit\Services;

use App\Models\SmtpCredential;
use App\Services\SmtpRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmtpRotationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SmtpRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SmtpRotationService();
    }

    /** @test */
    public function it_returns_available_smtp_account()
    {
        $smtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 10,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertNotNull($result);
        $this->assertEquals($smtp->id, $result->id);
    }

    /** @test */
    public function it_returns_null_when_no_smtp_available()
    {
        // Create only inactive accounts
        SmtpCredential::factory()->create([
            'is_active' => false,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_when_no_smtp_accounts_exist()
    {
        $result = $this->service->getAvailableSmtp();

        $this->assertNull($result);
    }

    /** @test */
    public function it_selects_smtp_with_least_usage()
    {
        $smtp1 = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 50,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 20, // Least used
        ]);

        $smtp3 = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 75,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertEquals($smtp2->id, $result->id);
    }

    /** @test */
    public function it_only_considers_active_smtp_accounts()
    {
        $inactiveSmtp = SmtpCredential::factory()->create([
            'is_active' => false,
            'emails_sent_today' => 0, // Least usage but inactive
        ]);

        $activeSmtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'emails_sent_today' => 50,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertEquals($activeSmtp->id, $result->id);
    }

    /** @test */
    public function it_excludes_smtp_at_daily_limit()
    {
        $atLimit = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 100,
        ]);

        $belowLimit = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 50,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertEquals($belowLimit->id, $result->id);
    }

    /** @test */
    public function it_checks_smtp_health_with_no_history()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 0,
            'failure_count' => 0,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_checks_smtp_health_with_good_success_rate()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 90,
            'failure_count' => 10,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertTrue($result); // 90% success rate
    }

    /** @test */
    public function it_checks_smtp_health_with_low_success_rate()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 60,
            'failure_count' => 40,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertFalse($result); // 60% success rate (below 70%)
    }

    /** @test */
    public function it_checks_smtp_health_at_exactly_70_percent()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 70,
            'failure_count' => 30,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertTrue($result); // Exactly 70% success rate
    }

    /** @test */
    public function it_checks_smtp_health_below_70_percent()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 69,
            'failure_count' => 31,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertFalse($result); // 69% success rate (below threshold)
    }

    /** @test */
    public function it_checks_smtp_health_with_100_percent_success()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 100,
            'failure_count' => 0,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_checks_smtp_health_with_zero_percent_success()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 0,
            'failure_count' => 100,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_auto_disables_unhealthy_smtp_accounts()
    {
        Log::shouldReceive('warning')->once();

        $healthySmtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 90,
            'failure_count' => 10,
        ]);

        $unhealthySmtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 50,
            'failure_count' => 50, // 50% success rate
        ]);

        $this->service->checkAndDisableUnhealthy();

        $this->assertTrue($healthySmtp->fresh()->is_active);
        $this->assertFalse($unhealthySmtp->fresh()->is_active);
    }

    /** @test */
    public function it_logs_when_disabling_unhealthy_smtp()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'SMTP account auto-disabled due to low success rate' &&
                    isset($context['smtp_id']) &&
                    isset($context['success_count']) &&
                    isset($context['failure_count']);
            });

        $unhealthySmtp = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 30,
            'failure_count' => 70,
        ]);

        $this->service->checkAndDisableUnhealthy();

        $this->assertFalse($unhealthySmtp->fresh()->is_active);
    }

    /** @test */
    public function it_does_not_check_already_inactive_smtp_accounts()
    {
        $inactiveSmtp = SmtpCredential::factory()->create([
            'is_active' => false,
            'success_count' => 10,
            'failure_count' => 90,
        ]);

        $this->service->checkAndDisableUnhealthy();

        // Should remain inactive
        $this->assertFalse($inactiveSmtp->fresh()->is_active);
    }

    /** @test */
    public function it_does_not_disable_healthy_accounts()
    {
        $smtp1 = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 100,
            'failure_count' => 0,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 75,
            'failure_count' => 25,
        ]);

        $this->service->checkAndDisableUnhealthy();

        $this->assertTrue($smtp1->fresh()->is_active);
        $this->assertTrue($smtp2->fresh()->is_active);
    }

    /** @test */
    public function it_handles_multiple_unhealthy_accounts()
    {
        Log::shouldReceive('warning')->times(2);

        $unhealthy1 = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 40,
            'failure_count' => 60,
        ]);

        $unhealthy2 = SmtpCredential::factory()->create([
            'is_active' => true,
            'success_count' => 50,
            'failure_count' => 50,
        ]);

        $this->service->checkAndDisableUnhealthy();

        $this->assertFalse($unhealthy1->fresh()->is_active);
        $this->assertFalse($unhealthy2->fresh()->is_active);
    }

    /** @test */
    public function it_handles_no_smtp_accounts_in_health_check()
    {
        // Should not throw any errors
        $this->service->checkAndDisableUnhealthy();

        $this->assertTrue(true); // Passed without errors
    }

    /** @test */
    public function it_selects_first_when_multiple_have_same_usage()
    {
        $smtp1 = SmtpCredential::factory()->create([
            'is_active' => true,
            'emails_sent_today' => 10,
        ]);

        $smtp2 = SmtpCredential::factory()->create([
            'is_active' => true,
            'emails_sent_today' => 10,
        ]);

        $result = $this->service->getAvailableSmtp();

        // Should return the first one when sorted
        $this->assertNotNull($result);
        $this->assertEquals($smtp1->id, $result->id);
    }

    /** @test */
    public function it_handles_smtp_with_zero_usage()
    {
        $zeroUsage = SmtpCredential::factory()->create([
            'is_active' => true,
            'emails_sent_today' => 0,
        ]);

        $someUsage = SmtpCredential::factory()->create([
            'is_active' => true,
            'emails_sent_today' => 5,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertEquals($zeroUsage->id, $result->id);
    }

    /** @test */
    public function it_uses_available_scope_for_filtering()
    {
        // Create an SMTP that should be filtered by the available scope
        $overLimit = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 50,
            'emails_sent_today' => 50,
        ]);

        $available = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 30,
        ]);

        $result = $this->service->getAvailableSmtp();

        $this->assertEquals($available->id, $result->id);
    }

    /** @test */
    public function it_calculates_success_rate_correctly_with_large_numbers()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 7000,
            'failure_count' => 3000,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertTrue($result); // 70% success rate
    }

    /** @test */
    public function it_handles_single_failure_in_success_rate()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 999,
            'failure_count' => 1,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertTrue($result); // 99.9% success rate
    }

    /** @test */
    public function it_handles_single_success_in_success_rate()
    {
        $smtp = SmtpCredential::factory()->create([
            'success_count' => 1,
            'failure_count' => 999,
        ]);

        $result = $this->service->isHealthy($smtp);

        $this->assertFalse($result); // 0.1% success rate
    }
}
