<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteResource\Pages;
use App\Filament\Resources\WebsiteResource\RelationManagers;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Domains & Websites';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Website Information')
                    ->schema([
                        Forms\Components\TextInput::make('url')
                            ->label('Website URL')
                            ->url()
                            ->required()
                            ->placeholder('https://mycompany.com')
                            ->helperText('Your company/agency website URL')
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('title')
                            ->label('Website Title')
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Outreach Configuration')
                    ->schema([
                        Forms\Components\Select::make('smtp_credential_id')
                            ->label('SMTP Account')
                            ->relationship('smtpCredential', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('SMTP account used to send emails'),

                        Forms\Components\Select::make('default_email_template_id')
                            ->label('Default Email Template')
                            ->relationship('defaultEmailTemplate', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Default template for outreach emails'),

                        Forms\Components\Select::make('requirements')
                            ->label('Matching Criteria')
                            ->relationship('requirements', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Select criteria to match domains against')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->searchable()
                    ->limit(50)
                    ->copyable()
                    ->url(fn (Website $record): string => $record->url)
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('smtpCredential.name')
                    ->label('SMTP Account')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('defaultEmailTemplate.name')
                    ->label('Email Template')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('requirements_count')
                    ->counts('requirements')
                    ->label('Criteria')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsites::route('/'),
            'create' => Pages\CreateWebsite::route('/create'),
            'view' => Pages\ViewWebsite::route('/{record}'),
            'edit' => Pages\EditWebsite::route('/{record}/edit'),
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
        return static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'primary';
    }
}
