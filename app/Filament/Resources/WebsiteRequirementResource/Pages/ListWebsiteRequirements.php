<?php

namespace App\Filament\Resources\WebsiteRequirementResource\Pages;

use App\Filament\Resources\WebsiteRequirementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsiteRequirements extends ListRecords
{
    protected static string $resource = WebsiteRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
