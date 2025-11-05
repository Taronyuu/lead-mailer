<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailReviewQueue extends Model
{
    use HasFactory;

    protected $table = 'email_review_queue';

    protected $fillable = [
        'website_id',
        'contact_id',
        'email_template_id',
        'smtp_credential_id',
        'generated_subject',
        'generated_body',
        'generated_preheader',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'priority',
    ];

    protected $casts = [
        'priority' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Relationships
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function smtpCredential(): BelongsTo
    {
        return $this->belongsTo(SmtpCredential::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 70);
    }

    /**
     * Approve email
     */
    public function approve(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Reject email
     */
    public function reject(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    /**
     * Check if email is ready to send
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
