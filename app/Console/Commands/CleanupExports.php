<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupExports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exports:cleanup
                            {--days=1 : Supprimer les fichiers plus vieux que X jours}
                            {--dry-run : Afficher les fichiers à supprimer sans les supprimer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nettoyer les anciens fichiers d\'export (Excel, PDF) du dossier storage/exports';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("🧹 Nettoyage des exports plus vieux que {$days} jour(s)...");
        $this->info("   Date limite: {$cutoffDate->format('d/m/Y H:i')}");

        if ($dryRun) {
            $this->warn('   Mode simulation (--dry-run)');
        }

        $this->newLine();

        $disk = Storage::disk('local');
        // Exports par tenant (tenants/{id}/exports/*) + anciens exports à plat (avant le multi-tenant).
        $files = collect($disk->allFiles('tenants'))
            ->filter(fn ($file) => str_contains($file, '/exports/'))
            ->merge($disk->files('exports'))
            ->values()
            ->all();

        $deletedCount = 0;
        $deletedSize = 0;
        $skippedCount = 0;

        if (empty($files)) {
            $this->info("✅ Aucun fichier d'export trouvé.");

            return Command::SUCCESS;
        }

        $this->table(
            ['Fichier', 'Taille', 'Date', 'Action'],
            collect($files)->map(function ($file) use ($disk, $cutoffDate, $dryRun, &$deletedCount, &$deletedSize, &$skippedCount) {
                $lastModified = Carbon::createFromTimestamp($disk->lastModified($file));
                $size = $disk->size($file);
                $sizeFormatted = $this->formatBytes($size);

                if ($lastModified->lt($cutoffDate)) {
                    if (! $dryRun) {
                        $disk->delete($file);
                    }
                    $deletedCount++;
                    $deletedSize += $size;
                    $action = $dryRun ? '🔍 À supprimer' : '🗑️ Supprimé';
                } else {
                    $skippedCount++;
                    $action = '✅ Conservé';
                }

                return [
                    basename($file),
                    $sizeFormatted,
                    $lastModified->format('d/m/Y H:i'),
                    $action,
                ];
            })->toArray()
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('📊 Résumé (simulation):');
            $this->info("   - Fichiers à supprimer: {$deletedCount} ({$this->formatBytes($deletedSize)})");
            $this->info("   - Fichiers conservés: {$skippedCount}");
            $this->warn('   Exécutez sans --dry-run pour supprimer réellement.');
        } else {
            $this->info('📊 Résumé:');
            $this->info("   - Fichiers supprimés: {$deletedCount} ({$this->formatBytes($deletedSize)})");
            $this->info("   - Fichiers conservés: {$skippedCount}");
        }

        // Log l'action
        if ($deletedCount > 0 && ! $dryRun) {
            activity()
                ->withProperties([
                    'deleted_count' => $deletedCount,
                    'deleted_size' => $deletedSize,
                    'days_threshold' => $days,
                ])
                ->log("Nettoyage exports: {$deletedCount} fichiers supprimés ({$this->formatBytes($deletedSize)})");
        }

        return Command::SUCCESS;
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
