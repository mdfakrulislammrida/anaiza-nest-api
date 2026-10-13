<?php

namespace App\Filament\Concerns;

use App\Support\BrandVoice;
use Filament\Notifications\Notification;

/**
 * Adds a one-line "Brand check: 2 notes" to a create/edit page's save notification. It only reads the
 * saved record, so it cannot get in the way of saving. Nothing is added when there are no notes.
 */
trait ReportsBrandVoice
{
    /**
     * The attributes of the saved record whose text is checked.
     *
     * @return list<string>
     */
    abstract protected function brandVoiceAttributes(): array;

    protected function getSavedNotification(): ?Notification
    {
        return $this->withBrandVoice(parent::getSavedNotification());
    }

    protected function getCreatedNotification(): ?Notification
    {
        return $this->withBrandVoice(parent::getCreatedNotification());
    }

    private function withBrandVoice(?Notification $notification): ?Notification
    {
        if ($notification === null || ! $this->record) {
            return $notification;
        }

        $parts = array_filter([
            BrandVoice::summary(array_map(
                fn (string $attribute): string => (string) ($this->record->{$attribute} ?? ''),
                $this->brandVoiceAttributes(),
            )),
            // Content the shop cleans on save says so, so a removed script or class is never a surprise.
            method_exists($this->record, 'contentHtmlReport') && $this->record->contentHtmlReport() !== []
                ? 'HTML tidied: '.implode(' ', $this->record->contentHtmlReport())
                : null,
        ]);

        return $parts === [] ? $notification : $notification->body(implode(' ', $parts));
    }
}
