# Step 10 Implementation Plan: Filament Admin Panel & Dashboard

## Executive Summary

Step 10 implements a comprehensive admin panel using **Filament 3.x** with Tailwind CSS, providing a beautiful, feature-rich interface for managing all system operations.

**Key Objectives:**
- Install and configure Filament 3.x
- Create Filament Resources for all models (auto-generated CRUD)
- Build dashboard widgets with real-time stats
- Implement custom pages for reports
- Add actions for bulk operations
- Configure Filament navigation and branding
- Use Tailwind CSS for any custom styling

**Technology Stack:**
- Laravel 12
- Filament 3.x
- Tailwind CSS
- Alpine.js (included with Filament)
- Livewire (Filament's foundation)

**Dependencies:**
- All previous steps (1-9)

---

## 1. Installation & Setup

### 1.1 Install Filament

```bash
# Install Filament Panel Builder
composer require filament/filament:"^3.2"

# Run installation
php artisan filament:install --panels

# Create admin user
php artisan make:filament-user
```

### 1.2 Configure Filament

**File:** `config/filament.php` (auto-generated)

Key configuration options:
```php
return [
    'path' => 'admin',
    'domain' => null,
    'default' => 'admin',

    'dark_mode' => true,

    'brand' => 'Lead Outreach',

    'auth' => [
        'guard' => 'web',
        'pages' => [
            'login' => \Filament\Pages\Auth\Login::class,
        ],
    ],
];
```

### 1.3 Publish Filament Assets

```bash
php artisan filament:assets
```

---

## 2. Filament Resources

### 2.1 Domain Resource

**Create Resource:**
```bash
php artisan make:filament-resource Domain --generate
```

**File:** `app/Filament/Resources/DomainResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DomainResource\Pages;
use App\Models\Domain;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('domain')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Select::make('status')
                    ->options([
                        Domain::STATUS_PENDING => 'Pending',
                        Domain::STATUS_ACTIVE => 'Active',
                        Domain::STATUS_PROCESSED => 'Processed',
                        Domain::STATUS_FAILED => 'Failed',
                        Domain::STATUS_BLOCKED => 'Blocked',
                    ])
                    ->default(Domain::STATUS_PENDING)
                    ->required(),

                Forms\Components\TextInput::make('tld')
                    ->label('TLD')
                    ->maxLength(10),

                Forms\Components\DateTimePicker::make('last_checked_at'),

                Forms\Components\TextInput::make('check_count')
                    ->numeric()
                    ->default(0),

                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull()
                    ->rows(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('domain')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('tld')
                    ->label('TLD')
                    ->badge()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '0' => 'gray',
                        '1' => 'success',
                        '2' => 'info',
                        '3' => 'danger',
                        '4' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '0' => 'Pending',
                        '1' => 'Active',
                        '2' => 'Processed',
                        '3' => 'Failed',
                        '4' => 'Blocked',
                        default => 'Unknown',
                    }),

                Tables\Columns\TextColumn::make('websites_count')
                    ->counts('websites')
                    ->label('Websites'),

                Tables\Columns\TextColumn::make('check_count')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_checked_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Domain::STATUS_PENDING => 'Pending',
                        Domain::STATUS_ACTIVE => 'Active',
                        Domain::STATUS_PROCESSED => 'Processed',
                        Domain::STATUS_FAILED => 'Failed',
                        Domain::STATUS_BLOCKED => 'Blocked',
                    ]),

                Tables\Filters\SelectFilter::make('tld')
                    ->options(fn () => Domain::pluck('tld', 'tld')->unique()->toArray()),

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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDomains::route('/'),
            'create' => Pages\CreateDomain::route('/create'),
            'edit' => Pages\EditDomain::route('/{record}/edit'),
            'view' => Pages\ViewDomain::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', Domain::STATUS_PENDING)->count();
    }
}
```

---

### 2.2 Website Resource

**Create Resource:**
```bash
php artisan make:filament-resource Website --generate
```

**File:** `app/Filament/Resources/WebsiteResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteResource\Pages;
use App\Jobs\CrawlWebsiteJob;
use App\Jobs\EvaluateWebsiteRequirementsJob;
use App\Jobs\ExtractContactsJob;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static ?string $navigationIcon = 'heroicon-o-window';

    protected static ?string $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('domain_id')
                    ->relationship('domain', 'domain')
                    ->searchable()
                    ->required()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('domain')
                            ->required()
                            ->unique(),
                    ]),

                Forms\Components\TextInput::make('url')
                    ->url()
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),

                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                Website::STATUS_PENDING => 'Pending',
                                Website::STATUS_CRAWLING => 'Crawling',
                                Website::STATUS_COMPLETED => 'Completed',
                                Website::STATUS_FAILED => 'Failed',
                                Website::STATUS_PER_REVIEW => 'Per Review',
                            ])
                            ->default(Website::STATUS_PENDING)
                            ->required(),

                        Forms\Components\TextInput::make('detected_platform')
                            ->maxLength(50),
                    ]),

                Forms\Components\Grid::make(3)
                    ->schema([
                        Forms\Components\TextInput::make('page_count')
                            ->numeric()
                            ->default(0),

                        Forms\Components\TextInput::make('word_count')
                            ->numeric()
                            ->default(0),

                        Forms\Components\Toggle::make('meets_requirements')
                            ->label('Meets Requirements'),
                    ]),

                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('Crawl Information')
                    ->schema([
                        Forms\Components\DateTimePicker::make('crawled_at'),
                        Forms\Components\DateTimePicker::make('crawl_started_at'),
                        Forms\Components\TextInput::make('crawl_attempts')
                            ->numeric()
                            ->default(0),
                        Forms\Components\Textarea::make('crawl_error')
                            ->rows(3)
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
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('domain.domain')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('url')
                    ->searchable()
                    ->limit(40)
                    ->copyable()
                    ->tooltip(fn ($record) => $record->url),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '0' => 'gray',
                        '1' => 'warning',
                        '2' => 'success',
                        '3' => 'danger',
                        '4' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '0' => 'Pending',
                        '1' => 'Crawling',
                        '2' => 'Completed',
                        '3' => 'Failed',
                        '4' => 'Per Review',
                        default => 'Unknown',
                    }),

                Tables\Columns\TextColumn::make('detected_platform')
                    ->badge()
                    ->colors([
                        'primary' => 'wordpress',
                        'success' => 'shopify',
                        'warning' => 'wix',
                        'info' => 'custom',
                    ])
                    ->searchable(),

                Tables\Columns\IconColumn::make('meets_requirements')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('page_count')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('contacts_count')
                    ->counts('contacts')
                    ->label('Contacts')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('crawled_at')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Website::STATUS_PENDING => 'Pending',
                        Website::STATUS_CRAWLING => 'Crawling',
                        Website::STATUS_COMPLETED => 'Completed',
                        Website::STATUS_FAILED => 'Failed',
                        Website::STATUS_PER_REVIEW => 'Per Review',
                    ]),

                Tables\Filters\SelectFilter::make('detected_platform'),

                Tables\Filters\TernaryFilter::make('meets_requirements')
                    ->label('Qualified Leads')
                    ->placeholder('All websites')
                    ->trueLabel('Qualified only')
                    ->falseLabel('Not qualified'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),

                    Tables\Actions\Action::make('crawl')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Website $record) {
                            CrawlWebsiteJob::dispatch($record);

                            Notification::make()
                                ->success()
                                ->title('Crawl job queued')
                                ->body("Crawling {$record->url}")
                                ->send();
                        }),

                    Tables\Actions\Action::make('extractContacts')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function (Website $record) {
                            ExtractContactsJob::dispatch($record);

                            Notification::make()
                                ->success()
                                ->title('Contact extraction queued')
                                ->send();
                        }),

                    Tables\Actions\Action::make('evaluate')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Website $record) {
                            EvaluateWebsiteRequirementsJob::dispatch($record);

                            Notification::make()
                                ->success()
                                ->title('Evaluation job queued')
                                ->send();
                        }),

                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('crawl')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                CrawlWebsiteJob::dispatch($record);
                            }

                            Notification::make()
                                ->success()
                                ->title('Crawl jobs queued')
                                ->body(count($records) . ' websites queued for crawling')
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('evaluate')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                EvaluateWebsiteRequirementsJob::dispatch($record);
                            }

                            Notification::make()
                                ->success()
                                ->title('Evaluation jobs queued')
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsites::route('/'),
            'create' => Pages\CreateWebsite::route('/create'),
            'edit' => Pages\EditWebsite::route('/{record}/edit'),
            'view' => Pages\ViewWebsite::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::qualifiedLeads()->count();
    }
}
```

---

### 2.3 Contact Resource

**Create Resource:**
```bash
php artisan make:filament-resource Contact --generate
```

**File:** `app/Filament/Resources/ContactResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactResource\Pages;
use App\Models\Contact;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Outreach';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('website_id')
                    ->relationship('website', 'url')
                    ->searchable()
                    ->required(),

                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required(),

                        Forms\Components\TextInput::make('name'),
                    ]),

                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('phone'),
                        Forms\Components\TextInput::make('position'),
                    ]),

                Forms\Components\Grid::make(2)
                    ->schema([
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
                            ->minValue(1)
                            ->maxValue(100),
                    ]),

                Forms\Components\Toggle::make('is_validated')
                    ->label('Validated'),

                Forms\Components\Toggle::make('is_valid')
                    ->label('Valid')
                    ->hidden(fn ($get) => !$get('is_validated')),

                Forms\Components\Toggle::make('contacted')
                    ->label('Has been contacted'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->default('—'),

                Tables\Columns\TextColumn::make('website.url')
                    ->limit(30)
                    ->searchable(),

                Tables\Columns\TextColumn::make('source_type')
                    ->badge()
                    ->colors([
                        'success' => Contact::SOURCE_CONTACT_PAGE,
                        'info' => Contact::SOURCE_ABOUT_PAGE,
                        'warning' => Contact::SOURCE_FOOTER,
                    ]),

                Tables\Columns\TextColumn::make('priority')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state >= 80 ? 'success' : ($state >= 50 ? 'warning' : 'danger')),

                Tables\Columns\IconColumn::make('is_validated')
                    ->boolean()
                    ->label('Validated'),

                Tables\Columns\IconColumn::make('contacted')
                    ->boolean()
                    ->label('Contacted'),

                Tables\Columns\TextColumn::make('contact_count')
                    ->label('Times Contacted')
                    ->sortable(),

                Tables\Columns\TextColumn::make('first_contacted_at')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('contacted')
                    ->placeholder('All contacts')
                    ->trueLabel('Contacted')
                    ->falseLabel('Not contacted'),

                Tables\Filters\TernaryFilter::make('is_validated')
                    ->label('Validated')
                    ->placeholder('All contacts')
                    ->trueLabel('Validated only')
                    ->falseLabel('Not validated'),

                Tables\Filters\SelectFilter::make('source_type'),

                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        '80-100' => 'High Priority (80-100)',
                        '50-79' => 'Medium Priority (50-79)',
                        '1-49' => 'Low Priority (1-49)',
                    ])
                    ->query(function ($query, $state) {
                        if ($state['value'] === '80-100') {
                            $query->where('priority', '>=', 80);
                        } elseif ($state['value'] === '50-79') {
                            $query->whereBetween('priority', [50, 79]);
                        } elseif ($state['value'] === '1-49') {
                            $query->where('priority', '<', 50);
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('priority', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContacts::route('/'),
            'create' => Pages\CreateContact::route('/create'),
            'edit' => Pages\EditContact::route('/{record}/edit'),
            'view' => Pages\ViewContact::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::validated()->notContacted()->count();
    }
}
```

---

### 2.4 SMTP Credential Resource

**Create Resource:**
```bash
php artisan make:filament-resource SmtpCredential --generate
```

**File:** `app/Filament/Resources/SmtpCredentialResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SmtpCredentialResource\Pages;
use App\Models\SmtpCredential;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SmtpCredentialResource extends Resource
{
    protected static ?string $model = SmtpCredential::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $label = 'SMTP Account';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('SMTP Configuration')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('host')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('port')
                                    ->required()
                                    ->numeric()
                                    ->default(587),

                                Forms\Components\Select::make('encryption')
                                    ->options([
                                        'tls' => 'TLS',
                                        'ssl' => 'SSL',
                                    ])
                                    ->default('tls')
                                    ->required(),
                            ]),

                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->required(fn ($context) => $context === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->maxLength(255),
                    ]),

                Forms\Components\Section::make('From Information')
                    ->schema([
                        Forms\Components\TextInput::make('from_address')
                            ->email()
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('from_name')
                            ->required()
                            ->maxLength(255),
                    ]),

                Forms\Components\Section::make('Limits & Usage')
                    ->schema([
                        Forms\Components\TextInput::make('daily_limit')
                            ->numeric()
                            ->required()
                            ->default(10)
                            ->minValue(1),

                        Forms\Components\Placeholder::make('emails_sent_today')
                            ->content(fn ($record) => $record?->emails_sent_today ?? 0),

                        Forms\Components\Placeholder::make('success_count')
                            ->content(fn ($record) => $record?->success_count ?? 0),

                        Forms\Components\Placeholder::make('failure_count')
                            ->content(fn ($record) => $record?->failure_count ?? 0),
                    ])
                    ->columns(4)
                    ->hidden(fn ($context) => $context === 'create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                Tables\Columns\TextColumn::make('from_address')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('host')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('emails_sent_today')
                    ->label('Sent Today')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) =>
                        $record->emails_sent_today >= $record->daily_limit ? 'danger' : 'success'
                    )
                    ->formatStateUsing(fn ($record) =>
                        $record->emails_sent_today . ' / ' . $record->daily_limit
                    ),

                Tables\Columns\TextColumn::make('success_count')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('failure_count')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All accounts')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),

                    Tables\Actions\Action::make('resetCounter')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (SmtpCredential $record) {
                            $record->update([
                                'emails_sent_today' => 0,
                                'last_reset_date' => today(),
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Counter reset')
                                ->send();
                        }),

                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmtpCredentials::route('/'),
            'create' => Pages\CreateSmtpCredential::route('/create'),
            'edit' => Pages\EditSmtpCredential::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $available = static::getModel()::available()->count();
        return $available > 0 ? (string) $available : null;
    }
}
```

---

## 3. Dashboard Widgets

### 3.1 Stats Overview Widget

**Create Widget:**
```bash
php artisan make:filament-widget StatsOverview --stats-overview
```

**File:** `app/Filament/Widgets/StatsOverview.php`

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailReviewQueue;
use App\Models\EmailSentLog;
use App\Models\Website;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Domains', Domain::count())
                ->description('Domains in database')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary')
                ->chart([7, 12, 18, 25, 32, 39, 45]),

            Stat::make('Qualified Leads', Website::qualifiedLeads()->count())
                ->description('Websites meeting requirements')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->chart([2, 5, 8, 12, 18, 25, 32]),

            Stat::make('Emails Today', EmailSentLog::today()->count())
                ->description('Sent in last 24 hours')
                ->descriptionIcon('heroicon-m-envelope')
                ->color('warning')
                ->chart([5, 10, 8, 12, 15, 18, 20]),

            Stat::make('Pending Reviews', EmailReviewQueue::pending()->count())
                ->description('Awaiting manual approval')
                ->descriptionIcon('heroicon-m-eye')
                ->color('info')
                ->url(route('filament.admin.resources.review-queue.index')),

            Stat::make('Validated Contacts', Contact::validated()->count())
                ->description('Ready for outreach')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),

            Stat::make('Email Success Rate', function () {
                $total = EmailSentLog::count();
                if ($total === 0) return '0%';

                $successful = EmailSentLog::successful()->count();
                $rate = round(($successful / $total) * 100, 1);

                return $rate . '%';
            })
                ->description('Overall delivery rate')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color(function () {
                    $total = EmailSentLog::count();
                    if ($total === 0) return 'gray';

                    $successful = EmailSentLog::successful()->count();
                    $rate = ($successful / $total) * 100;

                    return $rate >= 90 ? 'success' : ($rate >= 70 ? 'warning' : 'danger');
                }),
        ];
    }
}
```

---

### 3.2 Emails Chart Widget

**Create Widget:**
```bash
php artisan make:filament-widget EmailsChart --chart
```

**File:** `app/Filament/Widgets/EmailsChart.php`

```php
<?php

