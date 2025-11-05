<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Website extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'url',
        'smtp_credential_id',
        'default_email_template_id',
        'title',
        'description',
    ];

    public function smtpCredential(): BelongsTo
    {
        return $this->belongsTo(SmtpCredential::class);
    }

    public function defaultEmailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'default_email_template_id');
    }

    public function emailSentLogs(): HasMany
    {
        return $this->hasMany(EmailSentLog::class);
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function smtpCredentials(): HasMany
    {
        return $this->hasMany(SmtpCredential::class);
    }

    public function blacklistEntries(): HasMany
    {
        return $this->hasMany(BlacklistEntry::class);
    }

    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(WebsiteRequirement::class, 'website_requirement_matches')
            ->withPivot('matches', 'match_details')
            ->withTimestamps();
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'domain_website')
            ->withPivot([
                'matches',
                'match_details',
                'page_count',
                'word_count',
                'detected_platform',
                'html_snapshot',
                'evaluated_at',
            ])
            ->withTimestamps();
    }

    public function matchedDomains(): BelongsToMany
    {
        return $this->domains()->wherePivot('matches', true);
    }
}
