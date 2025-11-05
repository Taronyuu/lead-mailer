<?php

namespace Tests\Unit\Services;

use App\Models\SmtpCredential;
use App\Services\RateLimiterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimiterServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RateLimiterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.sending_window.start' => 8,
            'mail.sending_window.end' => 17,
        ]);

        $this->service = new RateLimiterService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_true_when_within_time_window()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_when_before_start_hour()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 07:00:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_false_when_after_end_hour()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 18:00:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_true_at_start_hour_exactly()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 08:00:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_at_end_hour_exactly()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 17:00:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_true_one_minute_before_end()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 16:59:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertTrue($result);
    }

    /** @test */
    public function it_accepts_custom_time_parameter()
    {
        $customTime = Carbon::parse('2024-01-15 12:00:00');

        $result = $this->service->isWithinTimeWindow($customTime);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_checks_custom_time_outside_window()
    {
        $customTime = Carbon::parse('2024-01-15 06:00:00');

        $result = $this->service->isWithinTimeWindow($customTime);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_start_time_today_when_before_start_hour()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 07:00:00'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-15 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_returns_start_time_tomorrow_when_after_end_hour()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 18:00:00'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-16 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_returns_now_when_within_window()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 12:30:00'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-15 12:30:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_returns_start_time_tomorrow_at_exact_end_hour()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 17:00:00'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-16 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_returns_start_time_today_at_exact_start_hour()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 08:00:00'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-15 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_resets_minutes_and_seconds_for_start_time()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 07:45:30'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-15 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_calculates_remaining_capacity_from_all_smtp_accounts()
    {
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 30,
        ]);

        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 200,
            'emails_sent_today' => 50,
        ]);

        $result = $this->service->getRemainingCapacity();

        // (100 - 30) + (200 - 50) = 70 + 150 = 220
        $this->assertEquals(220, $result);
    }

    /** @test */
    public function it_excludes_inactive_smtp_from_capacity()
    {
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 20,
        ]);

        SmtpCredential::factory()->create([
            'is_active' => false,
            'daily_limit' => 200,
            'emails_sent_today' => 0,
        ]);

        $result = $this->service->getRemainingCapacity();

        // Only active account: 100 - 20 = 80
        $this->assertEquals(80, $result);
    }

    /** @test */
    public function it_excludes_smtp_at_limit_from_capacity()
    {
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 100,
        ]);

        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 50,
            'emails_sent_today' => 10,
        ]);

        $result = $this->service->getRemainingCapacity();

        // First account at limit: 0
        // Second account: 50 - 10 = 40
        $this->assertEquals(40, $result);
    }

    /** @test */
    public function it_returns_zero_capacity_when_no_smtp_available()
    {
        $result = $this->service->getRemainingCapacity();

        $this->assertEquals(0, $result);
    }

    /** @test */
    public function it_calculates_delay_when_within_time_window()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00')); // 7 hours until end

        $result = $this->service->calculateDelay(420); // 420 emails in 420 minutes

        $this->assertEquals(1, $result); // 1 minute per email
    }

    /** @test */
    public function it_returns_zero_delay_when_outside_time_window()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 18:00:00'));

        $result = $this->service->calculateDelay(100);

        $this->assertEquals(0, $result);
    }

    /** @test */
    public function it_returns_zero_delay_when_total_to_send_is_zero()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        $result = $this->service->calculateDelay(0);

        $this->assertEquals(0, $result);
    }

    /** @test */
    public function it_returns_minimum_delay_of_one_minute()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 16:50:00')); // 10 minutes left

        $result = $this->service->calculateDelay(1000); // Many emails, little time

        $this->assertEquals(1, $result); // Minimum 1 minute
    }

    /** @test */
    public function it_distributes_emails_evenly_across_remaining_time()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 15:00:00')); // 2 hours = 120 minutes

        $result = $this->service->calculateDelay(60); // 60 emails

        $this->assertEquals(2, $result); // 120 / 60 = 2 minutes per email
    }

    /** @test */
    public function it_rounds_down_delay_to_integer()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 15:00:00')); // 120 minutes

        $result = $this->service->calculateDelay(70); // 120 / 70 = 1.71...

        $this->assertEquals(1, $result);
    }

    /** @test */
    public function it_uses_configured_start_hour()
    {
        config(['mail.sending_window.start' => 9]);
        $service = new RateLimiterService();

        Carbon::setTestNow(Carbon::parse('2024-01-15 08:30:00'));

        $result = $service->isWithinTimeWindow();

        $this->assertFalse($result);

        Carbon::setTestNow(Carbon::parse('2024-01-15 09:00:00'));

        $result = $service->isWithinTimeWindow();

        $this->assertTrue($result);
    }

    /** @test */
    public function it_uses_configured_end_hour()
    {
        config(['mail.sending_window.end' => 18]);
        $service = new RateLimiterService();

        Carbon::setTestNow(Carbon::parse('2024-01-15 17:30:00'));

        $result = $service->isWithinTimeWindow();

        $this->assertTrue($result);

        Carbon::setTestNow(Carbon::parse('2024-01-15 18:00:00'));

        $result = $service->isWithinTimeWindow();

        $this->assertFalse($result);
    }

    /** @test */
    public function it_handles_midnight_crossing()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 23:00:00'));

        $result = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-16 08:00:00', $result->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_handles_early_morning_hours()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 02:00:00'));

        $result = $this->service->isWithinTimeWindow();

        $this->assertFalse($result);

        $nextTime = $this->service->getNextAvailableTime();

        $this->assertEquals('2024-01-15 08:00:00', $nextTime->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_calculates_capacity_with_negative_remaining()
    {
        // Edge case: sent more than limit
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 120, // Over limit
        ]);

        $result = $this->service->getRemainingCapacity();

        $this->assertEquals(-20, $result);
    }

    /** @test */
    public function it_handles_large_number_of_emails_for_delay()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00')); // 420 minutes left

        $result = $this->service->calculateDelay(10000);

        $this->assertEquals(1, $result); // Minimum delay
    }

    /** @test */
    public function it_handles_few_emails_for_large_delay()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 08:00:00')); // 540 minutes left

        $result = $this->service->calculateDelay(1);

        $this->assertEquals(540, $result);
    }

    /** @test */
    public function it_calculates_correct_minutes_remaining()
    {
        Carbon::setTestNow(Carbon::parse('2024-01-15 16:30:00')); // 30 minutes until 17:00

        $result = $this->service->calculateDelay(30);

        $this->assertEquals(1, $result); // 30 / 30 = 1
    }

    /** @test */
    public function it_works_with_default_config_values()
    {
        config(['mail.sending_window.start' => null]);
        config(['mail.sending_window.end' => null]);

        $service = new RateLimiterService();

        Carbon::setTestNow(Carbon::parse('2024-01-15 10:00:00'));

        // Should use defaults (8 and 17)
        $result = $service->isWithinTimeWindow();

        $this->assertTrue($result);
    }

    /** @test */
    public function it_handles_smtp_with_zero_daily_limit()
    {
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 0,
            'emails_sent_today' => 0,
        ]);

        $result = $this->service->getRemainingCapacity();

        $this->assertEquals(0, $result);
    }
}
