<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\BlockedContactResource\Pages;
use App\Models\BlockedContact;
use App\Support\Contact;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class BlockedContactResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = BlockedContact::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Blocked buyers';

    protected static ?string $modelLabel = 'blocked buyer';

    protected static ?string $pluralModelLabel = 'blocked buyers';

    protected static string $permissionKey = 'blocked_contacts.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('kind')
                    ->label('What to block')
                    ->options(BlockedContact::KINDS)
                    ->default(BlockedContact::PHONE)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label(fn (Forms\Get $get): string => match ($get('kind')) {
                        BlockedContact::EMAIL => 'Email address',
                        BlockedContact::ADDRESS => 'Words to look for in the address',
                        default => 'Phone number',
                    })
                    ->helperText(fn (Forms\Get $get): string => match ($get('kind')) {
                        BlockedContact::EMAIL => 'Matched exactly, in any letter case.',
                        BlockedContact::ADDRESS => 'Any order whose street address contains these words is turned away. Keep it specific, like a street and number.',
                        default => '01XXXXXXXXX, however it is typed: +880 and spaces are fine.',
                    })
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get): void {
                        if ($get('kind') === BlockedContact::PHONE && Contact::phone((string) $value) === null) {
                            $fail('That is not a Bangladeshi mobile number.');
                        }
                        if ($get('kind') === BlockedContact::EMAIL && Contact::email((string) $value) === null) {
                            $fail('That is not an email address.');
                        }
                    }),
                Forms\Components\TextInput::make('note')
                    ->label('Note for your team (optional)')
                    ->helperText('Why it was blocked. The buyer never sees this.')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => BlockedContact::KINDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('value')->searchable(),
                Tables\Columns\TextColumn::make('note')->placeholder('—')->limit(60),
                Tables\Columns\TextColumn::make('creator.name')->label('Added by')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Added')->dateTime('j M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->options(BlockedContact::KINDS),
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

    /**
     * Stamps who added a block.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withCreator(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBlockedContacts::route('/'),
        ];
    }
}
