<?php

namespace App\Services;

use App\Models\SmtpCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SmtpRotationService
{
    /**
     * Get available SMTP account
     */
    public function getAvailableSmtp(): ?SmtpCredential
    {
        // Get all available SMTP accounts
        $available = SmtpCredential::available()->get();

        if ($available->isEmpty()) {
            return null;
        }

        // Use round-robin or least-used strategy
        return $this->selectLeastUsed($available);
    }

    /**
     * Select SMTP with least usage today
     */
    protected function selectLeastUsed($smtpAccounts): SmtpCredential
    {
        return $smtpAccounts->sortBy('emails_sent_today')->first();
    }

    /**
     * Check if SMTP is healthy
     */
    public function isHealthy(SmtpCredential $smtp): bool
    {
        // Calculate success rate
        $total = $smtp->success_count + $smtp->failure_count;

        if ($total === 0) {
            return true; // No history yet
        }

        $successRate = ($smtp->success_count / $total) * 100;

        // Disable if success rate below 70%
        if ($successRate < 70) {
            return false;
        }

        return true;
    }

    /**
     * Auto-disable failing SMTP accounts
     */
    public function checkAndDisableUnhealthy(): void
    {
        $smtpAccounts = SmtpCredential::where('is_active', true)->get();

        foreach ($smtpAccounts as $smtp) {
            if (!$this->isHealthy($smtp)) {
                $smtp->update(['is_active' => false]);

                Log::warning('SMTP account auto-disabled due to low success rate', [
                    'smtp_id' => $smtp->id,
                    'success_count' => $smtp->success_count,
                    'failure_count' => $smtp->failure_count,
                ]);
            }
        }
    }
}
