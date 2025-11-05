<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactResource\Pages;
use App\Filament\Resources\ContactResource\RelationManagers;
use App\Jobs\SendOutreachEmailJob;
use App\Jobs\ValidateContactEmailJob;
use App\Models\Contact;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Contacts & Outreach';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Contact Information')
                    ->schema([
                        Forms\Components\Select::make('domain_id')
                            ->relationship('domain', 'domain')
                            ->label('Domain')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('name')
                            ->label('Full Name')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('position')
                            ->label('Job Position')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Source Information')
                    ->schema([
                        Forms\Components\Select::make('source_type')
                            ->label('Source Type')
                            ->options([
                                Contact::SOURCE_CONTACT_PAGE => 'Contact Page',
                                Contact::SOURCE_ABOUT_PAGE => 'About Page',
                                Contact::SOURCE_FOOTER => 'Footer',
                                Contact::SOURCE_HEADER => 'Header',
                                Contact::SOURCE_BODY => 'Body',
                                Contact::SOURCE_TEAM_PAGE => 'Team Page',
                            ])
                            ->helperText('Where this contact was found'),

                        Forms\Components\TextInput::make('source_url')
                            ->label('Source URL')
                            ->url()
                            ->maxLength(500),

                        Forms\Components\TextInput::make('priority')
                            ->label('Priority Score')
                            ->numeric()
                            ->required()
                            ->default(50)
                            ->minValue(0)
                            ->maxValue(100)
                            ->helperText('0-100, higher is more important'),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Forms\Components\Section::make('Validation Status')
                    ->schema([
                        Forms\Components\Toggle::make('is_validated')
                            ->label('Is Validated')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Toggle::make('is_valid')
                            ->label('Is Valid')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('validated_at')
                            ->label('Validated At')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Textarea::make('validation_error')
                            ->label('Validation Error')
                            ->rows(2)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Forms\Components\Section::make('Contact History')
                    ->schema([
                        Forms\Components\Toggle::make('contacted')
                            ->label('Has Been Contacted')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('contact_count')
                            ->label('Contact Count')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('first_contacted_at')
                            ->label('First Contacted')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('last_contacted_at')
                            ->label('Last Contacted')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(4)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain.domain')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->icon('heroicon-m-globe-alt'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('position')
                    ->label('Position')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Contact::SOURCE_CONTACT_PAGE => 'Contact',
                        Contact::SOURCE_ABOUT_PAGE => 'About',
                        Contact::SOURCE_FOOTER => 'Footer',
                        Contact::SOURCE_HEADER => 'Header',
                        Contact::SOURCE_BODY => 'Body',
                        Contact::SOURCE_TEAM_PAGE => 'Team',
                        default => $state ?? 'Unknown',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
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
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('validated_at')
                    ->label('Validated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('first_contacted_at')
                    ->label('First Contact')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_validated')
                    ->label('Validation Status')
                    ->placeholder('All contacts')
                    ->trueLabel('Validated & Valid')
                    ->falseLabel('Not Validated')
                    ->queries(
                        true: fn (Builder $query) => $query->where('is_validated', true)->where('is_valid', true),
                        false: fn (Builder $query) => $query->where('is_validated', false),
                    ),

                Tables\Filters\TernaryFilter::make('contacted')
                    ->label('Contact Status')
                    ->placeholder('All contacts')
                    ->trueLabel('Contacted')
                    ->falseLabel('Not Contacted'),

                Tables\Filters\SelectFilter::make('priority')
                    ->label('Priority Level')
                    ->options([
                        'high' => 'High (80+)',
                        'medium' => 'Medium (50-79)',
                        'low' => 'Low (<50)',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'high' => $query->where('priority', '>=', 80),
                            'medium' => $query->whereBetween('priority', [50, 79]),
                            'low' => $query->where('priority', '<', 50),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('source_type')
                    ->label('Source Type')
                    ->options([
                        Contact::SOURCE_CONTACT_PAGE => 'Contact Page',
                        Contact::SOURCE_ABOUT_PAGE => 'About Page',
                        Contact::SOURCE_FOOTER => 'Footer',
                        Contact::SOURCE_HEADER => 'Header',
                        Contact::SOURCE_BODY => 'Body',
                        Contact::SOURCE_TEAM_PAGE => 'Team Page',
                    ])
                    ->multiple(),

                Tables\Filters\TrashedFilter::make(),
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
                            ->body('Email validation has been queued for processing.')
                            ->send();
                    })
                    ->visible(fn (Contact $record) => !$record->is_validated),

                Tables\Actions\Action::make('send_outreach')
                    ->label('Send Outreach')
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

                Tables\Actions\ViewAction::make(),
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
                                ->title('Bulk validation queued')
                                ->body("Queued {$count} contact(s) for validation.")
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('send_outreach_all')
                        ->label('Send Outreach to Selected')
                        ->icon('heroicon-m-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $contact) {
                                if ($contact->is_validated && $contact->is_valid) {
                                    SendOutreachEmailJob::dispatch($contact);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Bulk outreach queued')
                                ->body("Queued {$count} contact(s) for outreach.")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContacts::route('/'),
            'create' => Pages\CreateContact::route('/create'),
            'view' => Pages\ViewContact::route('/{record}'),
            'edit' => Pages\EditContact::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('is_validated', true)
            ->where('is_valid', true)
            ->where('contacted', false)
            ->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }
}
