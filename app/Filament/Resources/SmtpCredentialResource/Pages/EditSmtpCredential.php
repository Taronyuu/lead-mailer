<?php

namespace App\Filament\Resources\SmtpCredentialResource\Pages;

use App\Filament\Resources\SmtpCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSmtpCredential extends EditRecord
{
    protected static string $resource = SmtpCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
