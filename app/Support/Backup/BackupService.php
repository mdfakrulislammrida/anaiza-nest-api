<?php

namespace App\Support\Backup;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Dhaka;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use RuntimeException;
use ZipArchive;

/**
 * Makes, keeps and lists the shop's backups.
 *
 * A backup is one zip: a consistent copy of the SQLite database (taken with VACUUM INTO, which is safe while customers are
 * ordering, unlike copying the file) plus the uploaded pictures and a small manifest. It never contains .env.
 */
final class BackupService
{
    public const KINDS = ['daily', 'weekly', 'manual'];

    private const NAME_PATTERN = '/^anaiza-(daily|weekly|manual)-\d{8}-\d{6}\.zip$/';

    /** Picture and archive formats that are already compressed: stored as they are rather than squeezed again. */
    private const STORED = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'pdf', 'zip', 'mp4', 'webm'];

    public static function directory(): string
    {
        return rtrim((string) config('backup.directory'), '/\\');
    }

    /**
     * Makes a backup now and tidies old ones.
     *
     * @return array{name: string, path: string, size: int, kind: string, files: int, offsite: ?string}
     */
    public function run(string $kind = 'daily'): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("Unknown backup kind \"{$kind}\".");
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not available on this server, so a backup cannot be made. Ask the host to enable "zip".');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Backups are built for the SQLite database this shop runs on; the database in use is "'.$connection->getDriverName().'".');
        }

        $directory = self::directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("The backup folder {$directory} could not be created.");
        }

        $source = $this->sourceFiles();
        $this->assertRoomFor($directory, $source['bytes']);

        $name = sprintf('anaiza-%s-%s.zip', $kind, Dhaka::now()->format('Ymd-His'));
        $final = $directory.DIRECTORY_SEPARATOR.$name;
        $partial = $final.'.partial';
        $snapshot = $directory.DIRECTORY_SEPARATOR.'.snapshot-'.bin2hex(random_bytes(4)).'.sqlite';

        try {
            $this->snapshotDatabase($snapshot);
            $this->verifySnapshot($snapshot);
            $this->writeZip($partial, $snapshot, $source['files']);

            if (! @rename($partial, $final)) {
                throw new RuntimeException('The finished backup could not be put in place.');
            }
        } finally {
            @unlink($snapshot);
            @unlink($partial);
        }

        // Tidied before the copy goes anywhere, so a slow or unreachable other server never stops old backups being removed.
        $this->prune();

        $offsite = $this->pushOffsite($final, $name);

        return ['name' => $name, 'path' => $final, 'size' => (int) filesize($final), 'kind' => $kind, 'files' => count($source['files']), 'offsite' => $offsite];
    }

    /**
     * The backups on this server, newest first.
     *
     * @return list<array{name: string, kind: string, size: int, modified: int}>
     */
    public function all(): array
    {
        $directory = self::directory();

        if (! is_dir($directory)) {
            return [];
        }

        $found = [];

        foreach (scandir($directory) ?: [] as $file) {
            if (preg_match(self::NAME_PATTERN, $file, $match) !== 1) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$file;
            $found[] = ['name' => $file, 'kind' => $match[1], 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
        }

        usort($found, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $found;
    }

    /**
     * The full path of a backup by name, or null for anything that is not one of ours (so a name from a request can never
     * reach another file).
     */
    public function pathFor(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }

        $path = self::directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /** Removes backups beyond what is kept of each kind. Returns the names removed. */
    public function prune(): array
    {
        $keep = [
            'daily' => max(1, (int) config('backup.keep_daily')),
            'weekly' => max(1, (int) config('backup.keep_weekly')),
            'manual' => max(1, (int) config('backup.keep_manual')),
        ];

        $removed = [];
        $seen = ['daily' => 0, 'weekly' => 0, 'manual' => 0];

        foreach ($this->all() as $backup) {
            if (++$seen[$backup['kind']] > $keep[$backup['kind']]) {
                @unlink(self::directory().DIRECTORY_SEPARATOR.$backup['name']);
                $removed[] = $backup['name'];
            }
        }

        // A run that was cut short leaves a partial file behind; anything that old is not coming back.
        foreach (glob(self::directory().DIRECTORY_SEPARATOR.'*.partial') ?: [] as $partial) {
            if (filemtime($partial) < time() - 86400) {
                @unlink($partial);
            }
        }

        return $removed;
    }

    /**
     * @return array{files: list<array{path: string, entry: string, size: int}>, bytes: int}
     */
    private function sourceFiles(): array
    {
        $bytes = (int) @filesize((string) config('database.connections.sqlite.database'));
        $files = [];

        $root = (string) config('backup.files_root');

        if (config('backup.include_files') && is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.gitignore') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[] = ['path' => $file->getPathname(), 'entry' => 'files/'.$relative, 'size' => (int) $file->getSize()];
                $bytes += (int) $file->getSize();
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function assertRoomFor(string $directory, int $bytes): void
    {
        $free = @disk_free_space($directory);

        if ($free === false) {
            return;
        }

        $needed = (int) ($bytes * 1.2) + ((int) config('backup.min_free_mb')) * 1024 * 1024;

        if ($free < $needed) {
            throw new RuntimeException(sprintf(
                'Not enough free disk space for a backup: %s free, about %s needed. Nothing was written.',
                self::megabytes($free),
                self::megabytes($needed),
            ));
        }
    }

    private function snapshotDatabase(string $to): void
    {
        $pdo = DB::connection()->getPdo();

        if ($pdo->inTransaction()) {
            throw new RuntimeException('A backup cannot be taken in the middle of a database transaction.');
        }

        // Reads one consistent view of the database whatever else is writing, and writes a compact copy.
        $pdo->exec('VACUUM INTO '.$pdo->quote($to));
    }

    private function verifySnapshot(string $path): void
    {
        $copy = new \PDO('sqlite:'.$path);
        $result = (string) $copy->query('PRAGMA integrity_check')->fetchColumn();

        if (strtolower($result) !== 'ok') {
            throw new RuntimeException('The copy of the database did not pass its own check ('.$result.'), so it was discarded.');
        }
    }

    /**
     * @param  list<array{path: string, entry: string, size: int}>  $files
     */
    private function writeZip(string $target, string $database, array $files): void
    {
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The backup file could not be created.');
        }

        $zip->addFile($database, 'database.sqlite');
        $zip->setCompressionName('database.sqlite', ZipArchive::CM_DEFLATE);
        $zip->addFromString('manifest.json', json_encode($this->manifest(count($files)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($files as $file) {
            $zip->addFile($file['path'], $file['entry']);
            $zip->setCompressionName(
                $file['entry'],
                in_array(strtolower(pathinfo($file['entry'], PATHINFO_EXTENSION)), self::STORED, true) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE,
            );
        }

        if (! $zip->close()) {
            throw new RuntimeException('The backup file could not be finished (is the disk full?).');
        }

        $check = new ZipArchive;

        if ($check->open($target, ZipArchive::CHECKCONS) !== true || $check->locateName('database.sqlite') === false) {
            throw new RuntimeException('The finished backup did not read back correctly, so it was discarded.');
        }

        $check->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(int $files): array
    {
        $count = function (string $model): ?int {
            try {
                return $model::query()->count();
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'made_at' => Dhaka::now()->toIso8601String(),
            'app' => config('app.name'),
            'url' => config('app.url'),
            'database' => 'database.sqlite',
            'picture_files' => $files,
            'orders' => $count(Order::class),
            'customers' => $count(Customer::class),
            'products' => $count(Product::class),
            'note' => 'Does not include .env. See docs/backup-and-restore.md for how to restore.',
        ];
    }

    /**
     * The optional copy on another server. Returns where it went, or null when it is off.
     */
    private function pushOffsite(string $path, string $name): ?string
    {
        $config = config('backup.sftp');

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        if (! class_exists(SftpAdapter::class)) {
            throw new RuntimeException('BACKUP_SFTP_ENABLED is on, but the SFTP package is not installed (composer require league/flysystem-sftp-v3). The backup on this server was made.');
        }

        foreach (['host', 'username'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw new RuntimeException('BACKUP_SFTP_ENABLED is on, but BACKUP_SFTP_'.strtoupper($required).' is empty. The backup on this server was made.');
            }
        }

        $disk = Storage::build(array_filter([
            'driver' => 'sftp',
            'host' => $config['host'],
            'port' => $config['port'],
            'username' => $config['username'],
            'password' => $config['password'] ?: null,
            'privateKey' => $config['private_key'] ?: null,
            'passphrase' => $config['passphrase'] ?: null,
            'root' => $config['root'],
            'timeout' => $config['timeout'],
        ], fn ($value) => $value !== null));

        $stream = fopen($path, 'rb');

        try {
            $disk->writeStream($name, $stream);
        } catch (\Throwable $e) {
            throw new RuntimeException('The backup was made on this server, but the copy to '.$config['host'].' failed: '.$e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $config['host'].':'.$config['root'].'/'.$name;
    }

    public static function megabytes(int|float $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
