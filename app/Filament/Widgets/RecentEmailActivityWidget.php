<?php

namespace App\Filament\Widgets;

use App\Models\EmailSentLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentEmailActivityWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EmailSentLog::query()
                    ->with(['contact', 'website'])
                    ->latest('sent_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('recipient_email')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('website.url')
                    ->label('Website')
                    ->limit(50)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->limit(60)
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => EmailSentLog::STATUS_SENT,
                        'danger' => EmailSentLog::STATUS_FAILED,
                        'warning' => EmailSentLog::STATUS_BOUNCED,
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Sent At')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->defaultSort('sent_at', 'desc');
    }

    protected function getTableHeading(): string
    {
        return 'Recent Email Activity';
    }
}
