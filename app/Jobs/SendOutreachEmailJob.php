<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Services\EmailSendingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendOutreachEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes
    public $tries = 2;
    public $backoff = 300; // 5 minutes between retries

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Contact $contact,
        public EmailTemplate $template,
        public ?SmtpCredential $smtp = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailSendingService $emailService): void
    {
        try {
            Log::info('Sending outreach email', [
                'contact_id' => $this->contact->id,
                'template_id' => $this->template->id,
                'email' => $this->contact->email,
            ]);

            $result = $emailService->send($this->contact, $this->template, $this->smtp);

            if ($result['success']) {
                Log::info('Outreach email sent successfully', [
                    'contact_id' => $this->contact->id,
                    'log_id' => $result['log_id'],
                    'smtp_id' => $result['smtp_id'],
                ]);
            } else {
                Log::warning('Outreach email send failed', [
                    'contact_id' => $this->contact->id,
                    'error' => $result['error'],
                ]);

                // If it's a permanent error (blacklist, duplicate), don't retry
                if (str_contains($result['error'], 'Blacklisted') ||
                    str_contains($result['error'], 'Duplicate prevention') ||
                    str_contains($result['error'], 'Outside allowed')) {
                    return;
                }

                throw new \Exception($result['error']);
            }

        } catch (\Exception $e) {
            Log::error('Outreach email job failed', [
                'contact_id' => $this->contact->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Outreach email job failed permanently', [
            'contact_id' => $this->contact->id,
            'template_id' => $this->template->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
