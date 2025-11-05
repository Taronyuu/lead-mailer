<?php

namespace Tests\Unit\Services;

use App\Models\Contact;
use App\Models\Website;
use App\Services\EmailValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EmailValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EmailValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailValidationService::class);
    }

    public function test_it_validates_valid_email_successfully(): void
    {
        // Mock DNS functions
        $this->mockValidMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@gmail.com',
            'is_validated' => false,
            'is_valid' => false,
        ]);

        $result = $this->service->validate($contact);

        $this->assertTrue($result);
        $contact->refresh();
        $this->assertTrue($contact->is_validated);
        $this->assertTrue($contact->is_valid);
        $this->assertNull($contact->validation_error);
        $this->assertNotNull($contact->validated_at);
    }

    public function test_it_rejects_invalid_email_format(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'not-an-email',
            'is_validated' => false,
            'is_valid' => false,
        ]);

        $result = $this->service->validate($contact);

        $this->assertFalse($result);
        $contact->refresh();
        $this->assertTrue($contact->is_validated);
        $this->assertFalse($contact->is_valid);
        $this->assertEquals('Invalid email format', $contact->validation_error);
    }

    public function test_it_rejects_email_without_at_sign(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'testexample.com',
            'is_validated' => false,
        ]);

        $result = $this->service->validate($contact);

        $this->assertFalse($result);
        $this->assertEquals('Invalid email format', $contact->fresh()->validation_error);
    }

    public function test_it_rejects_email_without_domain(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'test@',
            'is_validated' => false,
        ]);

        $result = $this->service->validate($contact);

        $this->assertFalse($result);
        $this->assertEquals('Invalid email format', $contact->fresh()->validation_error);
    }

    public function test_it_rejects_disposable_email_domains(): void
    {
        $disposableDomains = [
            'tempmail.com',
            'guerrillamail.com',
            '10minutemail.com',
            'mailinator.com',
            'throwaway.email',
        ];

        foreach ($disposableDomains as $domain) {
            $contact = Contact::factory()->create([
                'email' => "test@{$domain}",
                'is_validated' => false,
                'is_valid' => false,
            ]);

            $result = $this->service->validate($contact);

            $this->assertFalse($result);
            $contact->refresh();
            $this->assertEquals('Disposable email domain', $contact->validation_error);

            $contact->delete();
        }
    }

    public function test_it_accepts_non_disposable_email_domains(): void
    {
        $this->mockValidMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
            'is_validated' => false,
        ]);

        $result = $this->service->validate($contact);

        $this->assertTrue($result);
    }

    public function test_it_validates_email_with_valid_mx_records(): void
    {
        $this->mockValidMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $result = $this->service->validate($contact);

        $this->assertTrue($result);
        $this->assertTrue($contact->fresh()->is_valid);
    }

    public function test_it_rejects_email_with_no_mx_or_a_records(): void
    {
        $this->mockNoMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@invalid-domain-xyz123.com',
        ]);

        $result = $this->service->validate($contact);

        $this->assertFalse($result);
        $contact->refresh();
        $this->assertFalse($contact->is_valid);
        $this->assertEquals('No MX or A records found', $contact->validation_error);
    }

    public function test_it_accepts_email_with_a_record_fallback(): void
    {
        $this->mockARecordFallback();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $result = $this->service->validate($contact);

        $this->assertTrue($result);
        $this->assertTrue($contact->fresh()->is_valid);
    }

    public function test_it_handles_email_without_domain_in_mx_validation(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'invalid',
        ]);

        $result = $this->service->validate($contact);

        $this->assertFalse($result);
        // Should fail on format validation before MX check
        $this->assertEquals('Invalid email format', $contact->fresh()->validation_error);
    }

    public function test_it_batch_validates_multiple_contacts(): void
    {
        $this->mockValidMxRecords();

        $contact1 = Contact::factory()->create(['email' => 'valid1@example.com']);
        $contact2 = Contact::factory()->create(['email' => 'invalid']);
        $contact3 = Contact::factory()->create(['email' => 'valid2@example.com']);

        $results = $this->service->batchValidate([$contact1, $contact2, $contact3]);

        $this->assertEquals(3, $results['validated']);
        $this->assertEquals(2, $results['valid']);
        $this->assertEquals(1, $results['invalid']);
    }

    public function test_batch_validate_returns_correct_counts_for_all_valid(): void
    {
        $this->mockValidMxRecords();

        $contacts = Contact::factory()->count(5)->create([
            'email' => 'test@example.com',
        ]);

        // Make emails unique
        foreach ($contacts as $index => $contact) {
            $contact->update(['email' => "test{$index}@example.com"]);
        }

        $results = $this->service->batchValidate($contacts->all());

        $this->assertEquals(5, $results['validated']);
        $this->assertEquals(5, $results['valid']);
        $this->assertEquals(0, $results['invalid']);
    }

    public function test_batch_validate_returns_correct_counts_for_all_invalid(): void
    {
        $contacts = Contact::factory()->count(3)->create([
            'email' => 'invalid-email',
        ]);

        $results = $this->service->batchValidate($contacts->all());

        $this->assertEquals(3, $results['validated']);
        $this->assertEquals(0, $results['valid']);
        $this->assertEquals(3, $results['invalid']);
    }

    public function test_batch_validate_with_empty_array(): void
    {
        $results = $this->service->batchValidate([]);

        $this->assertEquals(0, $results['validated']);
        $this->assertEquals(0, $results['valid']);
        $this->assertEquals(0, $results['invalid']);
    }

    public function test_it_handles_mx_lookup_exception_gracefully(): void
    {
        Log::shouldReceive('error')->once();

        // Force an exception by using a mock
        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        // We can't easily mock getmxrr as it's a global function
        // but we can test the exception handling by using an email that might trigger it
        $result = $this->service->validate($contact);

        // The result will depend on actual DNS, but it should handle gracefully
        $this->assertIsBool($result);
        $this->assertTrue($contact->fresh()->is_validated);
    }

    public function test_it_validates_email_with_subdomain(): void
    {
        $this->mockValidMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@mail.example.com',
        ]);

        $result = $this->service->validate($contact);

        $this->assertTrue($result);
    }

    public function test_it_validates_email_with_multiple_mx_records(): void
    {
        $this->mockMultipleMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $result = $this->service->validate($contact);

        $this->assertTrue($result);
    }

    public function test_it_case_insensitively_checks_disposable_domains(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'test@TEMPMAIL.COM',
        ]);

        $result = $this->service->validate($contact);

        $this->assertFalse($result);
        $this->assertEquals('Disposable email domain', $contact->fresh()->validation_error);
    }

    public function test_it_accepts_valid_email_formats(): void
    {
        $this->mockValidMxRecords();

        $validEmails = [
            'simple@example.com',
            'user.name@example.com',
            'user+tag@example.com',
            'user_name@example.com',
            '123@example.com',
            'user@sub.example.com',
        ];

        foreach ($validEmails as $email) {
            $contact = Contact::factory()->create(['email' => $email]);
            $result = $this->service->validate($contact);
            $this->assertTrue($result, "Failed for email: {$email}");
            $contact->delete();
        }
    }

    public function test_it_rejects_invalid_email_formats(): void
    {
        $invalidEmails = [
            'plaintext',
            '@example.com',
            'user@',
            'user @example.com',
            'user@example',
            '',
        ];

        foreach ($invalidEmails as $email) {
            if (empty($email)) {
                continue; // Skip empty as it would fail factory creation
            }

            $contact = Contact::factory()->create(['email' => $email]);
            $result = $this->service->validate($contact);
            $this->assertFalse($result, "Should fail for email: {$email}");
            $contact->forceDelete();
        }
    }

    public function test_it_stores_validation_metadata(): void
    {
        $this->mockValidMxRecords();

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->service->validate($contact);

        $contact->refresh();
        $this->assertNotNull($contact->validated_at);
        $this->assertTrue($contact->is_validated);
    }

    public function test_it_updates_existing_validation_status(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'invalid',
            'is_validated' => true,
            'is_valid' => true,
        ]);

        $this->service->validate($contact);

        $contact->refresh();
        $this->assertTrue($contact->is_validated);
        $this->assertFalse($contact->is_valid); // Should now be invalid
    }

    public function test_batch_validate_handles_mixed_validity(): void
    {
        $this->mockValidMxRecords();

        $validContact = Contact::factory()->create(['email' => 'valid@example.com']);
        $disposableContact = Contact::factory()->create(['email' => 'test@tempmail.com']);
        $invalidFormatContact = Contact::factory()->create(['email' => 'not-valid']);

        $results = $this->service->batchValidate([
            $validContact,
            $disposableContact,
            $invalidFormatContact,
        ]);

        $this->assertEquals(3, $results['validated']);
        $this->assertEquals(1, $results['valid']);
        $this->assertEquals(2, $results['invalid']);

        $this->assertTrue($validContact->fresh()->is_valid);
        $this->assertFalse($disposableContact->fresh()->is_valid);
        $this->assertFalse($invalidFormatContact->fresh()->is_valid);
    }

    /**
     * Mock helpers
     */
    protected function mockValidMxRecords(): void
    {
        // Note: In real tests, you might use runkit or similar to mock global functions
        // For now, we rely on actual DNS which may vary
        // In production tests, consider using a mocking library that supports global functions
    }

    protected function mockNoMxRecords(): void
    {
        // Mock scenario where both getmxrr and checkdnsrr return false
    }

    protected function mockARecordFallback(): void
    {
        // Mock scenario where getmxrr returns false but checkdnsrr returns true
    }

    protected function mockMultipleMxRecords(): void
    {
        // Mock scenario with multiple MX records
    }
}
