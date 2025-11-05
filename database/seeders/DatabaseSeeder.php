<?php

namespace Database\Seeders;

use App\Models\BlacklistEntry;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailReviewQueue;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteRequirement;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌱 Seeding database...');

        // 0. Create admin user for Filament
        $this->command->info('Creating admin user...');
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);
        $this->command->info('✓ Admin user created (admin@example.com)');
        return;
        // 1. Create SMTP Credentials
        $this->command->info('Creating SMTP credentials...');
        $smtpCredentials = SmtpCredential::factory()
            ->count(3)
            ->active()
            ->create();

        $this->command->info('✓ Created ' . $smtpCredentials->count() . ' SMTP credentials');

        // 2. Create Email Templates
        $this->command->info('Creating email templates...');
        $templates = collect([
            EmailTemplate::factory()->create([
                'name' => 'Standard Outreach',
                'subject_template' => 'Partnership Opportunity with {{website_url}}',
                'body_template' => "Hi {{contact_name}},\n\nI came across {{website_url}} and was impressed by your {{platform}} store.\n\nI'd love to discuss a potential partnership opportunity that could benefit your business.\n\nBest regards,\n{{from_name}}",
                'is_active' => true,
            ]),
            EmailTemplate::factory()->withAI()->create([
                'name' => 'AI-Powered Personalized Outreach',
                'is_active' => true,
            ]),
            EmailTemplate::factory()->create([
                'name' => 'Follow-up Email',
                'subject_template' => 'Following up on {{website_url}}',
                'is_active' => false,
            ]),
        ]);

        $this->command->info('✓ Created ' . $templates->count() . ' email templates');

        // 3. Create Website Requirements
        $this->command->info('Creating website requirements...');
        $requirements = collect([
            WebsiteRequirement::factory()->ecommerce()->create(),
            WebsiteRequirement::factory()->wordpress()->create(),
            WebsiteRequirement::factory()->create([
                'name' => 'Small Business Websites',
                'description' => 'Small business websites with 5-20 pages',
                'criteria' => [
                    'min_pages' => 5,
                    'max_pages' => 20,
                    'min_word_count' => 300,
                ],
            ]),
        ]);

        $this->command->info('✓ Created ' . $requirements->count() . ' website requirements');

        // 4. Create Domains and Websites
        $this->command->info('Creating domains and websites...');

        // Pending websites
        Domain::factory()
            ->count(20)
            ->has(Website::factory()->count(1), 'websites')
            ->create();

        // Completed websites (not qualified)
        Domain::factory()
            ->count(30)
            ->processed()
            ->has(Website::factory()->completed()->count(1), 'websites')
            ->create();

        // Qualified websites with contacts
        $qualifiedWebsites = Domain::factory()
            ->count(50)
            ->active()
            ->create()
            ->each(function ($domain) {
                $website = Website::factory()
                    ->qualified()
                    ->for($domain)
                    ->create();

                // Create 2-5 contacts per qualified website
                Contact::factory()
                    ->count(rand(2, 5))
                    ->validated()
                    ->for($website)
                    ->create();
            });

        $this->command->info('✓ Created 100 domains with websites');
        $this->command->info('✓ Created contacts for qualified websites');

        // 5. Create some contacted websites with email logs
        $this->command->info('Creating email sent logs...');

        $contactedWebsites = Website::where('meets_requirements', true)
            ->limit(20)
            ->get();

        foreach ($contactedWebsites as $website) {
            $contacts = $website->contacts()->validated()->limit(2)->get();

            foreach ($contacts as $contact) {
                EmailSentLog::factory()
                    ->for($website)
                    ->for($contact)
                    ->for($smtpCredentials->random())
                    ->for($templates->first())
                    ->create([
                        'recipient_email' => $contact->email,
                        'recipient_name' => $contact->name,
                    ]);

                $contact->update([
                    'contacted' => true,
                    'first_contacted_at' => now()->subDays(rand(1, 30)),
                    'last_contacted_at' => now()->subDays(rand(1, 30)),
                    'contact_count' => 1,
                ]);
            }
        }

        $this->command->info('✓ Created email sent logs');

        // 6. Create some failed email logs
        EmailSentLog::factory()
            ->failed()
            ->count(5)
            ->create([
                'smtp_credential_id' => $smtpCredentials->random()->id,
                'email_template_id' => $templates->first()->id,
            ]);

        // 7. Create Email Review Queue entries
        $this->command->info('Creating review queue entries...');

        $reviewWebsites = Website::where('meets_requirements', true)
            ->whereDoesntHave('emailSentLogs')
            ->limit(15)
            ->get();

        foreach ($reviewWebsites as $website) {
            $contact = $website->contacts()->validated()->first();

            if ($contact) {
                EmailReviewQueue::factory()
                    ->pending()
                    ->for($website)
                    ->for($contact)
                    ->for($templates->first())
                    ->create();
            }
        }

        // Create some approved entries
        EmailReviewQueue::factory()
            ->approved()
            ->count(5)
            ->create([
                'email_template_id' => $templates->first()->id,
            ]);

        // Create some rejected entries
        EmailReviewQueue::factory()
            ->rejected()
            ->count(3)
            ->create([
                'email_template_id' => $templates->first()->id,
            ]);

        $this->command->info('✓ Created review queue entries');

        // 8. Create Blacklist Entries
        $this->command->info('Creating blacklist entries...');

        BlacklistEntry::factory()->email()->count(10)->create();
        BlacklistEntry::factory()->domain()->count(5)->create();

        $this->command->info('✓ Created blacklist entries');

        // 9. Update SMTP stats
        $this->command->info('Updating SMTP statistics...');

        foreach ($smtpCredentials as $smtp) {
            $sentCount = EmailSentLog::where('smtp_credential_id', $smtp->id)
                ->where('status', EmailSentLog::STATUS_SENT)
                ->count();

            $failedCount = EmailSentLog::where('smtp_credential_id', $smtp->id)
                ->where('status', EmailSentLog::STATUS_FAILED)
                ->count();

            $smtp->update([
                'success_count' => $sentCount,
                'failure_count' => $failedCount,
                'emails_sent_today' => rand(0, 5),
            ]);
        }

        $this->command->info('✓ Updated SMTP statistics');

        // 10. Update template usage
        $this->command->info('Updating template usage...');

        foreach ($templates as $template) {
            $usageCount = EmailSentLog::where('email_template_id', $template->id)->count();
            $template->update(['usage_count' => $usageCount]);
        }

        $this->command->info('✓ Updated template usage');

        // Display summary
        $this->command->line('');
        $this->command->info('=== Seeding Complete ===');
        $this->command->table(
            ['Model', 'Count'],
            [
                ['Domains', Domain::count()],
                ['Websites', Website::count()],
                ['  - Qualified', Website::where('meets_requirements', true)->count()],
                ['Contacts', Contact::count()],
                ['  - Validated', Contact::where('is_validated', true)->count()],
                ['  - Contacted', Contact::where('contacted', true)->count()],
                ['SMTP Credentials', SmtpCredential::count()],
                ['Email Templates', EmailTemplate::count()],
                ['Website Requirements', WebsiteRequirement::count()],
                ['Email Sent Logs', EmailSentLog::count()],
                ['  - Successful', EmailSentLog::where('status', EmailSentLog::STATUS_SENT)->count()],
                ['  - Failed', EmailSentLog::where('status', EmailSentLog::STATUS_FAILED)->count()],
                ['Review Queue', EmailReviewQueue::count()],
                ['  - Pending', EmailReviewQueue::where('status', EmailReviewQueue::STATUS_PENDING)->count()],
                ['  - Approved', EmailReviewQueue::where('status', EmailReviewQueue::STATUS_APPROVED)->count()],
                ['Blacklist Entries', BlacklistEntry::count()],
            ]
        );

        $this->command->line('');
        $this->command->info('🎉 Database seeded successfully!');
        $this->command->line('');
        $this->command->info('Next steps:');
        $this->command->line('1. Visit the Filament admin panel: /admin');
        $this->command->line('2. Run crawls: php artisan website:crawl --all');
        $this->command->line('3. Process emails: php artisan email:process');
        $this->command->line('4. Check stats: php artisan system:stats');
    }
}
