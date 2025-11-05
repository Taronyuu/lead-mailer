<?php

namespace App\Filament\Resources\BlacklistEntryResource\Pages;

use App\Filament\Resources\BlacklistEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBlacklistEntry extends CreateRecord
{
    protected static string $resource = BlacklistEntryResource::class;
}
