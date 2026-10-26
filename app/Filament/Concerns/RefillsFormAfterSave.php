<?php

namespace App\Filament\Concerns;

/**
 * For an edit page whose model changes what is saved (a picture converted to WebP and the upload removed): after Save the form is
 * filled again from the saved record, exactly as a reload would. Without it the form keeps the path of the upload that no longer
 * exists, and the next Save on the same open page writes that dead path over the converted picture.
 */
trait RefillsFormAfterSave
{
    protected function afterSave(): void
    {
        $this->fillForm();
    }
}