namespace App\Filament\Widgets;

use App\Models\EmailSentLog;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class EmailsChart extends ChartWidget
{
    protected static ?string $heading = 'Emails Sent (Last 30 Days)';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $data = [];
        $labels = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $count = EmailSentLog::whereDate('sent_at', $date)->count();

            $labels[] = $date->format('M d');
            $data[] = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Emails sent',
                    'data' => $data,
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
```

---

### 3.3 Platform Distribution Widget

**Create Widget:**
```bash
php artisan make:filament-widget PlatformDistribution --chart
```

**File:** `app/Filament/Widgets/PlatformDistribution.php`

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Website;
use Filament\Widgets\ChartWidget;

class PlatformDistribution extends ChartWidget
{
    protected static ?string $heading = 'Qualified Leads by Platform';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $platforms = Website::qualifiedLeads()
            ->selectRaw('detected_platform, COUNT(*) as count')
            ->groupBy('detected_platform')
            ->pluck('count', 'detected_platform')
            ->toArray();

        return [
            'datasets' => [
                [
                    'data' => array_values($platforms),
                    'backgroundColor' => [
                        'rgb(59, 130, 246)',
                        'rgb(16, 185, 129)',
                        'rgb(251, 191, 36)',
                        'rgb(239, 68, 68)',
                        'rgb(168, 85, 247)',
                    ],
                ],
            ],
            'labels' => array_map('ucfirst', array_keys($platforms)),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
```

---

## 4. Custom Pages

### 4.1 Email Templates Resource

*Create using the same pattern as above resources*

### 4.2 Blacklist Resource

*Create using the same pattern as above resources*

### 4.3 Review Queue Resource

*Adapt the ReviewQueueController from original plan into a Filament Resource*

---

## 5. Navigation Configuration

**File:** `app/Providers/Filament/AdminPanelProvider.php`

```php
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                // Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->brandName('Lead Outreach System')
            ->favicon(asset('favicon.png'))
            ->darkMode(true)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Data Management')
                    ->icon('heroicon-o-folder'),
                NavigationGroup::make('Outreach')
                    ->icon('heroicon-o-envelope'),
                NavigationGroup::make('Configuration')
                    ->icon('heroicon-o-cog'),
                NavigationGroup::make('Reports')
                    ->icon('heroicon-o-chart-bar'),
            ]);
    }
}
```

---

## 6. Implementation Checklist

### Phase 1: Installation ✓
- [ ] Install Filament: `composer require filament/filament:"^3.2"`
- [ ] Run installation: `php artisan filament:install --panels`
- [ ] Create admin user: `php artisan make:filament-user`
- [ ] Verify access at `/admin`

### Phase 2: Resources ✓
- [ ] Create Domain Resource
- [ ] Create Website Resource
- [ ] Create Contact Resource
- [ ] Create SmtpCredential Resource
- [ ] Create EmailTemplate Resource
- [ ] Create BlacklistEntry Resource
- [ ] Create EmailReviewQueue Resource
- [ ] Create WebsiteRequirement Resource

### Phase 3: Widgets ✓
- [ ] Create StatsOverview widget
- [ ] Create EmailsChart widget
- [ ] Create PlatformDistribution widget
- [ ] Add widgets to dashboard

### Phase 4: Customization ✓
- [ ] Configure navigation groups
- [ ] Set brand name and logo
- [ ] Configure colors and theme
- [ ] Add custom actions to resources
- [ ] Add filters and bulk actions

### Phase 5: Testing ✓
- [ ] Test all CRUD operations
- [ ] Test custom actions (crawl, evaluate, etc.)
- [ ] Test bulk operations
- [ ] Test filters and searches
- [ ] Test responsive design

---

## 7. Benefits of Filament

### Auto-Generated CRUD
- Forms generated from models
- Tables with sorting, filtering, searching
- Relationships handled automatically
- Validation built-in

### Rich Components
- 50+ form components (TextInput, Select, FileUpload, etc.)
- Table components with actions
- Widgets for dashboard
- Notifications and modals

### Developer Experience
- Tailwind CSS styling (fully customizable)
- Alpine.js for interactivity
- Livewire for reactivity
- Dark mode support
- Mobile responsive

### Features Out-of-the-Box
- Authentication
- Authorization (policies)
- Multi-tenancy support
- Global search
- Import/Export
- Bulk operations
- Actions and notifications

---

## 8. Estimated Time

**Total Implementation Time:** 6-8 hours (vs 10-12 hours with custom controllers)

**Breakdown:**
- Installation & Setup: 30 mins
- Resources (8 resources × 45 mins): 6 hours
- Widgets (3 widgets × 30 mins): 1.5 hours
- Customization & Testing: 1 hour

**Time Saved:** 40-50% compared to building custom admin panel!

---

## Conclusion

Using **Filament 3.x** with **Tailwind CSS** provides a professional, feature-rich admin panel with significantly less code and faster development time. All CRUD operations, dashboards, and management interfaces are beautifully designed and fully functional out of the box.

**Next Steps:**
1. Install Filament
2. Create resources for each model
3. Customize navigation and branding
4. Add custom actions for domain operations
5. Deploy and enjoy! 🚀
