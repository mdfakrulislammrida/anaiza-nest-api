<?php

namespace App\Support\Data;

use Illuminate\Support\Facades\File;

/**
 * A plain copy of the SQLite file, made before a command that changes a lot of data. Separate from the nightly backup
 * (App\Support\Backup\BackupService): this one is a single file, named for the command that asked for it.
 */
final class SqliteBackup
{
    /**
     * @return string the path of the copy, in storage/backups
     *
     * @throws \RuntimeException with a sentence for the person running the command when no copy could be made
     */
    public static function copy(string $prefix): string
    {
        $source = (string) config('database.connections.sqlite.database');

        if (config('database.default') !== 'sqlite' || $source === '' || $source === ':memory:' || ! is_file($source)) {
            throw new \RuntimeException('The database is not a SQLite file, so it cannot be copied first.');
        }

        $directory = storage_path('backups');
        File::ensureDirectoryExists($directory);
        $target = $directory.DIRECTORY_SEPARATOR.$prefix.'-'.now()->format('Ymd-His').'.sqlite';

        if (! @copy($source, $target)) {
            throw new \RuntimeException('The copy of the database could not be written to storage/backups.');
        }

        return $target;
    }
}
