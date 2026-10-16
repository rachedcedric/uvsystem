<?php

namespace App\Livewire\Mobile\Admin;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Sauvegardes (réservées aux rôles master et admin, comme dans l'interface complète). */
#[Layout('components.layouts.mobile')]
#[Title('Sauvegardes')]
class Backups extends Component
{
    public static function directory(): string
    {
        return config('app.name', 'UVMASTER CI');
    }

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403); // Les sauvegardes contiennent les données de TOUS les tenants : réservées au super admin de plateforme.
    }

    private function guard(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403); // Les sauvegardes contiennent les données de TOUS les tenants : réservées au super admin de plateforme.
    }

    private function resolve(string $name): ?string
    {
        $path = self::directory().'/'.basename($name);

        return Str::endsWith($path, '.zip') && Storage::disk('local')->exists($path) ? $path : null;
    }

    public function run(string $mode): void
    {
        $this->guard();

        try {
            Artisan::call('backup:run', $mode === 'db' ? ['--only-db' => true] : []);
            activity()->causedBy(auth()->user())->withProperties(['type' => $mode === 'db' ? 'database' : 'full'])
                ->log($mode === 'db' ? 'Backup base de données lancé manuellement' : 'Backup complet lancé manuellement');
            $this->dispatch('m-toast', message: 'Sauvegarde terminée.');
        } catch (\Throwable $e) {
            $this->dispatch('m-toast', message: 'Sauvegarde impossible : '.$e->getMessage(), type: 'error');
        }
    }

    public function delete(string $name): void
    {
        $this->guard();

        if ($path = $this->resolve($name)) {
            Storage::disk('local')->delete($path);
            activity()->causedBy(auth()->user())->withProperties(['file' => $path])->log('Backup supprimé');
            $this->dispatch('m-toast', message: 'Sauvegarde supprimée.');
        }
    }

    public function clean(): void
    {
        $this->guard();

        try {
            Artisan::call('backup:clean');
            $this->dispatch('m-toast', message: 'Anciennes sauvegardes nettoyées.');
        } catch (\Throwable $e) {
            $this->dispatch('m-toast', message: 'Nettoyage impossible : '.$e->getMessage(), type: 'error');
        }
    }

    public function render()
    {
        $disk = Storage::disk('local');
        $backups = [];

        if ($disk->exists(self::directory())) {
            foreach ($disk->files(self::directory()) as $file) {
                if (Str::endsWith($file, '.zip')) {
                    $backups[] = [
                        'name' => basename($file),
                        'size' => $this->formatBytes($disk->size($file)),
                        'date' => Carbon::createFromTimestamp($disk->lastModified($file)),
                    ];
                }
            }
            usort($backups, fn ($a, $b) => $b['date'] <=> $a['date']);
        }

        return view('livewire.mobile.admin.backups', ['backups' => $backups]);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $pow = min((int) floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);

        return round($bytes / (1024 ** $pow), 1).' '.$units[$pow];
    }
}
