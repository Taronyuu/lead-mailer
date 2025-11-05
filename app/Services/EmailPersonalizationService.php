<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Website;
use Illuminate\Support\Str;

class EmailPersonalizationService
{
    /**
     * Personalize email content
     */
    public function personalize(string $content, Website $website, Contact $contact): string
    {
        // Add personalized greeting
        $content = $this->addPersonalizedGreeting($content, $contact);

        // Add website-specific context
        $content = $this->addWebsiteContext($content, $website);

        return $content;
    }

    /**
     * Add personalized greeting
     */
    protected function addPersonalizedGreeting(string $content, Contact $contact): string
    {
        if ($contact->name) {
            $greeting = "Hi {$contact->name},\n\n";
        } else {
            $greeting = "Hello,\n\n";
        }

        // Add greeting if not present
        if (!Str::startsWith($content, ['Hi ', 'Hello ', 'Hey '])) {
            $content = $greeting . $content;
        }

        return $content;
    }

    /**
     * Add website-specific context
     */
    protected function addWebsiteContext(string $content, Website $website): string
    {
        // This can be expanded with more sophisticated context addition
        return $content;
    }
}
