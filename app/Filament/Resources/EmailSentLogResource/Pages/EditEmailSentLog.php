<?php

namespace App\Filament\Resources\EmailSentLogResource\Pages;

use App\Filament\Resources\EmailSentLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmailSentLog extends EditRecord
{
    protected static string $resource = EmailSentLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
