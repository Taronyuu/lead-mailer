<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailSentLog;
use App\Models\EmailTemplate;
use App\Models\SmtpCredential;
use App\Models\Website;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailSendingService
{
    protected SmtpRotationService $smtpRotation;
    protected RateLimiterService $rateLimiter;
    protected DuplicatePreventionService $dupeService;
    protected EmailTemplateService $templateService;
    protected BlacklistService $blacklistService;

    public function __construct(
        SmtpRotationService $smtpRotation,
        RateLimiterService $rateLimiter,
        DuplicatePreventionService $dupeService,
        EmailTemplateService $templateService,
        BlacklistService $blacklistService
    ) {
        $this->smtpRotation = $smtpRotation;
        $this->rateLimiter = $rateLimiter;
        $this->dupeService = $dupeService;
        $this->templateService = $templateService;
        $this->blacklistService = $blacklistService;
    }

    /**
     * Send email to contact
     */
    public function send(
        Contact $contact,
        EmailTemplate $template,
        ?SmtpCredential $smtp = null
    ): array {
        if (!app()->environment('local')) {
            if (!$this->rateLimiter->isWithinTimeWindow()) {
                return [
                    'success' => false,
                    'error' => 'Outside allowed sending hours (8AM-5PM)',
                ];
            }
        }

        // Check blacklist
        $blacklistCheck = $this->blacklistService->isContactBlacklisted($contact);
        if ($blacklistCheck['blacklisted']) {
            return [
                'success' => false,
                'error' => 'Blacklisted: ' . implode(', ', $blacklistCheck['reasons']),
            ];
        }

        // Check for duplicates
        $dupeCheck = $this->dupeService->isSafeToContact($contact);
        if (!$dupeCheck['safe']) {
            return [
                'success' => false,
                'error' => 'Duplicate prevention: ' . implode(', ', $dupeCheck['reasons']),
            ];
        }

        // Get available SMTP
        if (!$smtp) {
            $smtp = $this->smtpRotation->getAvailableSmtp();

            if (!$smtp) {
                return [
                    'success' => false,
                    'error' => 'No available SMTP accounts',
                ];
            }
        }

        // Render email
        $email = $this->templateService->render(
            $template,
            $contact->website,
            $contact
        );

        // Send email
        try {
            $this->sendViaSmtp($smtp, $contact, $email);

            // Record success
            $log = $this->dupeService->recordEmail([
                'website_id' => $contact->website_id,
                'contact_id' => $contact->id,
                'smtp_credential_id' => $smtp->id,
                'email_template_id' => $template->id,
                'recipient_email' => $contact->email,
                'recipient_name' => $contact->name,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'status' => EmailSentLog::STATUS_SENT,
            ]);

            // Update SMTP stats
            $smtp->incrementSentCount();

            // Increment template usage
            $template->incrementUsage();

            Log::info('Email sent successfully', [
                'contact_id' => $contact->id,
                'smtp_id' => $smtp->id,
                'log_id' => $log->id,
            ]);

            return [
                'success' => true,
                'log_id' => $log->id,
                'smtp_id' => $smtp->id,
            ];

        } catch (\Exception $e) {
            // Record failure
            $this->dupeService->recordEmail([
                'website_id' => $contact->website_id,
                'contact_id' => $contact->id,
                'smtp_credential_id' => $smtp->id,
                'email_template_id' => $template->id,
                'recipient_email' => $contact->email,
                'recipient_name' => $contact->name,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'status' => EmailSentLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $smtp->recordFailure();

            Log::error('Email send failed', [
                'contact_id' => $contact->id,
                'smtp_id' => $smtp->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function sendFromReviewQueue(
        Contact $contact,
        Website $website,
        EmailTemplate $template,
        ?SmtpCredential $smtp,
        string $subject,
        string $body,
        ?string $preheader = null
    ): array {
        if (!app()->environment('local')) {
            if (!$this->rateLimiter->isWithinTimeWindow()) {
                return [
                    'success' => false,
                    'error' => 'Outside allowed sending hours (8AM-5PM)',
                ];
            }
        }

        $blacklistCheck = $this->blacklistService->isContactBlacklisted($contact);
        if ($blacklistCheck['blacklisted']) {
            return [
                'success' => false,
                'error' => 'Contact is blacklisted: ' . $blacklistCheck['reason'],
            ];
        }

        if (!$smtp) {
            $smtp = $this->smtpRotation->getNextCredential();
        }

        $email = [
            'subject' => $subject,
            'body' => $body,
            'preheader' => $preheader,
        ];

        try {
            $this->sendViaSmtp($smtp, $contact, $email);

            $log = $this->dupeService->recordEmail([
                'website_id' => $website->id,
                'contact_id' => $contact->id,
                'smtp_credential_id' => $smtp->id,
                'email_template_id' => $template->id,
                'recipient_email' => $contact->email,
                'recipient_name' => $contact->name,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'status' => EmailSentLog::STATUS_SENT,
            ]);

            $smtp->recordSuccess();
            $contact->markAsContacted();

            return [
                'success' => true,
                'log_id' => $log->id,
            ];
        } catch (\Exception $e) {
            $this->dupeService->recordEmail([
                'website_id' => $website->id,
                'contact_id' => $contact->id,
                'smtp_credential_id' => $smtp->id,
                'email_template_id' => $template->id,
                'recipient_email' => $contact->email,
                'recipient_name' => $contact->name,
                'subject' => $email['subject'],
                'body' => $email['body'],
                'status' => EmailSentLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $smtp->recordFailure();

            Log::error('Email send failed from review queue', [
                'contact_id' => $contact->id,
                'smtp_id' => $smtp->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send email via SMTP
     */
    protected function sendViaSmtp(SmtpCredential $smtp, Contact $contact, array $email): void
    {
        // Configure mailer with SMTP credentials
        config([
            'mail.mailers.smtp.host' => $smtp->host,
            'mail.mailers.smtp.port' => $smtp->port,
            'mail.mailers.smtp.encryption' => $smtp->encryption,
            'mail.mailers.smtp.username' => $smtp->username,
            'mail.mailers.smtp.password' => $smtp->password,
            'mail.from.address' => $smtp->from_address,
            'mail.from.name' => $smtp->from_name,
        ]);

        Mail::raw($email['body'], function ($message) use ($contact, $email) {
            $message->to($contact->email, $contact->name)
                ->subject($email['subject']);

            if (isset($email['preheader'])) {
                $message->getHeaders()->addTextHeader('X-Preheader', $email['preheader']);
            }
        });
    }
}
