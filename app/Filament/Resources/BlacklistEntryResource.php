<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlacklistEntryResource\Pages;
use App\Filament\Resources\BlacklistEntryResource\RelationManagers;
use App\Models\BlacklistEntry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class BlacklistEntryResource extends Resource
{
    protected static ?string $model = BlacklistEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Blacklist';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'value';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Blacklist Entry')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->required()
                            ->options([
                                BlacklistEntry::TYPE_EMAIL => 'Email Address',
                                BlacklistEntry::TYPE_DOMAIN => 'Domain',
                            ])
                            ->default(BlacklistEntry::TYPE_EMAIL)
                            ->live()
                            ->helperText('Choose whether to block an email or an entire domain'),

                        Forms\Components\TextInput::make('value')
                            ->label('Value')
                            ->required()
                            ->placeholder(fn (Forms\Get $get): string =>
                                $get('type') === BlacklistEntry::TYPE_EMAIL
                                    ? 'spam@example.com'
                                    : 'spammer.com'
                            )
                            ->helperText(fn (Forms\Get $get): string =>
                                $get('type') === BlacklistEntry::TYPE_EMAIL
                                    ? 'Enter the email address to block'
                                    : 'Enter the domain to block (without http:// or www.)'
                            )
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('reason')
                            ->label('Reason')
                            ->rows(3)
                            ->placeholder('Why is this entry being blacklisted?')
                            ->helperText('Optional: Explain why this entry is blocked')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('source')
                            ->label('Source')
                            ->required()
                            ->options([
                                BlacklistEntry::SOURCE_MANUAL => 'Manual Entry',
                                BlacklistEntry::SOURCE_IMPORT => 'Imported',
                                BlacklistEntry::SOURCE_AUTO => 'Auto-detected',
                            ])
                            ->default(BlacklistEntry::SOURCE_MANUAL)
                            ->helperText('How this entry was added to the blacklist'),

                        Forms\Components\Hidden::make('added_by_user_id')
                            ->default(fn (): ?int => Auth::id())
                            ->dehydrated(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Added By')
                    ->schema([
                        Forms\Components\Placeholder::make('added_by')
                            ->label('User')
                            ->content(function (BlacklistEntry $record): string {
                                if (!$record->exists || !$record->addedBy) {
                                    return 'Current user (' . Auth::user()?->name . ')';
                                }
                                return $record->addedBy->name . ' (' . $record->addedBy->email . ')';
                            }),

                        Forms\Components\Placeholder::make('created_at')
                            ->label('Added At')
                            ->content(fn (BlacklistEntry $record): string =>
                                $record->exists ? $record->created_at->format('M d, Y H:i:s') : 'Not yet created'
                            ),
                    ])
                    ->columns(2)
                    ->visible(fn (string $operation): bool => $operation === 'view' || $operation === 'edit')
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        BlacklistEntry::TYPE_EMAIL => 'danger',
                        BlacklistEntry::TYPE_DOMAIN => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BlacklistEntry::TYPE_EMAIL => 'Email',
                        BlacklistEntry::TYPE_DOMAIN => 'Domain',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('value')
                    ->label('Blocked Value')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon(fn (BlacklistEntry $record): string =>
                        $record->type === BlacklistEntry::TYPE_EMAIL
                            ? 'heroicon-m-envelope'
                            : 'heroicon-m-globe-alt'
                    )
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(50)
                    ->tooltip(fn (BlacklistEntry $record): ?string => $record->reason)
                    ->toggleable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        BlacklistEntry::SOURCE_MANUAL => 'info',
                        BlacklistEntry::SOURCE_IMPORT => 'success',
                        BlacklistEntry::SOURCE_AUTO => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BlacklistEntry::SOURCE_MANUAL => 'Manual',
                        BlacklistEntry::SOURCE_IMPORT => 'Import',
                        BlacklistEntry::SOURCE_AUTO => 'Auto',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('addedBy.name')
                    ->label('Added By')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        BlacklistEntry::TYPE_EMAIL => 'Email',
                        BlacklistEntry::TYPE_DOMAIN => 'Domain',
                    ])
                    ->multiple(),

                SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        BlacklistEntry::SOURCE_MANUAL => 'Manual',
                        BlacklistEntry::SOURCE_IMPORT => 'Import',
                        BlacklistEntry::SOURCE_AUTO => 'Auto',
                    ])
                    ->multiple(),

                SelectFilter::make('added_by_user_id')
                    ->label('Added By')
                    ->relationship('addedBy', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('export')
                        ->label('Export')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('info')
                        ->action(function (Collection $records) {
                            $csv = "Type,Value,Reason,Source,Added By,Created At\n";

                            foreach ($records as $record) {
                                $csv .= sprintf(
                                    "%s,%s,%s,%s,%s,%s\n",
                                    $record->type,
                                    $record->value,
                                    str_replace(["\r", "\n", ','], [' ', ' ', ';'], $record->reason ?? ''),
                                    $record->source,
                                    $record->addedBy?->name ?? 'Unknown',
                                    $record->created_at->format('Y-m-d H:i:s')
                                );
                            }

                            $filename = 'blacklist_export_' . now()->format('Y-m-d_His') . '.csv';

                            Notification::make()
                                ->success()
                                ->title('Export ready')
                                ->body("Exported {$records->count()} entries. Download starting...")
                                ->send();

                            return response()->streamDownload(
                                fn () => print($csv),
                                $filename,
                                ['Content-Type' => 'text/csv']
                            );
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import')
                    ->label('Import')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('success')
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('Entry Type')
                            ->required()
                            ->options([
                                BlacklistEntry::TYPE_EMAIL => 'Email Addresses',
                                BlacklistEntry::TYPE_DOMAIN => 'Domains',
                            ])
                            ->default(BlacklistEntry::TYPE_EMAIL),

                        Forms\Components\Textarea::make('entries')
                            ->label('Entries')
                            ->required()
                            ->rows(10)
                            ->placeholder("spam@example.com\nbadactor@test.com\nanother@blocked.com")
                            ->helperText('Enter one entry per line'),

                        Forms\Components\Textarea::make('reason')
                            ->label('Reason (Optional)')
                            ->rows(3)
                            ->placeholder('Imported from external blacklist'),
                    ])
                    ->action(function (array $data) {
                        $lines = array_filter(
                            array_map('trim', explode("\n", $data['entries'])),
                            fn ($line) => !empty($line)
                        );

                        $imported = 0;
                        $skipped = 0;

                        foreach ($lines as $value) {
                            $existing = BlacklistEntry::where('type', $data['type'])
                                ->where('value', $value)
                                ->exists();

                            if ($existing) {
                                $skipped++;
                                continue;
                            }

                            BlacklistEntry::create([
                                'type' => $data['type'],
                                'value' => $value,
                                'reason' => $data['reason'] ?? 'Bulk import',
                                'source' => BlacklistEntry::SOURCE_IMPORT,
                                'added_by_user_id' => Auth::id(),
                            ]);

                            $imported++;
                        }

                        Notification::make()
                            ->success()
                            ->title('Import completed')
                            ->body("Imported {$imported} entries. Skipped {$skipped} duplicates.")
                            ->send();
                    }),
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
            'index' => Pages\ListBlacklistEntries::route('/'),
            'create' => Pages\CreateBlacklistEntry::route('/create'),
            'view' => Pages\ViewBlacklistEntry::route('/{record}'),
            'edit' => Pages\EditBlacklistEntry::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $count = static::getModel()::count();
        return match (true) {
            $count === 0 => 'success',
            $count < 100 => 'warning',
            default => 'danger',
        };
    }
}
