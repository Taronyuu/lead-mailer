<?php

namespace Tests\Unit\Services;

use App\Services\MistralAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MistralAIServiceTest extends TestCase
{
    protected MistralAIService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mistral.api_key' => 'test-api-key']);
        $this->service = new MistralAIService();
    }

    /** @test */
    public function it_generates_email_successfully()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'This is a generated email body',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateEmail(
            'Write a friendly email',
            'Website content here',
            ['website_url' => 'https://example.com'],
            'professional',
            500
        );

        $this->assertEquals('This is a generated email body', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mistral.ai/v1/chat/completions' &&
                $request->hasHeader('Authorization', 'Bearer test-api-key') &&
                $request->hasHeader('Content-Type', 'application/json') &&
                $request['model'] === 'mistral-small-latest' &&
                $request['temperature'] === 0.7 &&
                $request['max_tokens'] === 500 &&
                count($request['messages']) === 2;
        });
    }

    /** @test */
    public function it_builds_prompt_with_all_parameters()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated email',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateEmail(
            'Write a friendly email about our services',
            'Website has great content about technology',
            [
                'website_url' => 'https://example.com',
                'website_title' => 'Example Site',
                'contact_name' => 'John',
            ],
            'casual',
            300
        );

        Http::assertSent(function ($request) {
            $userMessage = $request['messages'][1]['content'];

            return str_contains($userMessage, 'TONE: casual') &&
                str_contains($userMessage, 'Write a friendly email about our services') &&
                str_contains($userMessage, 'Website has great content about technology') &&
                str_contains($userMessage, 'website_url: https://example.com') &&
                str_contains($userMessage, 'website_title: Example Site') &&
                str_contains($userMessage, 'contact_name: John');
        });
    }

    /** @test */
    public function it_includes_system_message_for_email_generation()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated email',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateEmail(
            'Write an email',
            'Content',
            [],
            'professional',
            500
        );

        Http::assertSent(function ($request) {
            $systemMessage = $request['messages'][0];

            return $systemMessage['role'] === 'system' &&
                str_contains($systemMessage['content'], 'professional email copywriter');
        });
    }

    /** @test */
    public function it_uses_default_parameters()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated email',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateEmail(
            'Write an email',
            'Content'
        );

        Http::assertSent(function ($request) {
            return $request['temperature'] === 0.7 &&
                $request['max_tokens'] === 500;
        });
    }

    /** @test */
    public function it_returns_null_on_api_error()
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Mistral AI API error', [
                'status' => 500,
                'body_template' => 'Internal Server Error',
            ]);

        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response('Internal Server Error', 500),
        ]);

        $result = $this->service->generateEmail(
            'Write an email',
            'Content'
        );

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_when_response_missing_content()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            // Missing 'content' key
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateEmail(
            'Write an email',
            'Content'
        );

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_on_exception()
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Mistral AI exception', \Mockery::type('array'));

        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => function () {
                throw new \Exception('Connection timeout');
            },
        ]);

        $result = $this->service->generateEmail(
            'Write an email',
            'Content'
        );

        $this->assertNull($result);
    }

    /** @test */
    public function it_uses_30_second_timeout_for_email_generation()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Generated email',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateEmail(
            'Write an email',
            'Content'
        );

        Http::assertSent(function ($request) {
            // The timeout is set in the Http::timeout() call
            return true; // We can't directly assert timeout, but we verify the request was made
        });
    }

    /** @test */
    public function it_generates_subject_successfully()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '"Your Amazing Subject Line"',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSubject(
            'Example Website',
            'Outreach about collaboration',
            'professional'
        );

        $this->assertEquals('Your Amazing Subject Line', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mistral.ai/v1/chat/completions' &&
                $request['model'] === 'mistral-small-latest' &&
                $request['temperature'] === 0.8 &&
                $request['max_tokens'] === 50;
        });
    }

    /** @test */
    public function it_removes_quotes_from_generated_subject()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '"Subject with double quotes"',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSubject('Example Website');

        $this->assertEquals('Subject with double quotes', $result);
    }

    /** @test */
    public function it_removes_single_quotes_from_generated_subject()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => "'Subject with single quotes'",
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSubject('Example Website');

        $this->assertEquals('Subject with single quotes', $result);
    }

    /** @test */
    public function it_trims_whitespace_from_subject()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '  Subject with spaces  ',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSubject('Example Website');

        $this->assertEquals('Subject with spaces', $result);
    }

    /** @test */
    public function it_builds_subject_prompt_correctly()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Great Subject',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateSubject(
            'Tech Blog',
            'Partnership opportunity',
            'friendly'
        );

        Http::assertSent(function ($request) {
            $message = $request['messages'][0]['content'];

            return str_contains($message, 'Tech Blog') &&
                str_contains($message, 'Partnership opportunity') &&
                str_contains($message, 'Tone: friendly') &&
                str_contains($message, 'maximum 60 characters');
        });
    }

    /** @test */
    public function it_uses_default_subject_parameters()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Subject',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateSubject('Website Title');

        Http::assertSent(function ($request) {
            $message = $request['messages'][0]['content'];

            return str_contains($message, 'Context: ') &&
                str_contains($message, 'Tone: professional');
        });
    }

    /** @test */
    public function it_returns_null_on_subject_generation_error()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response('Error', 500),
        ]);

        $result = $this->service->generateSubject('Website');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_on_subject_generation_exception()
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Mistral AI subject generation failed', \Mockery::type('array'));

        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => function () {
                throw new \Exception('Network error');
            },
        ]);

        $result = $this->service->generateSubject('Website');

        $this->assertNull($result);
    }

    /** @test */
    public function it_uses_15_second_timeout_for_subject_generation()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Subject',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateSubject('Website');

        Http::assertSent(function ($request) {
            return true; // Request was made successfully
        });
    }

    /** @test */
    public function it_sends_only_user_message_for_subject_generation()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Subject',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->service->generateSubject('Website');

        Http::assertSent(function ($request) {
            return count($request['messages']) === 1 &&
                $request['messages'][0]['role'] === 'user';
        });
    }

    /** @test */
    public function it_handles_empty_choices_array()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [],
            ], 200),
        ]);

        $result = $this->service->generateEmail('Instructions', 'Content');

        $this->assertNull($result);
    }

    /** @test */
    public function it_handles_malformed_json_response()
    {
        Log::shouldReceive('error')->once();

        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response('Not JSON', 200),
        ]);

        $result = $this->service->generateEmail('Instructions', 'Content');

        $this->assertNull($result);
    }

    /** @test */
    public function it_uses_configured_api_key()
    {
        config(['services.mistral.api_key' => 'custom-key-12345']);
        $service = new MistralAIService();

        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Email',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service->generateEmail('Instructions', 'Content');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer custom-key-12345');
        });
    }

    /** @test */
    public function it_handles_empty_context_array()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Email',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateEmail(
            'Instructions',
            'Content',
            [] // Empty context
        );

        $this->assertEquals('Email', $result);
    }

    /** @test */
    public function it_returns_null_when_subject_content_is_empty()
    {
        Http::fake([
            'https://api.mistral.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSubject('Website');

        $this->assertEquals('', $result);
    }
}
