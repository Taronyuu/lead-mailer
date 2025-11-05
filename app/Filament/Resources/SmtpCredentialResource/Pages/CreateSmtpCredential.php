<?php

namespace App\Filament\Resources\SmtpCredentialResource\Pages;

use App\Filament\Resources\SmtpCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSmtpCredential extends CreateRecord
{
    protected static string $resource = SmtpCredentialResource::class;
}
