<?php

namespace App\Filament\Resources\EmailReviewQueueResource\Pages;

use App\Filament\Resources\EmailReviewQueueResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmailReviewQueues extends ListRecords
{
    protected static string $resource = EmailReviewQueueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
