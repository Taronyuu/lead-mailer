<?php

namespace App\Filament\Resources\EmailReviewQueueResource\Pages;

use App\Filament\Resources\EmailReviewQueueResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmailReviewQueue extends EditRecord
{
    protected static string $resource = EmailReviewQueueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
