<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'website_id',
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

    protected $casts = [
        'ai_enabled' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'usage_count' => 'integer',
        'ai_max_tokens' => 'integer',
        'available_variables' => 'array',
        'metadata' => 'array',
    ];

    // AI Tone constants
    public const TONE_PROFESSIONAL = 'professional';
    public const TONE_FRIENDLY = 'friendly';
    public const TONE_CASUAL = 'casual';
    public const TONE_FORMAL = 'formal';

    /**
     * Relationships
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAiEnabled($query)
    {
        return $query->where('ai_enabled', true);
    }

    /**
     * Helper Methods
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Get default variables
     */
    public static function getDefaultVariables(): array
    {
        return [
            '{{website_url}}' => 'The website URL',
            '{{website_title}}' => 'The website title',
            '{{website_description}}' => 'The website meta description',
            '{{contact_name}}' => 'Contact name if available',
            '{{contact_email}}' => 'Contact email address',
            '{{platform}}' => 'Detected platform (WordPress, Shopify, etc.)',
            '{{page_count}}' => 'Number of pages on website',
            '{{domain}}' => 'Domain name only',
            '{{sender_name}}' => 'Your name',
            '{{sender_company}}' => 'Your company name',
        ];
    }
}
