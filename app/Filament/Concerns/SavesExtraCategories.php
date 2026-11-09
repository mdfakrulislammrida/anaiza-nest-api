<?php

namespace App\Filament\Concerns;

/**
 * The product form's "Also show in these categories". The extras are not a column of products: they are read from, and written to, the category_product
 * table here, around the normal save. The primary category (category_id) is saved by the form as always and is never one of the extras.
 */
trait SavesExtraCategories
{
    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['extra_category_ids'] = $this->getRecord()->categories()->where('categories.id', '!=', $this->getRecord()->category_id)->pluck('categories.id')->map(fn ($id): int => (int) $id)->all();

        return $data;
    }

    protected function saveExtraCategories(): void
    {
        $this->getRecord()->syncCategories((array) ($this->data['extra_category_ids'] ?? []));
    }
}
