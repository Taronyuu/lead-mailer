<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MistralAIService
{
    protected ?string $apiKey;
    protected string $baseUrl = 'https://api.mistral.ai/v1';

    public function __construct()
    {
        $this->apiKey = config('services.mistral.api_key');
    }

    /**
     * Generate personalized email content
     */
    public function generateEmail(
        string $instructions,
        string $websiteContent,
        array $context = [],
        string $tone = 'professional',
        int $maxTokens = 500
    ): ?string {
        try {
            $prompt = $this->buildPrompt($instructions, $websiteContent, $context, $tone);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/chat/completions', [
                'model' => 'mistral-small-latest',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional email copywriter specializing in personalized outreach emails.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['choices'][0]['message']['content'] ?? null;
            }

            Log::error('Mistral AI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Mistral AI exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build prompt for AI
     */
    protected function buildPrompt(
        string $instructions,
        string $websiteContent,
        array $context,
        string $tone
    ): string {
        $contextStr = '';
        foreach ($context as $key => $value) {
            $contextStr .= "{$key}: {$value}\n";
        }

        return <<<PROMPT
Write a personalized email based on the following:

TONE: {$tone}

INSTRUCTIONS:
{$instructions}

WEBSITE CONTEXT:
{$contextStr}

WEBSITE CONTENT:
{$websiteContent}

Generate only the email content without subject line. Keep it concise and personalized.
PROMPT;
    }

    /**
     * Generate email subject
     */
    public function generateSubject(
        string $websiteTitle,
        string $context = '',
        string $tone = 'professional'
    ): ?string {
        try {
            $prompt = <<<PROMPT
Generate a compelling email subject line for an outreach email to: {$websiteTitle}

Context: {$context}
Tone: {$tone}

Generate only the subject line, no quotes, maximum 60 characters.
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->baseUrl . '/chat/completions', [
                'model' => 'mistral-small-latest',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.8,
                'max_tokens' => 50,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $subject = $data['choices'][0]['message']['content'] ?? null;
                return trim(str_replace(['"', "'"], '', $subject));
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Mistral AI subject generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
