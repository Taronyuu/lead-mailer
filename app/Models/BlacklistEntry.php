<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlacklistEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'type',
        'value',
        'is_active',
        'reason',
        'source',
        'added_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Type constants
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_EMAIL = 'email';

    // Source constants
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_AUTO = 'auto';

    /**
     * Relationships
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopeDomains($query)
    {
        return $query->where('type', self::TYPE_DOMAIN);
    }

    public function scopeEmails($query)
    {
        return $query->where('type', self::TYPE_EMAIL);
    }
}
