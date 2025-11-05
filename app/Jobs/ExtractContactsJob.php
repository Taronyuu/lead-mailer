<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\ContactExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    public function __construct(
        public Domain $domain,
        public string $html
    ) {}

    public function handle(ContactExtractionService $extractor): void
    {
        try {
            Log::info('Extracting contacts', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
            ]);

            $contacts = $extractor->extractFromHtml(
                $this->html,
                'https://' . $this->domain->domain,
                $this->domain
            );

            Log::info('Contact extraction completed', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'contacts_found' => count($contacts),
            ]);

            foreach ($contacts as $contact) {
                ValidateContactEmailJob::dispatch($contact);
            }

        } catch (\Exception $e) {
            Log::error('Contact extraction failed', [
                'domain_id' => $this->domain->id,
                'domain' => $this->domain->domain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
