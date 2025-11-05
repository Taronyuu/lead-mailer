<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Jobs\SendOutreachEmailJob;
use App\Jobs\ValidateContactEmailJob;
use App\Models\Contact;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $recordTitleAttribute = 'email';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('name')
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),

                Forms\Components\TextInput::make('position')
                    ->maxLength(255),

                Forms\Components\Select::make('source_type')
                    ->options([
                        Contact::SOURCE_CONTACT_PAGE => 'Contact Page',
                        Contact::SOURCE_ABOUT_PAGE => 'About Page',
                        Contact::SOURCE_FOOTER => 'Footer',
                        Contact::SOURCE_HEADER => 'Header',
                        Contact::SOURCE_BODY => 'Body',
                        Contact::SOURCE_TEAM_PAGE => 'Team Page',
                    ]),

                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->default(50)
                    ->minValue(0)
                    ->maxValue(100),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('position')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Contact::SOURCE_CONTACT_PAGE => 'Contact',
                        Contact::SOURCE_ABOUT_PAGE => 'About',
                        Contact::SOURCE_FOOTER => 'Footer',
                        Contact::SOURCE_HEADER => 'Header',
                        Contact::SOURCE_BODY => 'Body',
                        Contact::SOURCE_TEAM_PAGE => 'Team',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 80 => 'success',
                        $state >= 50 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_validated')
                    ->label('Validated')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_valid')
                    ->label('Valid')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('contacted')
                    ->label('Contacted')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('contact_count')
                    ->label('Contacts')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('validated_at')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_validated')
                    ->label('Validated')
                    ->placeholder('All contacts')
                    ->trueLabel('Validated')
                    ->falseLabel('Not Validated'),

                Tables\Filters\TernaryFilter::make('contacted')
                    ->label('Contacted')
                    ->placeholder('All contacts')
                    ->trueLabel('Contacted')
                    ->falseLabel('Not Contacted'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('validate')
                    ->label('Validate')
                    ->icon('heroicon-m-check-badge')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Contact $record) {
                        ValidateContactEmailJob::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Validation queued')
                            ->body('Email validation has been queued.')
                            ->send();
                    })
                    ->visible(fn (Contact $record) => !$record->is_validated),

                Tables\Actions\Action::make('send_outreach')
                    ->label('Send Email')
                    ->icon('heroicon-m-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Contact $record) {
                        SendOutreachEmailJob::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Email queued')
                            ->body('Outreach email has been queued for sending.')
                            ->send();
                    })
                    ->visible(fn (Contact $record) => $record->is_validated && $record->is_valid),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('validate_all')
                        ->label('Validate Selected')
                        ->icon('heroicon-m-check-badge')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $contact) {
                                if (!$contact->is_validated) {
                                    ValidateContactEmailJob::dispatch($contact);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Validation queued')
                                ->body("Queued {$count} contact(s) for validation.")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
