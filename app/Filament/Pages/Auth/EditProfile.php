<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * "My profile": name, email, password and a photo. Filament's profile page always edits the signed-in user and nobody else, so a user can change only their
 * own details and photo (the Users screen has no photo field). The photo is a square, jpg, png or webp, up to 2 MB, kept in the public disk's "avatars" folder;
 * replacing or removing it deletes the old file (see User::booted).
 */
class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getAvatarFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getAvatarFormComponent(): Component
    {
        return FileUpload::make('avatar')
            ->label('Photo')
            ->helperText('A square picture of you: JPG, PNG or WebP, up to 2 MB. You can crop it after choosing it.')
            ->image()
            ->imageEditor()
            ->imageEditorAspectRatios(['1:1'])
            ->imageCropAspectRatio('1:1')
            ->imageResizeMode('cover')
            ->imageResizeUpscale(false)
            ->imageResizeTargetWidth('500')
            ->imageResizeTargetHeight('500')
            ->circleCropper()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(2048)
            ->disk('public')
            ->directory('avatars')
            ->visibility('public');
    }
}
