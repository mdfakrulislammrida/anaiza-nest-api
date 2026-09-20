<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\MediaItemResource\Pages;
use App\Models\MediaItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaItemResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = MediaItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Media Library';

    protected static ?string $slug = 'media-library';

    protected static ?string $modelLabel = 'Media Item';

    protected static ?string $pluralModelLabel = 'Media Library';

    protected static string $permissionKey = 'media_library.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('path')
                    ->label('File')
                    ->disk('public')
                    ->directory('media-library')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        if ($state instanceof TemporaryUploadedFile) {
                            $set('original_name', $state->getClientOriginalName());
                            $set('mime_type', $state->getMimeType());
                            $set('size', $state->getSize());
                        }
                    })
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('original_name'),
                Forms\Components\Hidden::make('mime_type'),
                Forms\Components\Hidden::make('size'),
                Forms\Components\Hidden::make('uploaded_by')
                    ->default(fn () => Auth::id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('path')
                    ->label('')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('original_name')
                    ->label('File')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('mime_type')
                    ->label('Type')
                    ->badge(),
                Tables\Columns\TextColumn::make('human_size')
                    ->label('Size'),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->limit(50)
                    ->copyable()
                    ->copyMessage('URL copied')
                    ->copyMessageDuration(1500),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\Action::make('bulkUpload')
                    ->label('Upload files')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\FileUpload::make('files')
                            ->label('Files')
                            ->multiple()
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        foreach ($data['files'] as $file) {
                            /** @var TemporaryUploadedFile $file */
                            $path = $file->store('media-library', 'public');

                            MediaItem::create([
                                'disk' => 'public',
                                'path' => $path,
                                'original_name' => $file->getClientOriginalName(),
                                'mime_type' => $file->getMimeType(),
                                'size' => $file->getSize(),
                                'uploaded_by' => Auth::id(),
                            ]);
                        }
                    })
                    ->successNotificationTitle('Files uploaded'),
                Tables\Actions\CreateAction::make()
                    ->label('Add one file'),
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
            'index' => Pages\ListMediaItems::route('/'),
            'create' => Pages\CreateMediaItem::route('/create'),
            'edit' => Pages\EditMediaItem::route('/{record}/edit'),
        ];
    }
}
