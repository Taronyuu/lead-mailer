<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsiteRequirement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'criteria',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'criteria' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function calculateRequiredMaxPages(): int
    {
        $requirements = static::active()->get();

        if ($requirements->isEmpty()) {
            return 10;
        }

        $maxPagesNeeded = 10;

        foreach ($requirements as $requirement) {
            $criteria = $requirement->criteria ?? [];

            if (isset($criteria['min_pages'])) {
                $minPages = static::parseNumericValue($criteria['min_pages']);
                if ($minPages > 0) {
                    $requiredPages = $minPages + 5;
                    $maxPagesNeeded = max($maxPagesNeeded, $requiredPages);
                }
            }
        }

        return $maxPagesNeeded;
    }

    protected static function parseNumericValue($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = preg_replace('/[^0-9]/', '', $value);
        }

        return (int) $value;
    }
}
