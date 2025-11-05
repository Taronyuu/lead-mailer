<?php

namespace App\Filament\Resources\EmailReviewQueueResource\Pages;

use App\Filament\Resources\EmailReviewQueueResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewEmailReviewQueue extends ViewRecord
{
    protected static string $resource = EmailReviewQueueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
