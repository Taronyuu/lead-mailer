<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmtpCredential extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'website_id',
        'name',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'is_active',
        'daily_limit',
        'emails_sent_today',
        'last_reset_date',
        'last_used_at',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'port' => 'integer',
        'is_active' => 'boolean',
        'daily_limit' => 'integer',
        'emails_sent_today' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'last_reset_date' => 'date',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

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

    public function scopeAvailable($query)
    {
        return $query->active()
            ->whereColumn('emails_sent_today', '<', 'daily_limit');
    }

    /**
     * Helper Methods
     */
    public function incrementSentCount(): void
    {
        $this->increment('emails_sent_today');
        $this->increment('success_count');
        $this->update(['last_used_at' => now()]);
    }

    public function recordFailure(): void
    {
        $this->increment('failure_count');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && ($this->emails_sent_today < $this->daily_limit);
    }

    public function getRemainingCapacity(): int
    {
        return max(0, $this->daily_limit - $this->emails_sent_today);
    }
}
