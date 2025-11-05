<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\EmailValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ValidateContactEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60; // 1 minute
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Contact $contact
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailValidationService $validator): void
    {
        try {
            // Skip if already validated
            if ($this->contact->is_validated) {
                return;
            }

            Log::info('Validating contact email', [
                'contact_id' => $this->contact->id,
                'email' => $this->contact->email,
            ]);

            $validator->validate($this->contact);

            Log::info('Contact email validation completed', [
                'contact_id' => $this->contact->id,
                'is_valid' => $this->contact->is_valid,
            ]);

        } catch (\Exception $e) {
            Log::error('Contact email validation failed', [
                'contact_id' => $this->contact->id,
                'error' => $e->getMessage(),
            ]);

            // Mark as validated but invalid on error
            $this->contact->markAsValidated(false, 'Validation error: ' . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->contact->markAsValidated(false, 'Validation job failed: ' . $exception->getMessage());

        Log::error('Contact validation job failed permanently', [
            'contact_id' => $this->contact->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
