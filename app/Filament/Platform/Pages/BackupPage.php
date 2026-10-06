<?php

namespace App\Filament\Platform\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackupPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $navigationLabel = 'Sauvegardes';

    protected static ?string $navigationGroup = 'Système';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.backup-page';

    public array $backups = [];

    public bool $isRunning = false;

    public function mount(): void
    {
        $this->loadBackups();
    }

    public function loadBackups(): void
    {
        $this->backups = [];
        $disk = Storage::disk('local');

        $backupPath = config('app.name', 'uv-system');

        if ($disk->exists($backupPath)) {
            $files = $disk->files($backupPath);

            foreach ($files as $file) {
                if (Str::endsWith($file, '.zip')) {
                    $this->backups[] = [
                        'name' => basename($file),
                        'path' => $file,
                        'size' => $this->formatBytes($disk->size($file)),
                        'date' => date('d/m/Y H:i:s', $disk->lastModified($file)),
                        'timestamp' => $disk->lastModified($file),
                    ];
                }
            }

            // Sort by date descending
            usort($this->backups, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        }
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }

    public function runFullBackup(): void
    {
        $this->isRunning = true;

        try {
            Artisan::call('backup:run');

            Notification::make()
                ->title('Backup complet lancé')
                ->body('La sauvegarde complète est en cours. Rafraîchissez la page dans quelques instants.')
                ->success()
                ->send();

            activity()
                ->causedBy(Auth::user())
                ->withProperties(['type' => 'full'])
                ->log('Backup complet lancé manuellement');

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors du backup')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->isRunning = false;
        $this->loadBackups();
    }

    public function runDbBackup(): void
    {
        $this->isRunning = true;

        try {
            Artisan::call('backup:run', ['--only-db' => true]);

            Notification::make()
                ->title('Backup base de données lancé')
                ->body('La sauvegarde de la base de données est en cours.')
                ->success()
                ->send();

            activity()
                ->causedBy(Auth::user())
                ->withProperties(['type' => 'database'])
                ->log('Backup base de données lancé manuellement');

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors du backup')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->isRunning = false;
        $this->loadBackups();
    }

    /**
     * Les méthodes Livewire reçoivent leurs arguments du navigateur : on ne garde que le nom de fichier
     * et on le replace dans le dossier de sauvegarde, sans quoi on pourrait viser n'importe quel fichier.
     */
    private function backupPath(string $path): string
    {
        $name = basename($path);

        return Str::endsWith($name, '.zip') ? config('app.name', 'uv-system').'/'.$name : '';
    }

    public function downloadBackup(string $path): void
    {
        $path = $this->backupPath($path);
        $disk = Storage::disk('local');

        if ($path !== '' && $disk->exists($path)) {
            activity()
                ->causedBy(Auth::user())
                ->withProperties(['file' => $path])
                ->log('Backup téléchargé');

            $this->redirect(route('backup.download', ['path' => base64_encode($path)]));
        }
    }

    public function deleteBackup(string $path): void
    {
        $path = $this->backupPath($path);
        $disk = Storage::disk('local');

        if ($path !== '' && $disk->exists($path)) {
            $disk->delete($path);

            Notification::make()
                ->title('Backup supprimé')
                ->success()
                ->send();

            activity()
                ->causedBy(Auth::user())
                ->withProperties(['file' => $path])
                ->log('Backup supprimé');

            $this->loadBackups();
        }
    }

    public function cleanOldBackups(): void
    {
        try {
            Artisan::call('backup:clean');

            Notification::make()
                ->title('Nettoyage effectué')
                ->body('Les anciennes sauvegardes ont été nettoyées selon la politique de rétention.')
                ->success()
                ->send();

            $this->loadBackups();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors du nettoyage')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        return $user && $user->isSuperAdmin(); // Les sauvegardes contiennent les données de TOUS les tenants : réservées au super admin de plateforme.
    }
}
