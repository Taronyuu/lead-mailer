<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\EmailTemplate;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_fillable_attributes(): void
    {
        $fillable = [
            'name',
            'description',
            'subject_template',
            'body_template',
            'preheader',
            'ai_enabled',
            'ai_instructions',
            'ai_tone',
            'ai_max_tokens',
            'is_active',
            'is_default',
            'usage_count',
            'available_variables',
            'metadata',
        ];

        $template = new EmailTemplate();

        $this->assertEquals($fillable, $template->getFillable());
    }

    public function test_it_casts_attributes_correctly(): void
    {
        $template = EmailTemplate::factory()->create([
            'ai_enabled' => true,
            'is_active' => true,
            'is_default' => false,
            'usage_count' => '50',
            'ai_max_tokens' => '500',
            'available_variables' => ['var1', 'var2'],
            'metadata' => ['key' => 'value'],
        ]);

        $this->assertIsBool($template->ai_enabled);
        $this->assertIsBool($template->is_active);
        $this->assertIsBool($template->is_default);
        $this->assertIsInt($template->usage_count);
        $this->assertIsInt($template->ai_max_tokens);
        $this->assertIsArray($template->available_variables);
        $this->assertIsArray($template->metadata);
    }

    public function test_it_uses_soft_deletes(): void
    {
        $template = EmailTemplate::factory()->create();

        $template->delete();

        $this->assertSoftDeleted('email_templates', ['id' => $template->id]);
        $this->assertNotNull($template->fresh()->deleted_at);
    }

    public function test_active_scope_works(): void
    {
        EmailTemplate::factory()->create(['is_active' => true]);
        EmailTemplate::factory()->create(['is_active' => false]);
        EmailTemplate::factory()->create(['is_active' => true]);

        $active = EmailTemplate::active()->get();

        $this->assertCount(2, $active);
        $active->each(fn($template) => $this->assertTrue($template->is_active));
    }

    public function test_ai_enabled_scope_works(): void
    {
        EmailTemplate::factory()->create(['ai_enabled' => true]);
        EmailTemplate::factory()->create(['ai_enabled' => false]);
        EmailTemplate::factory()->create(['ai_enabled' => true]);

        $aiEnabled = EmailTemplate::aiEnabled()->get();

        $this->assertCount(2, $aiEnabled);
        $aiEnabled->each(fn($template) => $this->assertTrue($template->ai_enabled));
    }

    public function test_increment_usage_increments_count(): void
    {
        $template = EmailTemplate::factory()->create(['usage_count' => 10]);

        $template->incrementUsage();

        $this->assertEquals(11, $template->fresh()->usage_count);
    }

    public function test_get_default_variables_returns_correct_array(): void
    {
        $variables = EmailTemplate::getDefaultVariables();

        $this->assertIsArray($variables);
        $this->assertArrayHasKey('{{website_url}}', $variables);
        $this->assertArrayHasKey('{{website_title}}', $variables);
        $this->assertArrayHasKey('{{website_description}}', $variables);
        $this->assertArrayHasKey('{{contact_name}}', $variables);
        $this->assertArrayHasKey('{{contact_email}}', $variables);
        $this->assertArrayHasKey('{{platform}}', $variables);
        $this->assertArrayHasKey('{{page_count}}', $variables);
        $this->assertArrayHasKey('{{domain}}', $variables);
        $this->assertArrayHasKey('{{sender_name}}', $variables);
        $this->assertArrayHasKey('{{sender_company}}', $variables);
    }

    public function test_get_default_variables_has_descriptions(): void
    {
        $variables = EmailTemplate::getDefaultVariables();

        foreach ($variables as $key => $description) {
            $this->assertNotEmpty($description);
            $this->assertIsString($description);
        }
    }

    public function test_tone_constants_are_defined(): void
    {
        $this->assertEquals('professional', EmailTemplate::TONE_PROFESSIONAL);
        $this->assertEquals('friendly', EmailTemplate::TONE_FRIENDLY);
        $this->assertEquals('casual', EmailTemplate::TONE_CASUAL);
        $this->assertEquals('formal', EmailTemplate::TONE_FORMAL);
    }

    public function test_factory_creates_valid_email_template(): void
    {
        $template = EmailTemplate::factory()->create();

        $this->assertInstanceOf(EmailTemplate::class, $template);
        $this->assertNotNull($template->name);
        $this->assertNotNull($template->subject_template);
        $this->assertNotNull($template->body_template);
        $this->assertDatabaseHas('email_templates', ['id' => $template->id]);
    }

    public function test_factory_can_create_ai_enabled_template(): void
    {
        $template = EmailTemplate::factory()->create(['ai_enabled' => true]);

        $this->assertTrue($template->ai_enabled);
        $this->assertNotNull($template->ai_tone);
    }

    public function test_factory_can_create_default_template(): void
    {
        $template = EmailTemplate::factory()->create(['is_default' => true]);

        $this->assertTrue($template->is_default);
    }
}
