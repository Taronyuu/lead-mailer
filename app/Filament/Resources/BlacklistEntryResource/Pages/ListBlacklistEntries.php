<?php

namespace App\Filament\Resources\BlacklistEntryResource\Pages;

use App\Filament\Resources\BlacklistEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBlacklistEntries extends ListRecords
{
    protected static string $resource = BlacklistEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
