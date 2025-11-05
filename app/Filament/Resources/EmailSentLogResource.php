<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailSentLogResource\Pages;
use App\Filament\Resources\EmailSentLogResource\RelationManagers;
use App\Models\EmailSentLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmailSentLogResource extends Resource
{
    protected static ?string $model = EmailSentLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Email Management';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'subject';

    protected static ?string $navigationLabel = 'Sent Emails';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Email Information')
                    ->schema([
                        Forms\Components\Select::make('website_id')
                            ->relationship('website', 'title')
                            ->label('Website')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Select::make('contact_id')
                            ->relationship('contact', 'email')
                            ->label('Contact')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Select::make('smtp_credential_id')
                            ->relationship('smtpCredential', 'name')
                            ->label('SMTP Credential')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Select::make('email_template_id')
                            ->relationship('emailTemplate', 'name')
                            ->label('Email Template')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(4),

                Forms\Components\Section::make('Recipient Information')
                    ->schema([
                        Forms\Components\TextInput::make('recipient_email')
                            ->label('Recipient Email')
                            ->email()
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('recipient_name')
                            ->label('Recipient Name')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('sent_at')
                            ->label('Sent At')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Email Content')
                    ->schema([
                        Forms\Components\TextInput::make('subject')
                            ->label('Subject')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        Forms\Components\RichEditor::make('body')
                            ->label('Email Body')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Status Information')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                EmailSentLog::STATUS_SENT => 'Sent',
                                EmailSentLog::STATUS_FAILED => 'Failed',
                                EmailSentLog::STATUS_BOUNCED => 'Bounced',
                            ])
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Textarea::make('error_message')
                            ->label('Error Message')
                            ->rows(3)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('website.title')
                    ->label('Website')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->icon('heroicon-m-computer-desktop')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('recipient_email')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\TextColumn::make('recipient_name')
                    ->label('Name')
                    ->searchable()
                    ->icon('heroicon-m-user')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(40)
                    ->weight(FontWeight::Medium)
                    ->tooltip(fn (EmailSentLog $record): string => $record->subject),

                Tables\Columns\TextColumn::make('emailTemplate.name')
                    ->label('Template')
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EmailSentLog::STATUS_SENT => 'success',
                        EmailSentLog::STATUS_FAILED => 'danger',
                        EmailSentLog::STATUS_BOUNCED => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        EmailSentLog::STATUS_SENT => 'Sent',
                        EmailSentLog::STATUS_FAILED => 'Failed',
                        EmailSentLog::STATUS_BOUNCED => 'Bounced',
                        default => 'Unknown',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('smtpCredential.name')
                    ->label('SMTP')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Sent At')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        EmailSentLog::STATUS_SENT => 'Sent',
                        EmailSentLog::STATUS_FAILED => 'Failed',
                        EmailSentLog::STATUS_BOUNCED => 'Bounced',
                    ])
                    ->multiple(),

                Filter::make('sent_today')
                    ->label('Sent Today')
                    ->query(fn (Builder $query): Builder => $query->whereDate('sent_at', today())),

                Filter::make('sent_this_week')
                    ->label('Sent This Week')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('sent_at', [
                        now()->startOfWeek(),
                        now()->endOfWeek(),
                    ])),

                Filter::make('sent_this_month')
                    ->label('Sent This Month')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('sent_at', [
                        now()->startOfMonth(),
                        now()->endOfMonth(),
                    ])),

                Tables\Filters\SelectFilter::make('smtp_credential_id')
                    ->label('SMTP Credential')
                    ->relationship('smtpCredential', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('email_template_id')
                    ->label('Email Template')
                    ->relationship('emailTemplate', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // No bulk actions for read-only resource
            ])
            ->defaultSort('sent_at', 'desc');
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
            'index' => Pages\ListEmailSentLogs::route('/'),
            'view' => Pages\ViewEmailSentLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereDate('sent_at', today())->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }
}
