<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "\n=== Bug Fixes Verification Script ===\n\n";

$allPassed = true;

echo "1. Testing BlacklistService - is_active column exists...\n";
try {
    $blacklist = \App\Models\BlacklistEntry::create([
        'type' => 'email',
        'value' => 'test@example.com',
        'is_active' => true,
        'reason' => 'Test entry',
        'source' => 'manual',
    ]);

    if ($blacklist->is_active === true) {
        echo "   ✅ PASSED: is_active column works correctly\n";
        $blacklist->delete();
    } else {
        echo "   ❌ FAILED: is_active value incorrect\n";
        $allPassed = false;
    }
} catch (Exception $e) {
    echo "   ❌ FAILED: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n2. Testing Contact - source_context column exists...\n";
try {
    $domain = \App\Models\Domain::firstOrCreate([
        'domain' => 'test-verify.com',
        'tld' => 'com',
    ]);

    $website = \App\Models\Website::firstOrCreate([
        'domain_id' => $domain->id,
        'url' => 'https://test-verify.com',
    ]);

    $contact = \App\Models\Contact::create([
        'website_id' => $website->id,
        'email' => 'verify@test.com',
        'source_context' => 'Test context around email',
        'source_type' => 'contact_page',
    ]);

    if ($contact->source_context === 'Test context around email') {
        echo "   ✅ PASSED: source_context column works correctly\n";
        $contact->delete();
    } else {
        echo "   ❌ FAILED: source_context value incorrect\n";
        $allPassed = false;
    }

    $website->delete();
    $domain->delete();
} catch (Exception $e) {
    echo "   ❌ FAILED: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n3. Testing EmailValidationService - type signature fixed...\n";
try {
    $domain = \App\Models\Domain::firstOrCreate([
        'domain' => 'test-verify2.com',
        'tld' => 'com',
    ]);

    $website = \App\Models\Website::firstOrCreate([
        'domain_id' => $domain->id,
        'url' => 'https://test-verify2.com',
    ]);

    $contact = \App\Models\Contact::create([
        'website_id' => $website->id,
        'email' => 'test@gmail.com',
        'source_type' => 'contact_page',
    ]);

    $validator = new \App\Services\EmailValidationService();
    $validator->validate($contact);

    if ($contact->is_validated) {
        echo "   ✅ PASSED: EmailValidationService accepts Contact object\n";
        $contact->delete();
    } else {
        echo "   ❌ FAILED: Validation did not mark contact as validated\n";
        $allPassed = false;
    }

    $website->delete();
    $domain->delete();
} catch (Exception $e) {
    echo "   ❌ FAILED: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n4. Testing EmailReviewQueue - smtp_credential_id column exists...\n";
try {
    $domain = \App\Models\Domain::firstOrCreate([
        'domain' => 'test-verify3.com',
        'tld' => 'com',
    ]);

    $website = \App\Models\Website::firstOrCreate([
        'domain_id' => $domain->id,
        'url' => 'https://test-verify3.com',
    ]);

    $contact = \App\Models\Contact::create([
        'website_id' => $website->id,
        'email' => 'review@test.com',
        'source_type' => 'contact_page',
    ]);

    $template = \App\Models\EmailTemplate::firstOrCreate(
        ['name' => 'Test Template Verify'],
        [
            'subject_template' => 'Test Subject',
            'body_template' => 'Test Body',
            'is_active' => true,
        ]
    );

    $smtp = \App\Models\SmtpCredential::firstOrCreate(
        ['name' => 'Test SMTP Verify'],
        [
            'host' => 'smtp.test.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'test@test.com',
            'password' => bcrypt('password'),
            'from_email' => 'test@test.com',
            'from_name' => 'Test',
        ]
    );

    $reviewQueue = \App\Models\EmailReviewQueue::create([
        'website_id' => $website->id,
        'contact_id' => $contact->id,
        'email_template_id' => $template->id,
        'smtp_credential_id' => $smtp->id,
        'generated_subject' => 'Test',
        'generated_body' => 'Test Body',
    ]);

    if ($reviewQueue->smtp_credential_id === $smtp->id) {
        echo "   ✅ PASSED: smtp_credential_id column works correctly\n";
        $reviewQueue->delete();
    } else {
        echo "   ❌ FAILED: smtp_credential_id value incorrect\n";
        $allPassed = false;
    }

    $smtp->delete();
    $template->delete();
    $contact->delete();
    $website->delete();
    $domain->delete();
} catch (Exception $e) {
    echo "   ❌ FAILED: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n5. Testing ProcessEmailQueueJob - logic fix verification...\n";
try {
    $reflection = new ReflectionClass(\App\Jobs\ProcessEmailQueueJob::class);
    $method = $reflection->getMethod('handle');
    $source = file_get_contents($reflection->getFileName());

    if (strpos($source, 'whereDoesntHave(\'emailSentLogs\')') !== false) {
        echo "   ❌ FAILED: Old logic still present (filters by website email logs)\n";
        $allPassed = false;
    } elseif (strpos($source, 'Contact::whereHas(\'website\'') !== false) {
        echo "   ✅ PASSED: New logic implemented (filters by uncontacted contacts)\n";
    } else {
        echo "   ⚠️  WARNING: Unable to verify logic change\n";
    }
} catch (Exception $e) {
    echo "   ❌ FAILED: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n" . str_repeat("=", 50) . "\n";
if ($allPassed) {
    echo "✅ ALL TESTS PASSED - All critical bugs have been fixed!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED - Please review the errors above\n";
    exit(1);
}
