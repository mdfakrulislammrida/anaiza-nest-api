<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CourierStatusMapResource\Pages;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * What each status a courier reports means for an order, in one table you can read and change. A status we have not seen
 * before is added here, as "Unknown status", the first time it arrives.
 */
class CourierStatusMapResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = CourierStatusMap::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Courier statuses';

    protected static ?string $modelLabel = 'courier status';

    protected static ?string $slug = 'courier-statuses';

    protected static string $permissionKey = 'integration_settings.manage';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('courier')->options(CourierSetting::COURIERS)->required()->native(false),
            Forms\Components\TextInput::make('courier_status')
                ->label('Status as the courier writes it')
                ->required()->maxLength(120)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('courier', $get('courier'))),
            Forms\Components\TextInput::make('stage')
                ->label('What it means (shown on the order, and to the customer in plain words)')
                ->required()->maxLength(80)
                ->helperText('For example Picked up, On its way, Delivered, On hold.'),
            Forms\Components\Select::make('effect')
                ->label('What it does to the order')
                ->options(CourierStatusMap::EFFECTS)
                ->required()->native(false)->default('none')
                ->helperText('Delivered only works on a status marked Final. Returned or cancelled flags the order and tells you; it never puts stock back and never refunds.'),
            Forms\Components\Toggle::make('is_final')
                ->label('Final: the parcel will not change after this')
                ->helperText('Checking stops after a final status. Mark only the courier\'s own confirmed endings, never a rider\'s word that is still waiting for approval.'),
            Forms\Components\Toggle::make('marks_cod_collected')
                ->label('The cash on delivery was collected')
                ->helperText('Recorded on the order, with the date, when a status check (not a webhook alone) confirms this final status.'),
            Forms\Components\TextInput::make('rank')
                ->label('Order of events (higher is later)')
                ->numeric()->minValue(0)->maxValue(1000)
                ->helperText('A report with a lower number than the one on the order is ignored, so a parcel never goes backwards. Leave blank to keep the current position.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('rank')
            ->paginated([25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('courier')->formatStateUsing(fn (string $state): string => CourierSetting::COURIERS[$state] ?? $state)->sortable(),
                Tables\Columns\TextColumn::make('courier_status')->label('Status')->searchable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('stage')->label('Means')->wrap(),
                Tables\Columns\TextColumn::make('effect')->formatStateUsing(fn (string $state): string => CourierStatusMap::EFFECTS[$state] ?? $state)->badge()->color(fn (string $state): string => match ($state) {
                    'delivered' => 'success',
                    'returned' => 'danger',
                    'partial' => 'warning',
                    default => 'gray',
                }),
                Tables\Columns\IconColumn::make('is_final')->label('Final')->boolean(),
                Tables\Columns\IconColumn::make('marks_cod_collected')->label('Cash')->boolean(),
                Tables\Columns\TextColumn::make('rank')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('seen_count')->label('Seen')->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('never'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('courier')->options(CourierSetting::COURIERS),
                Tables\Filters\Filter::make('unmapped')->label('Not mapped yet')->toggle()->query(fn ($query) => $query->where('stage', 'like', 'Unknown status%')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->emptyStateHeading('No statuses yet')
            ->emptyStateDescription('Statuses appear here as the couriers report them.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCourierStatusMaps::route('/')];
    }
}
