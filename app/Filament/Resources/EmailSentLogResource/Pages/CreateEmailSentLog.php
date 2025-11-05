<?php

namespace App\Filament\Resources\EmailSentLogResource\Pages;

use App\Filament\Resources\EmailSentLogResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailSentLog extends CreateRecord
{
    protected static string $resource = EmailSentLogResource::class;
}
