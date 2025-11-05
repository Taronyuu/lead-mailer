<?php

namespace App\Filament\Resources\EmailSentLogResource\Pages;

use App\Filament\Resources\EmailSentLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewEmailSentLog extends ViewRecord
{
    protected static string $resource = EmailSentLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
