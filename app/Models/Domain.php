<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class, 'domain_website')
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

    public function markAsEvaluated(
        Website $website,
        bool $matches,
        array $matchDetails,
        int $pageCount,
        int $wordCount,
        ?string $detectedPlatform,
        string $htmlSnapshot
    ): void {
        $this->websites()->syncWithoutDetaching([
            $website->id => [
                'matches' => $matches,
                'match_details' => json_encode($matchDetails),
                'page_count' => $pageCount,
                'word_count' => $wordCount,
                'detected_platform' => $detectedPlatform,
                'html_snapshot' => $htmlSnapshot,
                'evaluated_at' => now(),
            ]
        ]);
    }
}
