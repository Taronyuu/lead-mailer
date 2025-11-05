<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\EmailReviewQueue;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateReviewQueueEntriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 2;

    protected Domain $domain;
    protected int $websiteId;

    public function __construct(Domain $domain, int $websiteId)
    {
        $this->domain = $domain;
        $this->websiteId = $websiteId;
    }

    public function handle(): void
    {
        try {
            $website = Website::with(['defaultEmailTemplate', 'smtpCredential'])->findOrFail($this->websiteId);

            if (!$website->defaultEmailTemplate) {
                Log::warning('Website has no default email template', [
                    'website_id' => $this->websiteId,
                    'domain_id' => $this->domain->id,
                ]);
                return;
            }

            $contacts = $this->domain->contacts;

            if ($contacts->isEmpty()) {
                Log::info('No contacts found for domain', [
                    'website_id' => $this->websiteId,
                    'domain_id' => $this->domain->id,
                    'domain' => $this->domain->domain,
                ]);
                return;
            }

            $pivotData = $this->domain->websites()
                ->where('website_id', $this->websiteId)
                ->first()
                ->pivot;

            $pageCount = $pivotData->page_count ?? 0;
            $wordCount = $pivotData->word_count ?? 0;
            $detectedPlatform = $pivotData->detected_platform ?? 'Unknown';

            foreach ($contacts as $contact) {
                $existingEntry = EmailReviewQueue::where('website_id', $this->websiteId)
                    ->where('contact_id', $contact->id)
                    ->first();

                if ($existingEntry) {
                    Log::debug('Review queue entry already exists', [
                        'website_id' => $this->websiteId,
                        'contact_id' => $contact->id,
                    ]);
                    continue;
                }

                $variables = $this->buildTemplateVariables(
                    $contact,
                    $website,
                    $pageCount,
                    $detectedPlatform
                );

                $generatedSubject = $this->replaceVariables(
                    $website->defaultEmailTemplate->subject_template,
                    $variables
                );

                $generatedBody = $this->replaceVariables(
                    $website->defaultEmailTemplate->body_template,
                    $variables
                );

                $generatedPreheader = $website->defaultEmailTemplate->preheader
                    ? $this->replaceVariables($website->defaultEmailTemplate->preheader, $variables)
                    : null;

                EmailReviewQueue::create([
                    'website_id' => $this->websiteId,
                    'contact_id' => $contact->id,
                    'email_template_id' => $website->defaultEmailTemplate->id,
                    'smtp_credential_id' => $website->smtp_credential_id,
                    'generated_subject' => $generatedSubject,
                    'generated_body' => $generatedBody,
                    'generated_preheader' => $generatedPreheader,
                    'status' => EmailReviewQueue::STATUS_PENDING,
                    'priority' => 50,
                ]);
            }

            Log::info('Review queue entries created', [
                'website_id' => $this->websiteId,
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'contacts_processed' => $contacts->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create review queue entries', [
                'website_id' => $this->websiteId,
                'domain_id' => $this->domain->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function buildTemplateVariables($contact, $website, $pageCount, $detectedPlatform): array
    {
        return [
            '{{website_url}}' => $this->domain->domain,
            '{{website_title}}' => $website->title ?? $this->domain->domain,
            '{{website_description}}' => $website->description ?? '',
            '{{contact_name}}' => $contact->name ?? '',
            '{{contact_email}}' => $contact->email,
            '{{platform}}' => $detectedPlatform,
            '{{page_count}}' => $pageCount,
            '{{domain}}' => $this->domain->domain,
            '{{sender_name}}' => '',
            '{{sender_company}}' => '',
        ];
    }

    protected function replaceVariables(string $template, array $variables): string
    {
        return str_replace(
            array_keys($variables),
            array_values($variables),
            $template
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Create review queue entries job failed permanently', [
            'website_id' => $this->websiteId,
            'domain_id' => $this->domain->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
