<?php

namespace App\Filament\Resources\SmtpCredentialResource\Pages;

use App\Filament\Resources\SmtpCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSmtpCredentials extends ListRecords
{
    protected static string $resource = SmtpCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
