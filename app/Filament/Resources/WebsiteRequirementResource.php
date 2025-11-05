<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteRequirementResource\Pages;
use App\Filament\Resources\WebsiteRequirementResource\RelationManagers;
use App\Models\WebsiteRequirement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WebsiteRequirementResource extends Resource
{
    protected static ?string $model = WebsiteRequirement::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Match Criteria';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Requirement Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Requirement Name')
                            ->required()
                            ->placeholder('High-Value Websites')
                            ->helperText('A descriptive name for this matching rule')
                            ->columnSpan(2),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active requirements are checked'),

                        Forms\Components\TextInput::make('priority')
                            ->label('Priority')
                            ->required()
                            ->numeric()
                            ->default(50)
                            ->minValue(1)
                            ->maxValue(100)
                            ->helperText('Higher priority (1-100) = checked first')
                            ->columnSpan(3),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->placeholder('Describe what this requirement checks for...')
                            ->helperText('Explain the purpose and logic of this requirement')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Matching Criteria')
                    ->description('Define the conditions that websites must meet. Use key-value pairs where the key is the field to check and the value is the expected value or condition.')
                    ->schema([
                        Forms\Components\KeyValue::make('criteria')
                            ->label('Criteria Rules')
                            ->required()
                            ->keyLabel('Field Name')
                            ->valueLabel('Expected Value / Condition')
                            ->addButtonLabel('Add Criteria')
                            ->reorderable()
                            ->helperText('Examples: platform => WordPress, min_pages => 10, has_contact_form => true')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Available Fields & Examples')
                    ->schema([
                        Forms\Components\Placeholder::make('criteria_help')
                            ->label('')
                            ->content(new \Illuminate\Support\HtmlString('<div class="space-y-3">
                                    <div class="font-semibold">Available Criteria Fields:</div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">platforms</code> - Array: ["WordPress", "Shopify"]</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">min_pages</code> - Integer: 30</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">max_pages</code> - Integer: 100</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">min_word_count</code> - Integer: 500</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">max_word_count</code> - Integer: 10000</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">required_keywords</code> - Array: ["shop", "store"]</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">excluded_keywords</code> - Array: ["adult", "casino"]</div>
                                        <div><code class="text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">required_urls</code> - Array: ["/contact", "/about"]</div>
                                    </div>
                                    <div class="mt-3 font-semibold">Example Criteria:</div>
                                    <div class="space-y-1 text-sm">
                                        <div><strong>platforms:</strong> ["WordPress"] - Must be WordPress</div>
                                        <div><strong>min_pages:</strong> 30 - Must have at least 30 pages</div>
                                        <div><strong>max_pages:</strong> 1000 - Cannot exceed 1000 pages</div>
                                    </div>
                                </div>'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Requirement Name')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-funnel')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 80 => 'danger',
                        $state >= 50 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('criteria_count')
                    ->label('Criteria')
                    ->formatStateUsing(fn (WebsiteRequirement $record): string =>
                        is_array($record->criteria) ? count($record->criteria) . ' rules' : '0 rules'
                    )
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->tooltip(fn (WebsiteRequirement $record): ?string => $record->description)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All requirements')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\SelectFilter::make('priority')
                    ->label('Priority Level')
                    ->options([
                        'high' => 'High (80-100)',
                        'medium' => 'Medium (50-79)',
                        'low' => 'Low (1-49)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] === 'high',
                            fn (Builder $query) => $query->where('priority', '>=', 80)
                        )->when(
                            $data['value'] === 'medium',
                            fn (Builder $query) => $query->whereBetween('priority', [50, 79])
                        )->when(
                            $data['value'] === 'low',
                            fn (Builder $query) => $query->where('priority', '<', 50)
                        );
                    }),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('test_criteria')
                    ->label('Test')
                    ->icon('heroicon-m-beaker')
                    ->color('info')
                    ->form([
                        Forms\Components\Textarea::make('sample_data')
                            ->label('Sample Website Data (JSON)')
                            ->required()
                            ->rows(10)
                            ->placeholder('{"platform": "WordPress", "page_count": 25, "has_contact_form": true}')
                            ->helperText('Enter sample website data as JSON to test if it matches this requirement'),
                    ])
                    ->action(function (WebsiteRequirement $record, array $data) {
                        try {
                            $sampleData = json_decode($data['sample_data'], true);

                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new \Exception('Invalid JSON format');
                            }

                            $matches = true;
                            $results = [];

                            foreach ($record->criteria as $field => $expected) {
                                $actual = $sampleData[$field] ?? null;
                                $fieldMatches = false;

                                // Simple matching logic (can be enhanced)
                                if (str_starts_with((string)$expected, '>')) {
                                    $fieldMatches = $actual > (float)substr($expected, 1);
                                } elseif (str_starts_with((string)$expected, '<')) {
                                    $fieldMatches = $actual < (float)substr($expected, 1);
                                } elseif ($expected === 'true' || $expected === true) {
                                    $fieldMatches = (bool)$actual === true;
                                } elseif ($expected === 'false' || $expected === false) {
                                    $fieldMatches = (bool)$actual === false;
                                } else {
                                    $fieldMatches = $actual == $expected;
                                }

                                $results[] = "{$field}: " . ($fieldMatches ? '✓ Match' : '✗ No Match') . " (Expected: {$expected}, Got: " . json_encode($actual) . ")";

                                if (!$fieldMatches) {
                                    $matches = false;
                                }
                            }

                            $notification = Notification::make()
                                ->title($matches ? 'Criteria Matched!' : 'Criteria Not Matched')
                                ->body(implode("\n", $results));

                            if ($matches) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Test Failed')
                                ->body($e->getMessage())
                                ->send();
                        }
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
                                ->title('Requirements activated')
                                ->body("Activated {$records->count()} requirement(s).")
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
                                ->title('Requirements deactivated')
                                ->body("Deactivated {$records->count()} requirement(s).")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('priority', 'desc');
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
            'index' => Pages\ListWebsiteRequirements::route('/'),
            'create' => Pages\CreateWebsiteRequirement::route('/create'),
            'view' => Pages\ViewWebsiteRequirement::route('/{record}'),
            'edit' => Pages\EditWebsiteRequirement::route('/{record}/edit'),
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
