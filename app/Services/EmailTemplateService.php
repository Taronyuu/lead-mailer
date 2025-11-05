<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Website;
use Illuminate\Support\Str;

class EmailTemplateService
{
    protected MistralAIService $aiService;

    public function __construct(MistralAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Render email template with variables
     */
    public function render(
        EmailTemplate $template,
        Website $website,
        Contact $contact,
        array $additionalVars = []
    ): array {
        $variables = $this->buildVariables($website, $contact, $additionalVars);

        $subject = $this->replaceVariables($template->subject_template, $variables);

        \Log::info('EmailTemplateService render', [
            'subject_template' => $template->subject_template,
            'subject_after_replace' => $subject,
        ]);
        $body = $this->replaceVariables($template->body_template, $variables);

        // Use AI if enabled
        if ($template->ai_enabled) {
            $aiContent = $this->generateAIContent($template, $website, $contact);

            if ($aiContent) {
                $body = $aiContent['body'] ?? $body;
                $subject = $aiContent['subject'] ?? $subject;
            }
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'preheader' => $template->preheader,
        ];
    }

    /**
     * Generate AI-enhanced content
     */
    protected function generateAIContent(
        EmailTemplate $template,
        Website $website,
        Contact $contact
    ): ?array {
        $websiteContent = $website->content_snapshot ?? '';

        if (empty($websiteContent)) {
            return null;
        }

        // Truncate content if too long
        $websiteContent = Str::limit($websiteContent, 2000);

        $cleanUrl = $this->cleanUrl($website->url);

        $context = [
            'website_url' => $cleanUrl,
            'website_title' => $website->title,
            'platform' => $website->detected_platform,
            'contact_name' => $contact->name ?? 'there',
        ];

        // Generate body
        $body = $this->aiService->generateEmail(
            $template->ai_instructions ?? 'Write a friendly outreach email',
            $websiteContent,
            $context,
            $template->ai_tone,
            $template->ai_max_tokens
        );

        // Generate subject if not in template
        $subject = null;
        if (Str::contains($template->subject_template, '{{ai_subject}}')) {
            $subject = $this->aiService->generateSubject(
                $website->title ?? $cleanUrl,
                '',
                $template->ai_tone
            );
        }

        return [
            'body' => $body,
            'subject' => $subject,
        ];
    }

    /**
     * Build template variables
     */
    protected function buildVariables(
        Website $website,
        Contact $contact,
        array $additional = []
    ): array {
        $parsedUrl = parse_url($website->url);
        $cleanUrl = $this->cleanUrl($website->url);

        \Log::info('EmailTemplateService buildVariables', [
            'original_url' => $website->url,
            'clean_url' => $cleanUrl,
        ]);

        return array_merge([
            '{{website_url}}' => $cleanUrl,
            '{{website_title}}' => $website->title ?? $cleanUrl,
            '{{website_description}}' => $website->description ?? '',
            '{{contact_name}}' => $contact->name ?? 'there',
            '{{contact_email}}' => $contact->email,
            '{{platform}}' => ucfirst($website->detected_platform ?? 'unknown'),
            '{{page_count}}' => $website->page_count ?? 0,
            '{{domain}}' => $parsedUrl['host'] ?? '',
            '{{sender_name}}' => config('app.sender_name', 'Our Team'),
            '{{sender_company}}' => config('app.sender_company', 'Company'),
        ], $additional);
    }

    protected function cleanUrl(string $url): string
    {
        $url = preg_replace('#^https?://#', '', $url);
        $url = preg_replace('#^www\.#', '', $url);
        $url = rtrim($url, '/');

        return $url;
    }

    /**
     * Replace variables in template
     */
    protected function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace($key, $value, $template);
        }

        return $template;
    }

    /**
     * Preview template
     */
    public function preview(EmailTemplate $template, ?Website $website = null, ?Contact $contact = null): array
    {
        // Use sample data if not provided
        if (!$website) {
            $website = new Website([
                'url' => 'https://example.com',
                'title' => 'Example Website',
                'description' => 'A sample website for testing',
                'detected_platform' => 'wordpress',
                'page_count' => 25,
            ]);
        }

        if (!$contact) {
            $contact = new Contact([
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ]);
            $contact->website = $website;
        }

        return $this->render($template, $website, $contact);
    }
}
