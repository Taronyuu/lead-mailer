<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Admin Users';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('User Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Full Name')
                            ->required()
                            ->placeholder('John Doe')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->placeholder('user@example.com')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Password')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(8)
                            ->maxLength(255)
                            ->helperText(fn (string $operation): string =>
                                $operation === 'edit'
                                    ? 'Leave blank to keep current password'
                                    : 'Minimum 8 characters'
                            )
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirm Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(false)
                            ->maxLength(255)
                            ->same('password')
                            ->visible(fn (Forms\Get $get): bool => filled($get('password'))),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Email Verification')
                    ->schema([
                        Forms\Components\Placeholder::make('email_verification_status')
                            ->label('Verification Status')
                            ->content(function (User $record): string {
                                if (!$record->exists) {
                                    return 'User will need to verify email after creation';
                                }
                                return $record->email_verified_at
                                    ? 'Verified on ' . $record->email_verified_at->format('M d, Y H:i:s')
                                    : 'Not verified';
                            }),

                        Forms\Components\Toggle::make('mark_as_verified')
                            ->label('Mark Email as Verified')
                            ->default(false)
                            ->helperText('Manually verify the user\'s email address')
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Forms\Set $set, bool $state) {
                                if ($state) {
                                    $set('email_verified_at', now());
                                }
                            })
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        Forms\Components\DateTimePicker::make('email_verified_at')
                            ->label('Email Verified At')
                            ->disabled()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->visible(fn (string $operation): bool => $operation === 'create'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Forms\Components\Section::make('Activity Information')
                    ->schema([
                        Forms\Components\Placeholder::make('created_at')
                            ->label('Created')
                            ->content(fn (User $record): string =>
                                $record->exists ? $record->created_at->format('M d, Y H:i:s') : 'Not yet created'
                            ),

                        Forms\Components\Placeholder::make('updated_at')
                            ->label('Last Updated')
                            ->content(fn (User $record): string =>
                                $record->exists ? $record->updated_at->format('M d, Y H:i:s') : 'Not yet created'
                            ),

                        Forms\Components\Placeholder::make('blacklist_entries_count')
                            ->label('Blacklist Entries Added')
                            ->content(fn (User $record): string =>
                                $record->exists ? $record->blacklistEntries()->count() . ' entries' : '0 entries'
                            ),
                    ])
                    ->columns(3)
                    ->visible(fn (string $operation): bool => $operation === 'view' || $operation === 'edit')
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-user')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->email_verified_at !== null)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('blacklistEntries_count')
                    ->counts('blacklistEntries')
                    ->label('Blacklist Entries')
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Email Verification')
                    ->placeholder('All users')
                    ->trueLabel('Verified only')
                    ->falseLabel('Unverified only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('email_verified_at'),
                    ),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Created from'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Created until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('verify_email')
                    ->label('Verify Email')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->action(function (User $record) {
                        $record->update(['email_verified_at' => now()]);

                        Notification::make()
                            ->success()
                            ->title('Email verified')
                            ->body("Email for {$record->name} has been marked as verified.")
                            ->send();
                    }),

                Tables\Actions\Action::make('reset_password')
                    ->label('Reset Password')
                    ->icon('heroicon-m-key')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('New Password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->maxLength(255)
                            ->helperText('Minimum 8 characters'),

                        Forms\Components\TextInput::make('new_password_confirmation')
                            ->label('Confirm Password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(255)
                            ->same('new_password'),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update([
                            'password' => Hash::make($data['new_password']),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Password reset')
                            ->body("Password for {$record->name} has been reset successfully.")
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => $record->id !== Auth::id())
                    ->requiresConfirmation()
                    ->modalDescription('Are you sure you want to delete this user? This action cannot be undone.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('verify_emails')
                        ->label('Verify Emails')
                        ->icon('heroicon-m-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->email_verified_at === null) {
                                    $record->update(['email_verified_at' => now()]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Emails verified')
                                ->body("Verified {$count} user email(s).")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            $currentUserId = Auth::id();
                            if ($records->contains('id', $currentUserId)) {
                                Notification::make()
                                    ->danger()
                                    ->title('Cannot delete your own account')
                                    ->body('You cannot delete your own user account.')
                                    ->send();

                                return false;
                            }
                        }),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'primary';
    }
}
