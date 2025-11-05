<?php

namespace App\Filament\Resources\DomainResource\RelationManagers;

use App\Jobs\CrawlWebsiteJob;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class WebsitesRelationManager extends RelationManager
{
    protected static string $relationship = 'websites';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('url')
                    ->required()
                    ->url()
                    ->maxLength(500),

                Forms\Components\Select::make('status')
                    ->options([
                        Website::STATUS_PENDING => 'Pending',
                        Website::STATUS_CRAWLING => 'Crawling',
                        Website::STATUS_COMPLETED => 'Completed',
                        Website::STATUS_FAILED => 'Failed',
                    ])
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
            ->columns([
                Tables\Columns\TextColumn::make('url')
                    ->searchable()
                    ->limit(50)
                    ->copyable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (int $state): string => match ($state) {
                        Website::STATUS_PENDING => 'gray',
                        Website::STATUS_CRAWLING => 'info',
                        Website::STATUS_COMPLETED => 'success',
                        Website::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        Website::STATUS_PENDING => 'Pending',
                        Website::STATUS_CRAWLING => 'Crawling',
                        Website::STATUS_COMPLETED => 'Completed',
                        Website::STATUS_FAILED => 'Failed',
                        default => 'Unknown',
                    }),

                Tables\Columns\IconColumn::make('meets_requirements')
                    ->label('Qualified')
                    ->boolean(),

                Tables\Columns\TextColumn::make('detected_platform')
                    ->label('Platform')
                    ->badge(),

                Tables\Columns\TextColumn::make('contacts_count')
                    ->counts('contacts')
                    ->label('Contacts')
                    ->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Website::STATUS_PENDING => 'Pending',
                        Website::STATUS_CRAWLING => 'Crawling',
                        Website::STATUS_COMPLETED => 'Completed',
                        Website::STATUS_FAILED => 'Failed',
                    ]),

                Tables\Filters\TernaryFilter::make('meets_requirements')
                    ->label('Qualified')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('crawl')
                    ->icon('heroicon-m-arrow-path')
                    ->color('success')
                    ->action(function (Website $record) {
                        CrawlWebsiteJob::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Crawl queued')
                            ->body('Website has been queued for crawling.')
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('crawl')
                        ->label('Crawl Selected')
                        ->icon('heroicon-m-arrow-path')
                        ->color('success')
                        ->action(function ($records) {
                            foreach ($records as $website) {
                                CrawlWebsiteJob::dispatch($website);
                            }

                            Notification::make()
                                ->success()
                                ->title('Bulk crawl queued')
                                ->body("Queued {$records->count()} website(s) for crawling.")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
