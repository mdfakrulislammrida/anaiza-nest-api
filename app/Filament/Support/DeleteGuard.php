<?php

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Friendly deletes for records the database refuses to orphan.
 *
 * A few foreign keys are `restrictOnDelete` on purpose (a category that still has
 * products, a customer or shipping zone that still has orders), so deleting them used
 * to surface as a raw 500. These closures plug into an action's `->using()` hook:
 * they check the named relations first, and as a safety net they also turn a leftover
 * foreign-key violation into the same message instead of an error page.
 *
 *   DeleteAction::make()->using(DeleteGuard::single(['products' => 'products']))
 *   DeleteBulkAction::make()->using(DeleteGuard::bulk(['products' => 'products']))
 *       ->successNotificationTitle(null)
 *
 * The bulk variant sends its own notifications, so it needs the default one switched off.
 */
class DeleteGuard
{
    /**
     * @param  array<string, string>  $blockers  Relation name => plural noun shown to the admin.
     * @param  string|null  $hint  What to do instead, appended to the message.
     */
    public static function single(array $blockers, ?string $hint = null): Closure
    {
        return function (Model $record) use ($blockers, $hint): bool {
            $reason = self::reasonFor($record, $blockers, $hint);

            if ($reason === null) {
                try {
                    return (bool) $record->delete();
                } catch (QueryException $e) {
                    if (! self::isForeignKeyViolation($e)) {
                        throw $e;
                    }

                    $reason = self::fallbackReason($hint);
                }
            }

            Notification::make()
                ->danger()
                ->title('Can\'t delete '.self::label($record))
                ->body($reason)
                ->persistent()
                ->send();

            return false;
        };
    }

    /**
     * @param  array<string, string>  $blockers
     */
    public static function bulk(array $blockers, ?string $hint = null): Closure
    {
        return function (Collection $records) use ($blockers, $hint): void {
            $deleted = 0;
            $skipped = [];

            foreach ($records as $record) {
                $reason = self::reasonFor($record, $blockers, $hint);

                if ($reason === null) {
                    try {
                        $record->delete();
                        $deleted++;

                        continue;
                    } catch (QueryException $e) {
                        if (! self::isForeignKeyViolation($e)) {
                            throw $e;
                        }

                        $reason = self::fallbackReason($hint);
                    }
                }

                $skipped[] = [self::label($record), $reason];
            }

            if ($deleted > 0) {
                Notification::make()
                    ->success()
                    ->title($deleted === 1 ? '1 record deleted' : "{$deleted} records deleted")
                    ->send();
            }

            if ($skipped !== []) {
                $shown = array_slice($skipped, 0, 8);
                $lines = array_map(fn (array $row): string => "{$row[0]}: {$row[1]}", $shown);

                if (count($skipped) > count($shown)) {
                    $lines[] = '... and '.(count($skipped) - count($shown)).' more.';
                }

                Notification::make()
                    ->warning()
                    ->title(count($skipped) === 1 ? '1 record was skipped' : count($skipped).' records were skipped')
                    ->body(implode("\n", $lines))
                    ->persistent()
                    ->send();
            }
        };
    }

    /**
     * @param  array<string, string>  $blockers
     */
    public static function reasonFor(Model $record, array $blockers, ?string $hint = null): ?string
    {
        $found = [];

        foreach ($blockers as $relation => $noun) {
            $count = $record->{$relation}()->count();

            if ($count > 0) {
                $found[] = number_format($count).' '.($count === 1 ? self::singular($noun) : $noun);
            }
        }

        if ($found === []) {
            return null;
        }

        $reason = 'It still has '.implode(' and ', $found).'.';

        return $hint ? "{$reason} {$hint}" : $reason;
    }

    private static function fallbackReason(?string $hint): string
    {
        $reason = 'Other records still depend on it.';

        return $hint ? "{$reason} {$hint}" : $reason;
    }

    private static function singular(string $noun): string
    {
        return match (true) {
            str_ends_with($noun, 'ies') => substr($noun, 0, -3).'y',
            str_ends_with($noun, 's') => substr($noun, 0, -1),
            default => $noun,
        };
    }

    private static function label(Model $record): string
    {
        foreach (['name', 'title', 'code', 'label'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return "\"{$value}\"";
            }
        }

        return '#'.$record->getKey();
    }

    private static function isForeignKeyViolation(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'foreign key');
    }
}
