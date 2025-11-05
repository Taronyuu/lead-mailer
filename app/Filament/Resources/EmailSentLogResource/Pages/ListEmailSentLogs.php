<?php

namespace App\Filament\Resources\EmailSentLogResource\Pages;

use App\Filament\Resources\EmailSentLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmailSentLogs extends ListRecords
{
    protected static string $resource = EmailSentLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
