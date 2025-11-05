<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\SmtpCredential;

class SmtpCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'name',
            'host',
            'port',
            'encryption',
            'username',
            'password',
            'from_address',
            'from_name',
            'is_active',
            'daily_limit',
            'emails_sent_today',
            'last_reset_date',
            'last_used_at',
            'success_count',
            'failure_count',
        ];

        $credential = new SmtpCredential();

        $this->assertEquals($fillable, $credential->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $credential = SmtpCredential::factory()->create([
            'port' => '587',
            'is_active' => true,
            'daily_limit' => '1000',
            'emails_sent_today' => '50',
            'success_count' => '500',
            'failure_count' => '5',
            'last_reset_date' => '2024-01-01',
            'last_used_at' => '2024-01-01 10:00:00',
        ]);

        $this->assertIsInt($credential->port);
        $this->assertIsBool($credential->is_active);
        $this->assertIsInt($credential->daily_limit);
        $this->assertIsInt($credential->emails_sent_today);
        $this->assertIsInt($credential->success_count);
        $this->assertIsInt($credential->failure_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $credential->last_reset_date);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $credential->last_used_at);
    }

    public function test_it_uses_soft_deletes(): void
    {
        $credential = SmtpCredential::factory()->create();

        $credential->delete();

        $this->assertSoftDeleted('smtp_credentials', ['id' => $credential->id]);
        $this->assertNotNull($credential->fresh()->deleted_at);
    }

    public function test_password_is_hidden(): void
    {
        $credential = SmtpCredential::factory()->create(['password' => 'secret123']);

        $array = $credential->toArray();

        $this->assertArrayNotHasKey('password', $array);
    }

    public function test_active_scope_works(): void
    {
        SmtpCredential::factory()->create(['is_active' => true]);
        SmtpCredential::factory()->create(['is_active' => false]);
        SmtpCredential::factory()->create(['is_active' => true]);

        $active = SmtpCredential::active()->get();

        $this->assertCount(2, $active);
        $active->each(fn($credential) => $this->assertTrue($credential->is_active));
    }

    public function test_available_scope_works(): void
    {
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 50,
        ]);
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 100,
        ]);
        SmtpCredential::factory()->create([
            'is_active' => false,
            'daily_limit' => 100,
            'emails_sent_today' => 0,
        ]);
        SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 99,
        ]);

        $available = SmtpCredential::available()->get();

        $this->assertCount(2, $available);
        $available->each(function($credential) {
            $this->assertTrue($credential->is_active);
            $this->assertLessThan($credential->daily_limit, $credential->emails_sent_today);
        });
    }

    public function test_increment_sent_count_increments_counters(): void
    {
        $credential = SmtpCredential::factory()->create([
            'emails_sent_today' => 10,
            'success_count' => 100,
        ]);

        $credential->incrementSentCount();

        $fresh = $credential->fresh();
        $this->assertEquals(11, $fresh->emails_sent_today);
        $this->assertEquals(101, $fresh->success_count);
    }

    public function test_increment_sent_count_updates_last_used_at(): void
    {
        $credential = SmtpCredential::factory()->create(['last_used_at' => null]);

        $credential->incrementSentCount();

        $fresh = $credential->fresh();
        $this->assertNotNull($fresh->last_used_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->last_used_at);
    }

    public function test_record_failure_increments_failure_count(): void
    {
        $credential = SmtpCredential::factory()->create(['failure_count' => 5]);

        $credential->recordFailure();

        $this->assertEquals(6, $credential->fresh()->failure_count);
    }

    public function test_is_available_returns_true_when_active_and_under_limit(): void
    {
        $credential = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 50,
        ]);

        $this->assertTrue($credential->isAvailable());
    }

    public function test_is_available_returns_false_when_inactive(): void
    {
        $credential = SmtpCredential::factory()->create([
            'is_active' => false,
            'daily_limit' => 100,
            'emails_sent_today' => 50,
        ]);

        $this->assertFalse($credential->isAvailable());
    }

    public function test_is_available_returns_false_when_at_limit(): void
    {
        $credential = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 100,
        ]);

        $this->assertFalse($credential->isAvailable());
    }

    public function test_is_available_returns_false_when_over_limit(): void
    {
        $credential = SmtpCredential::factory()->create([
            'is_active' => true,
            'daily_limit' => 100,
            'emails_sent_today' => 101,
        ]);

        $this->assertFalse($credential->isAvailable());
    }

    public function test_get_remaining_capacity_returns_correct_value(): void
    {
        $credential = SmtpCredential::factory()->create([
            'daily_limit' => 100,
            'emails_sent_today' => 30,
        ]);

        $this->assertEquals(70, $credential->getRemainingCapacity());
    }

    public function test_get_remaining_capacity_returns_zero_when_at_limit(): void
    {
        $credential = SmtpCredential::factory()->create([
            'daily_limit' => 100,
            'emails_sent_today' => 100,
        ]);

        $this->assertEquals(0, $credential->getRemainingCapacity());
    }

    public function test_get_remaining_capacity_returns_zero_when_over_limit(): void
    {
        $credential = SmtpCredential::factory()->create([
            'daily_limit' => 100,
            'emails_sent_today' => 150,
        ]);

        $this->assertEquals(0, $credential->getRemainingCapacity());
    }

    public function test_factory_creates_valid_smtp_credential(): void
    {
        $credential = SmtpCredential::factory()->create();

        $this->assertInstanceOf(SmtpCredential::class, $credential);
        $this->assertNotNull($credential->host);
        $this->assertNotNull($credential->username);
        $this->assertDatabaseHas('smtp_credentials', ['id' => $credential->id]);
    }
}
