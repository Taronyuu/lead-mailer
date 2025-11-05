<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailReviewQueueResource\Pages;
use App\Filament\Resources\EmailReviewQueueResource\RelationManagers;
use App\Jobs\SendEmailReviewQueueJob;
use App\Models\EmailReviewQueue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class EmailReviewQueueResource extends Resource
{
    protected static ?string $model = EmailReviewQueue::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'Email Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'generated_subject';

    protected static ?string $navigationLabel = 'Review Queue';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Recipient Information')
                    ->schema([
                        Forms\Components\Select::make('website_id')
                            ->relationship('website', 'url')
                            ->label('Website')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('contact_id')
                            ->relationship('contact', 'email')
                            ->label('Contact')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('email_template_id')
                            ->relationship('emailTemplate', 'name')
                            ->label('Email Template')
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Email Content')
                    ->schema([
                        Forms\Components\TextInput::make('generated_subject')
                            ->label('Subject Line')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('generated_preheader')
                            ->label('Preheader Text')
                            ->rows(2)
                            ->maxLength(255)
                            ->helperText('Optional preview text that appears in email clients')
                            ->columnSpanFull(),

                        Forms\Components\RichEditor::make('generated_body')
                            ->label('Email Body')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Review Information')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                EmailReviewQueue::STATUS_PENDING => 'Pending',
                                EmailReviewQueue::STATUS_APPROVED => 'Approved',
                                EmailReviewQueue::STATUS_REJECTED => 'Rejected',
                            ])
                            ->required()
                            ->default(EmailReviewQueue::STATUS_PENDING),

                        Forms\Components\TextInput::make('priority')
                            ->label('Priority')
                            ->numeric()
                            ->required()
                            ->default(50)
                            ->minValue(0)
                            ->maxValue(100),

                        Forms\Components\DateTimePicker::make('reviewed_at')
                            ->label('Reviewed At')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\Textarea::make('review_notes')
                            ->label('Review Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
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
                    ->icon('heroicon-m-computer-desktop'),

                Tables\Columns\TextColumn::make('contact.email')
                    ->label('Contact')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\TextColumn::make('generated_subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(40)
                    ->weight(FontWeight::Medium)
                    ->tooltip(fn (EmailReviewQueue $record): string => $record->generated_subject),

                Tables\Columns\TextColumn::make('emailTemplate.name')
                    ->label('Template')
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EmailReviewQueue::STATUS_PENDING => 'warning',
                        EmailReviewQueue::STATUS_APPROVED => 'success',
                        EmailReviewQueue::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        EmailReviewQueue::STATUS_PENDING => 'Pending',
                        EmailReviewQueue::STATUS_APPROVED => 'Approved',
                        EmailReviewQueue::STATUS_REJECTED => 'Rejected',
                        default => 'Unknown',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 70 => 'danger',
                        $state >= 50 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('reviewedBy.name')
                    ->label('Reviewed By')
                    ->icon('heroicon-m-user')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reviewed_at')
                    ->label('Reviewed')
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
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        EmailReviewQueue::STATUS_PENDING => 'Pending',
                        EmailReviewQueue::STATUS_APPROVED => 'Approved',
                        EmailReviewQueue::STATUS_REJECTED => 'Rejected',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('priority')
                    ->label('Priority Level')
                    ->options([
                        'high' => 'High (70+)',
                        'medium' => 'Medium (50-69)',
                        'low' => 'Low (<50)',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'high' => $query->where('priority', '>=', 70),
                            'medium' => $query->whereBetween('priority', [50, 69]),
                            'low' => $query->where('priority', '<', 50),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve & Send')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve & Send Email')
                    ->modalDescription('This email will be approved and sent immediately.')
                    ->modalSubmitActionLabel('Approve & Send')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')
                            ->label('Review Notes (Optional)')
                            ->rows(3),
                    ])
                    ->action(function (EmailReviewQueue $record, array $data) {
                        $record->approve(Auth::id(), $data['review_notes'] ?? null);
                        SendEmailReviewQueueJob::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Email approved and sent')
                            ->body('Email has been approved and is being sent.')
                            ->send();
                    })
                    ->visible(fn (EmailReviewQueue $record) => $record->status === EmailReviewQueue::STATUS_PENDING),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Email')
                    ->modalDescription('This email will be rejected and will not be sent.')
                    ->modalSubmitActionLabel('Reject')
                    ->form([
                        Forms\Components\Textarea::make('review_notes')
                            ->label('Rejection Reason')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (EmailReviewQueue $record, array $data) {
                        $record->reject(Auth::id(), $data['review_notes']);

                        Notification::make()
                            ->success()
                            ->title('Email rejected')
                            ->body('Email has been rejected.')
                            ->send();
                    })
                    ->visible(fn (EmailReviewQueue $record) => $record->status === EmailReviewQueue::STATUS_PENDING),

                Tables\Actions\Action::make('send')
                    ->label('Send Now')
                    ->icon('heroicon-m-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (EmailReviewQueue $record) {
                        SendEmailReviewQueueJob::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Email queued')
                            ->body('Email has been queued for sending.')
                            ->send();
                    })
                    ->visible(fn (EmailReviewQueue $record) => $record->status === EmailReviewQueue::STATUS_APPROVED),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (EmailReviewQueue $record) => $record->status === EmailReviewQueue::STATUS_PENDING),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve_all')
                        ->label('Approve & Send Selected')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve & Send Selected Emails')
                        ->modalDescription('All selected pending emails will be approved and sent immediately.')
                        ->modalSubmitActionLabel('Approve & Send All')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $email) {
                                if ($email->status === EmailReviewQueue::STATUS_PENDING) {
                                    $email->approve(Auth::id());
                                    SendEmailReviewQueueJob::dispatch($email);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Bulk approval completed')
                                ->body("Approved and sent {$count} email(s).")
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('reject_all')
                        ->label('Reject Selected')
                        ->icon('heroicon-m-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Reject Selected Emails')
                        ->modalDescription('All selected pending emails will be marked as rejected.')
                        ->modalSubmitActionLabel('Reject All')
                        ->form([
                            Forms\Components\Textarea::make('review_notes')
                                ->label('Rejection Reason')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function ($records, array $data) {
                            $count = 0;
                            foreach ($records as $email) {
                                if ($email->status === EmailReviewQueue::STATUS_PENDING) {
                                    $email->reject(Auth::id(), $data['review_notes']);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Bulk rejection completed')
                                ->body("Rejected {$count} email(s).")
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('send_all')
                        ->label('Send Approved')
                        ->icon('heroicon-m-paper-airplane')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $email) {
                                if ($email->status === EmailReviewQueue::STATUS_APPROVED) {
                                    SendEmailReviewQueueJob::dispatch($email);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Bulk send queued')
                                ->body("Queued {$count} email(s) for sending.")
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListEmailReviewQueues::route('/'),
            'view' => Pages\ViewEmailReviewQueue::route('/{record}'),
            'edit' => Pages\EditEmailReviewQueue::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', EmailReviewQueue::STATUS_PENDING)->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }
}
