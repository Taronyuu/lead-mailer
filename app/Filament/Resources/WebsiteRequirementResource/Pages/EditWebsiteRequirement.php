<?php

namespace App\Filament\Resources\WebsiteRequirementResource\Pages;

use App\Filament\Resources\WebsiteRequirementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWebsiteRequirement extends EditRecord
{
    protected static string $resource = WebsiteRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
