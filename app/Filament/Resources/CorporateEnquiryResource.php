<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CorporateEnquiryResource\Pages;
use App\Models\CorporateEnquiry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CorporateEnquiryResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = CorporateEnquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Corporate enquiries';

    protected static ?string $modelLabel = 'corporate enquiry';

    protected static ?string $pluralModelLabel = 'corporate enquiries';

    protected static string $permissionKey = 'corporate_enquiries.manage';

    public static function getNavigationBadge(): ?string
    {
        $new = CorporateEnquiry::query()->where('status', 'new')->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Status and notes')
                    ->description('Your own working notes. They are never shown to the customer.')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options(CorporateEnquiry::STATUSES)
                            ->required()
                            ->native(false),
                        Forms\Components\Textarea::make('internal_notes')
                            ->label('Internal notes')
                            ->rows(4)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('The enquiry')
                    ->description('As submitted on the storefront. Read-only.')
                    ->schema([
                        Forms\Components\TextInput::make('company')->disabled(),
                        Forms\Components\TextInput::make('name')->label('Contact name')->disabled(),
                        Forms\Components\TextInput::make('phone')->disabled(),
                        Forms\Components\TextInput::make('email')->disabled(),
                        Forms\Components\TextInput::make('quantity')->disabled(),
                        Forms\Components\DatePicker::make('needed_by')->label('Needed by')->disabled(),
                        Forms\Components\Textarea::make('products_of_interest')
                            ->label('Products of interest')
                            ->rows(3)
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('message')
                            ->rows(4)
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CorporateEnquiry::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'warning',
                        'quoted' => 'info',
                        'won' => 'success',
                        'lost' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('company')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Contact')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('needed_by')
                    ->label('Needed by')
                    ->date('j M Y')
                    ->placeholder('Not given')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(CorporateEnquiry::STATUSES),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Open'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No corporate enquiries yet')
            ->emptyStateDescription('Enquiries sent from the Corporate gifting page appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCorporateEnquiries::route('/'),
            'edit' => Pages\EditCorporateEnquiry::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
