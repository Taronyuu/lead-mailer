<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailTemplateResource\Pages;
use App\Filament\Resources\EmailTemplateResource\RelationManagers;
use App\Models\EmailTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Email Templates';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Template Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Template Name')
                            ->required()
                            ->placeholder('Welcome Email')
                            ->helperText('A friendly name to identify this template')
                            ->columnSpan(2),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active templates can be used'),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->placeholder('Describe when this template should be used...')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Email Content')
                    ->schema([
                        Forms\Components\TextInput::make('subject_template')
                            ->label('Subject Line')
                            ->required()
                            ->placeholder('Hello {{contact_name}}, check out {{website_title}}')
                            ->helperText('Use {{variable}} syntax for dynamic content')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('preheader')
                            ->label('Preheader Text')
                            ->placeholder('This appears in email preview...')
                            ->helperText('Optional preview text shown in email clients')
                            ->columnSpanFull(),

                        Forms\Components\RichEditor::make('body_template')
                            ->label('Email Body')
                            ->required()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'link',
                                'orderedList',
                                'unorderedList',
                                'h2',
                                'h3',
                            ])
                            ->placeholder('Write your email content here...')
                            ->helperText('Use {{variable}} syntax for dynamic content')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('AI Enhancement')
                    ->schema([
                        Forms\Components\Toggle::make('ai_enabled')
                            ->label('Enable AI Enhancement')
                            ->default(false)
                            ->live()
                            ->helperText('Use AI to personalize and improve email content'),

                        Forms\Components\Textarea::make('ai_instructions')
                            ->label('AI Instructions')
                            ->rows(3)
                            ->placeholder('Personalize the email based on the website content...')
                            ->helperText('Instructions for the AI to follow when generating content')
                            ->visible(fn (Forms\Get $get): bool => $get('ai_enabled'))
                            ->columnSpanFull(),

                        Forms\Components\Select::make('ai_tone')
                            ->label('AI Tone')
                            ->options([
                                EmailTemplate::TONE_PROFESSIONAL => 'Professional',
                                EmailTemplate::TONE_FRIENDLY => 'Friendly',
                                EmailTemplate::TONE_CASUAL => 'Casual',
                                EmailTemplate::TONE_FORMAL => 'Formal',
                            ])
                            ->default(EmailTemplate::TONE_PROFESSIONAL)
                            ->visible(fn (Forms\Get $get): bool => $get('ai_enabled')),

                        Forms\Components\TextInput::make('ai_max_tokens')
                            ->label('Max AI Tokens')
                            ->numeric()
                            ->default(500)
                            ->minValue(100)
                            ->maxValue(2000)
                            ->helperText('Maximum length of AI-generated content')
                            ->visible(fn (Forms\Get $get): bool => $get('ai_enabled')),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Forms\Components\Section::make('Available Variables')
                    ->schema([
                        Forms\Components\Placeholder::make('variables_help')
                            ->label('')
                            ->content(function (): HtmlString {
                                $variables = EmailTemplate::getDefaultVariables();
                                $html = '<div class="space-y-2">';
                                foreach ($variables as $var => $desc) {
                                    $html .= "<div><code class='text-sm bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded'>{$var}</code> - {$desc}</div>";
                                }
                                $html .= '</div>';
                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Usage Statistics')
                    ->schema([
                        Forms\Components\TextInput::make('usage_count')
                            ->label('Times Used')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\DateTimePicker::make('last_used_at')
                            ->label('Last Used')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Template Name')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-m-document-text')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('subject_template')
                    ->label('Subject')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (EmailTemplate $record): string => $record->subject_template)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('ai_enabled')
                    ->label('AI')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ai_tone')
                    ->label('Tone')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'N/A')
                    ->visible(fn (): bool => EmailTemplate::where('ai_enabled', true)->exists())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Usage')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

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
                    ->placeholder('All templates')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\TernaryFilter::make('ai_enabled')
                    ->label('AI Enhancement')
                    ->placeholder('All templates')
                    ->trueLabel('AI enabled')
                    ->falseLabel('AI disabled'),

                Tables\Filters\SelectFilter::make('ai_tone')
                    ->label('AI Tone')
                    ->options([
                        EmailTemplate::TONE_PROFESSIONAL => 'Professional',
                        EmailTemplate::TONE_FRIENDLY => 'Friendly',
                        EmailTemplate::TONE_CASUAL => 'Casual',
                        EmailTemplate::TONE_FORMAL => 'Formal',
                    ])
                    ->multiple(),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->modalHeading('Template Preview')
                    ->modalContent(function (EmailTemplate $record): string {
                        $sampleData = [
                            '{{website_url}}' => 'https://example.com',
                            '{{website_title}}' => 'Example Website',
                            '{{website_description}}' => 'A great example website',
                            '{{contact_name}}' => 'John Doe',
                            '{{contact_email}}' => 'john@example.com',
                            '{{platform}}' => 'WordPress',
                            '{{page_count}}' => '25',
                            '{{domain}}' => 'example.com',
                            '{{sender_name}}' => 'Your Name',
                            '{{sender_company}}' => 'Your Company',
                        ];

                        $subject = str_replace(array_keys($sampleData), array_values($sampleData), $record->subject_template);
                        $body = str_replace(array_keys($sampleData), array_values($sampleData), $record->body_template);

                        return view('filament.resources.email-template.preview', [
                            'subject' => $subject,
                            'preheader' => $record->preheader,
                            'body' => $body,
                            'aiEnabled' => $record->ai_enabled,
                        ])->render();
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Tables\Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (EmailTemplate $record) {
                        $newTemplate = $record->replicate();
                        $newTemplate->name = $record->name . ' (Copy)';
                        $newTemplate->usage_count = 0;
                        $newTemplate->last_used_at = null;
                        $newTemplate->save();

                        Notification::make()
                            ->success()
                            ->title('Template duplicated')
                            ->body("Created a copy: {$newTemplate->name}")
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
                                ->title('Templates activated')
                                ->body("Activated {$records->count()} template(s).")
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
                                ->title('Templates deactivated')
                                ->body("Deactivated {$records->count()} template(s).")
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
            'index' => Pages\ListEmailTemplates::route('/'),
            'create' => Pages\CreateEmailTemplate::route('/create'),
            'view' => Pages\ViewEmailTemplate::route('/{record}'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
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
