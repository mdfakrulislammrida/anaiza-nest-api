<?php

namespace App\Filament\Pages;

use App\Support\Backup\BackupService;
use App\Support\Dhaka;
use App\Support\System\HealthChecks;
use App\Support\System\ShopAlerts;
use App\Support\System\SystemEvents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One page that says whether the shop's machinery is working: the scheduled jobs, the backup, the disk, the database, email.
 * Backups made on the server are listed here and can be downloaded.
 */
class SystemHealth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $title = 'System health';

    protected static string $view = 'filament.pages.system-health';

    protected static ?string $slug = 'system-health';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('system.manage') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backUpNow')
                ->label('Back up now')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->requiresConfirmation()
                ->modalHeading('Make a backup now?')
                ->modalDescription('A copy of the database and the uploaded pictures is made on the server. The shop keeps running while it is made.')
                ->action(function (): void {
                    $this->makeBackup();
                }),
        ];
    }

    public function makeBackup(): void
    {
        try {
            $made = (new BackupService)->run('manual');
            SystemEvents::mark('backup', $made['name'].', '.BackupService::megabytes($made['size']), 'ok');

            Notification::make()->success()->title('Backup made')->body($made['name'].' ('.BackupService::megabytes($made['size']).')')->send();
        } catch (\Throwable $e) {
            SystemEvents::mark('backup', $e->getMessage(), 'error');
            SystemEvents::record('backup_failed', $e->getMessage());
            ShopAlerts::backupFailed($e->getMessage());

            Notification::make()->danger()->title('The backup did not finish')->body($e->getMessage())->persistent()->send();
        }
    }

    public function download(string $name): ?BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        $path = (new BackupService)->pathFor($name);

        if ($path === null) {
            Notification::make()->danger()->title('That backup is no longer on the server')->send();

            return null;
        }

        return response()->download($path, $name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $checks = HealthChecks::all();

        return [
            'checks' => $checks,
            'overall' => HealthChecks::overall($checks),
            'backups' => array_map(fn (array $backup): array => $backup + [
                'when' => Dhaka::readable(Carbon::createFromTimestamp($backup['modified'])),
                'sizeLabel' => BackupService::megabytes($backup['size']),
            ], (new BackupService)->all()),
        ];
    }
}
