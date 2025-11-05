<?php

namespace App\Filament\Widgets;

use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailReviewQueue;
use App\Models\EmailSentLog;
use App\Models\Website;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Total Websites', Website::count())
                ->description('Websites in database')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),

            Stat::make('Total Domains', Domain::count())
                ->description('Domains in system')
                ->descriptionIcon('heroicon-m-server')
                ->color('info'),

            Stat::make('Total Contacts', Contact::count())
                ->description('Email contacts extracted')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info'),

            Stat::make('Validated Contacts', Contact::where('is_validated', true)->where('is_valid', true)->count())
                ->description('Contacts with valid emails')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),

            Stat::make('Emails Sent', EmailSentLog::where('status', EmailSentLog::STATUS_SENT)->count())
                ->description('Successfully sent emails')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('success'),

            Stat::make('Pending Review', EmailReviewQueue::where('status', EmailReviewQueue::STATUS_PENDING)->count())
                ->description('Emails awaiting review')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }
}
