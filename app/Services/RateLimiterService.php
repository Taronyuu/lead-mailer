<?php

namespace App\Services;

use App\Models\SmtpCredential;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class RateLimiterService
{
    protected int $startHour;
    protected int $endHour;

    public function __construct()
    {
        $this->startHour = config('mail.sending_window.start', 8);
        $this->endHour = config('mail.sending_window.end', 17);
    }

    /**
     * Check if within allowed time window
     */
    public function isWithinTimeWindow(?Carbon $time = null): bool
    {
        $time = $time ?? now();
        $hour = $time->hour;

        return $hour >= $this->startHour && $hour < $this->endHour;
    }

    /**
     * Get next available sending time
     */
    public function getNextAvailableTime(): Carbon
    {
        $now = now();

        // If we're before start hour today, return start hour today
        if ($now->hour < $this->startHour) {
            return $now->copy()->setHour($this->startHour)->setMinute(0)->setSecond(0);
        }

        // If we're after end hour, return start hour tomorrow
        if ($now->hour >= $this->endHour) {
            return $now->copy()->addDay()->setHour($this->startHour)->setMinute(0)->setSecond(0);
        }

        // We're within window, return now
        return $now;
    }

    /**
     * Get remaining sends for today
     */
    public function getRemainingCapacity(): int
    {
        $smtp = SmtpCredential::available()->get();

        return $smtp->sum(function ($account) {
            return $account->daily_limit - $account->emails_sent_today;
        });
    }

    /**
     * Calculate delay between sends (throttling)
     */
    public function calculateDelay(int $totalToSend): int
    {
        if (!$this->isWithinTimeWindow()) {
            return 0;
        }

        $now = now();
        $endTime = $now->copy()->setHour($this->endHour);
        $remainingMinutes = $now->diffInMinutes($endTime);

        if ($totalToSend === 0) {
            return 0;
        }

        // Distribute sends evenly across remaining time
        $delayMinutes = $remainingMinutes / $totalToSend;

        return max(1, (int) $delayMinutes); // At least 1 minute
    }
}
