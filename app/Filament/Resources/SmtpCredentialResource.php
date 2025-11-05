<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SmtpCredentialResource\Pages;
use App\Filament\Resources\SmtpCredentialResource\RelationManagers;
use App\Models\SmtpCredential;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Mail;

class SmtpCredentialResource extends Resource
{
    protected static ?string $model = SmtpCredential::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'SMTP Accounts';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Account Name')
                            ->required()
                            ->placeholder('Primary Gmail Account')
                            ->helperText('A friendly name to identify this SMTP account')
                            ->columnSpan(2),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active accounts will be used for sending'),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('SMTP Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('host')
                            ->label('SMTP Host')
                            ->required()
                            ->placeholder('smtp.gmail.com')
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('port')
                            ->label('Port')
                            ->required()
                            ->numeric()
                            ->default(587)
                            ->minValue(1)
                            ->maxValue(65535),

                        Forms\Components\Select::make('encryption')
                            ->label('Encryption')
                            ->required()
                            ->options([
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                                'none' => 'None',
                            ])
                            ->default('tls'),

                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->required()
                            ->placeholder('user@example.com')
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank to keep current password when editing')
                            ->columnSpan(2),
                    ])
                    ->columns(4),

                Forms\Components\Section::make('Sender Information')
                    ->schema([
                        Forms\Components\TextInput::make('from_address')
                            ->label('From Email')
                            ->required()
                            ->email()
                            ->placeholder('noreply@example.com'),

                        Forms\Components\TextInput::make('from_name')
                            ->label('From Name')
                            ->required()
                            ->placeholder('Company Name'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Usage Limits & Statistics')
                    ->schema([
                        Forms\Components\TextInput::make('daily_limit')
                            ->label('Daily Limit')
                            ->required()
                            ->numeric()
                            ->default(100)
                            ->minValue(1)
                            ->helperText('Maximum emails per day'),

                        Forms\Components\TextInput::make('emails_sent_today')
                            ->label('Sent Today')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('success_count')
                            ->label('Total Successful')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('failure_count')
                            ->label('Total Failed')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('last_used_at')
                            ->label('Last Used')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DatePicker::make('last_reset_date')
                            ->label('Last Reset')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(3)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Account Name')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-server')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('host')
                    ->label('SMTP Host')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('capacity')
                    ->label('Capacity')
                    ->formatStateUsing(fn (SmtpCredential $record): string =>
                        "{$record->emails_sent_today} / {$record->daily_limit}"
                    )
                    ->badge()
                    ->color(fn (SmtpCredential $record): string =>
                        $record->isAvailable() ? 'success' : 'danger'
                    )
                    ->sortable(['emails_sent_today']),

                Tables\Columns\TextColumn::make('health_status')
                    ->label('Health')
                    ->formatStateUsing(function (SmtpCredential $record): string {
                        $total = $record->success_count + $record->failure_count;
                        if ($total === 0) return 'N/A';
                        $rate = round(($record->success_count / $total) * 100, 1);
                        return "{$rate}%";
                    })
                    ->badge()
                    ->color(function (SmtpCredential $record): string {
                        $total = $record->success_count + $record->failure_count;
                        if ($total === 0) return 'gray';
                        $rate = ($record->success_count / $total) * 100;
                        return match (true) {
                            $rate >= 95 => 'success',
                            $rate >= 80 => 'warning',
                            default => 'danger',
                        };
                    })
                    ->sortable(['success_count']),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All accounts')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\Filter::make('has_capacity')
                    ->label('Has Capacity')
                    ->query(fn (Builder $query): Builder =>
                        $query->whereColumn('emails_sent_today', '<', 'daily_limit')
                    )
                    ->toggle(),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('test_connection')
                    ->label('Test')
                    ->icon('heroicon-m-signal')
                    ->color('info')
                    ->action(function (SmtpCredential $record) {
                        try {
                            // Configure temporary mailer
                            config([
                                'mail.mailers.test_smtp' => [
                                    'transport' => 'smtp',
                                    'host' => $record->host,
                                    'port' => $record->port,
                                    'encryption' => $record->encryption,
                                    'username' => $record->username,
                                    'password' => $record->password,
                                ],
                            ]);

                            // Test connection
                            $transport = Mail::mailer('test_smtp')->getSymfonyTransport();
                            $transport->start();

                            Notification::make()
                                ->success()
                                ->title('Connection successful')
                                ->body("Successfully connected to {$record->host}")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Connection failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reset_daily_count')
                    ->label('Reset Count')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (SmtpCredential $record) {
                        $record->update([
                            'emails_sent_today' => 0,
                            'last_reset_date' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Daily count reset')
                            ->body('The daily email count has been reset to 0.')
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each->update(['is_active' => true]);

                            Notification::make()
                                ->success()
                                ->title('Accounts activated')
                                ->body("Activated {$records->count()} account(s).")
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-m-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $records->each->update(['is_active' => false]);

                            Notification::make()
                                ->success()
                                ->title('Accounts deactivated')
                                ->body("Deactivated {$records->count()} account(s).")
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
            'index' => Pages\ListSmtpCredentials::route('/'),
            'create' => Pages\CreateSmtpCredential::route('/create'),
            'view' => Pages\ViewSmtpCredential::route('/{record}'),
            'edit' => Pages\EditSmtpCredential::route('/{record}/edit'),
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
        return static::getModel()::where('is_active', true)->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }
}
