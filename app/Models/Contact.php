<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'domain_id',
        'email',
        'name',
        'phone',
        'position',
        'source_type',
        'source_url',
        'source_context',
        'priority',
        'is_validated',
        'is_valid',
        'validation_error',
        'validated_at',
        'contacted',
        'first_contacted_at',
        'last_contacted_at',
        'contact_count',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_validated' => 'boolean',
        'is_valid' => 'boolean',
        'contacted' => 'boolean',
        'contact_count' => 'integer',
        'validated_at' => 'datetime',
        'first_contacted_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    // Source type constants
    public const SOURCE_CONTACT_PAGE = 'contact_page';
    public const SOURCE_ABOUT_PAGE = 'about_page';
    public const SOURCE_FOOTER = 'footer';
    public const SOURCE_HEADER = 'header';
    public const SOURCE_BODY = 'body';
    public const SOURCE_TEAM_PAGE = 'team_page';

    /**
     * Relationships
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function emailSentLogs(): HasMany
    {
        return $this->hasMany(EmailSentLog::class);
    }

    public function getWebsiteAttribute()
    {
        return $this->domain?->website;
    }

    /**
     * Scopes
     */
    public function scopeValidated($query)
    {
        return $query->where('is_validated', true)
            ->where('is_valid', true);
    }

    public function scopeNotContacted($query)
    {
        return $query->where('contacted', false);
    }

    public function scopeContacted($query)
    {
        return $query->where('contacted', true);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 80);
    }

    public function scopeMediumPriority($query)
    {
        return $query->whereBetween('priority', [50, 79]);
    }

    public function scopeLowPriority($query)
    {
        return $query->where('priority', '<', 50);
    }

    /**
     * Helper Methods
     */
    public function markAsValidated(bool $isValid, ?string $error = null): void
    {
        $this->update([
            'is_validated' => true,
            'is_valid' => $isValid,
            'validation_error' => $error,
            'validated_at' => now(),
        ]);
    }

    public function markAsContacted(): void
    {
        $firstContact = $this->first_contacted_at === null;

        $this->update([
            'contacted' => true,
            'first_contacted_at' => $firstContact ? now() : $this->first_contacted_at,
            'last_contacted_at' => now(),
        ]);

        $this->increment('contact_count');
    }

    public function isValidated(): bool
    {
        return $this->is_validated && $this->is_valid;
    }

    public function isContacted(): bool
    {
        return $this->contacted;
    }

    public function calculatePriority(): int
    {
        $priority = 50; // Base priority

        // Source type bonus
        $sourceBonus = match($this->source_type) {
            self::SOURCE_CONTACT_PAGE => 30,
            self::SOURCE_ABOUT_PAGE => 20,
            self::SOURCE_TEAM_PAGE => 25,
            self::SOURCE_HEADER => 15,
            self::SOURCE_FOOTER => 10,
            default => 5,
        };

        $priority += $sourceBonus;

        // Has name bonus
        if (!empty($this->name)) {
            $priority += 10;
        }

        // Has position bonus
        if (!empty($this->position)) {
            $priority += 5;
        }

        return min(100, $priority);
    }
}
