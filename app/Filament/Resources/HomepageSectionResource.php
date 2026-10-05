<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\HomepageSectionResource\Pages;
use App\Models\HomepageSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HomepageSectionResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = HomepageSection::class;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Homepage Sections';

    protected static ?string $modelLabel = 'Homepage Section';

    protected static string $permissionKey = 'homepage_sections.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->options(HomepageSection::TYPES)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('position')
                    ->numeric()
                    ->default(fn () => ((int) HomepageSection::max('position')) + 1)
                    ->required(),
                Forms\Components\Toggle::make('is_enabled')
                    ->label('Enabled')
                    ->default(true),
                Forms\Components\DateTimePicker::make('deal_ends_at')
                    ->label('Deal ends at (optional)')
                    ->helperText('Times are Bangladesh time. When set and still in the future, the storefront shows a live countdown to this moment. Leave blank for no countdown -- nothing is shown by default.')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('type') === 'hot_deals')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('custom_title')
                    ->label('Custom Title (optional)')
                    ->helperText('Shown as a heading above the HTML block -- leave blank for none.')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('type') === 'custom_html')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('custom_html')
                    ->label('Custom HTML')
                    ->helperText('Raw HTML, rendered as-is on the homepage -- not sanitized. Only paste content you trust, since anyone with admin access could use this to inject anything.')
                    ->rows(12)
                    ->required(fn (Get $get): bool => $get('type') === 'custom_html')
                    ->visible(fn (Get $get): bool => $get('type') === 'custom_html')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => HomepageSection::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('custom_title')
                    ->label('Custom Title')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('is_enabled')
                    ->label('Enabled'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_enabled')
                    ->label('Enabled'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomepageSections::route('/'),
            'create' => Pages\CreateHomepageSection::route('/create'),
            'edit' => Pages\EditHomepageSection::route('/{record}/edit'),
        ];
    }
}
