<?php

namespace App\Filament\Resources\BlacklistEntryResource\Pages;

use App\Filament\Resources\BlacklistEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBlacklistEntry extends EditRecord
{
    protected static string $resource = BlacklistEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
