<?php

namespace App\Jobs;

use App\Models\EmailReviewQueue;
use App\Services\ReviewQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEmailReviewQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;

    protected EmailReviewQueue $email;

    public function __construct(EmailReviewQueue $email)
    {
        $this->email = $email;
    }

    public function handle(ReviewQueueService $reviewService): void
    {
        try {
            Log::info('SendEmailReviewQueueJob: Starting', [
                'email_id' => $this->email->id,
            ]);

            Log::info('SendEmailReviewQueueJob: Loading relationships', [
                'email_id' => $this->email->id,
                'has_contact' => $this->email->contact !== null,
                'has_website' => $this->email->website !== null,
            ]);

            Log::info('Sending email from review queue', [
                'email_id' => $this->email->id,
                'contact_email' => $this->email->contact->email,
                'subject' => $this->email->generated_subject,
            ]);

            Log::info('SendEmailReviewQueueJob: Calling sendApproved', [
                'email_id' => $this->email->id,
            ]);

            $result = $reviewService->sendApproved($this->email);

            if ($result['success']) {
                Log::info('Email sent successfully', [
                    'email_id' => $this->email->id,
                    'contact_email' => $this->email->contact->email,
                ]);
            } else {
                Log::error('Email failed to send', [
                    'email_id' => $this->email->id,
                    'contact_email' => $this->email->contact->email,
                    'error' => $result['error'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Email sending failed', [
                'email_id' => $this->email->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Email sending job failed permanently', [
            'email_id' => $this->email->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
