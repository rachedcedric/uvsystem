<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class FilamentNotificationService
{
    /**
     * Notification de succès d'export
     */
    public static function exportSuccess(string $filename, string $type = 'Excel'): void
    {
        Notification::make()
            ->title('Export réussi ! 📥')
            ->body("Le fichier {$type} a été généré avec succès.")
            ->success()
            ->icon('heroicon-o-document-arrow-down')
            ->duration(5000)
            ->actions([
                Action::make('download')
                    ->label('Télécharger')
                    ->url(route('exports.download', ['filename' => $filename]))
                    ->openUrlInNewTab(),
            ])
            ->send();
    }

    /**
     * Notification d'alerte de dette
     */
    public static function debtAlert(Agent $agent, string $level = 'warning'): void
    {
        $ratio = $agent->debt_limit > 0
            ? round(($agent->current_debt / $agent->debt_limit) * 100, 1)
            : 0;

        $config = match ($level) {
            'critical' => [
                'title' => '🚨 ALERTE CRITIQUE',
                'color' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            'high' => [
                'title' => '⚠️ Alerte haute',
                'color' => 'warning',
                'icon' => 'heroicon-o-exclamation-circle',
            ],
            default => [
                'title' => '📊 Alerte dette',
                'color' => 'warning',
                'icon' => 'heroicon-o-chart-bar',
            ],
        };

        $debt = number_format($agent->current_debt, 0, ',', ' ');

        Notification::make()
            ->title("{$config['title']} - {$agent->name}")
            ->body("Dette: {$debt} FCFA ({$ratio}% du plafond)")
            ->color($config['color'])
            ->icon($config['icon'])
            ->persistent()
            ->actions([
                Action::make('view')
                    ->label('Voir agent')
                    ->url("/admin/agents/{$agent->id}")
                    ->color('primary'),
                Action::make('reminder')
                    ->label('Envoyer rappel')
                    ->url("/admin/agents/{$agent->id}/edit?action=reminder")
                    ->color('warning'),
            ])
            ->sendToDatabase(Auth::user());
    }

    /**
     * Notification de transaction créée
     */
    public static function transactionCreated(Agent $agent, float $amount): void
    {
        $amountFormatted = number_format($amount, 0, ',', ' ');

        Notification::make()
            ->title('Transaction enregistrée ✅')
            ->body("{$amountFormatted} FCFA distribués à {$agent->name}")
            ->success()
            ->icon('heroicon-o-arrow-right-circle')
            ->duration(4000)
            ->send();
    }

    /**
     * Notification de remboursement reçu
     */
    public static function remboursementReceived(Agent $agent, float $amount): void
    {
        $amountFormatted = number_format($amount, 0, ',', ' ');

        Notification::make()
            ->title('Remboursement reçu 💰')
            ->body("{$amountFormatted} FCFA reçus de {$agent->name}")
            ->success()
            ->icon('heroicon-o-banknotes')
            ->duration(4000)
            ->send();
    }

    /**
     * Notification de rappel envoyé
     */
    public static function reminderSent(Agent $agent, string $channel = 'sms'): void
    {
        $channelLabel = match ($channel) {
            'sms' => 'SMS',
            'email' => 'Email',
            'whatsapp' => 'WhatsApp',
            default => $channel,
        };

        Notification::make()
            ->title("Rappel {$channelLabel} envoyé 📤")
            ->body("Un rappel a été envoyé à {$agent->name} ({$agent->phone})")
            ->success()
            ->icon('heroicon-o-paper-airplane')
            ->duration(4000)
            ->send();
    }

    /**
     * Notification d'erreur
     */
    public static function error(string $title, string $message): void
    {
        Notification::make()
            ->title("❌ {$title}")
            ->body($message)
            ->danger()
            ->icon('heroicon-o-x-circle')
            ->persistent()
            ->send();
    }

    /**
     * Notification d'information
     */
    public static function info(string $title, string $message, ?string $actionUrl = null, ?string $actionLabel = null): void
    {
        $notification = Notification::make()
            ->title("ℹ️ {$title}")
            ->body($message)
            ->info()
            ->icon('heroicon-o-information-circle')
            ->duration(5000);

        if ($actionUrl && $actionLabel) {
            $notification->actions([
                Action::make('action')
                    ->label($actionLabel)
                    ->url($actionUrl),
            ]);
        }

        $notification->send();
    }

    /**
     * Notification de bienvenue
     */
    public static function welcome(User $user): void
    {
        Notification::make()
            ->title("Bienvenue, {$user->name}! 👋")
            ->body('Vous êtes connecté au système de gestion UV.')
            ->success()
            ->icon('heroicon-o-sparkles')
            ->duration(5000)
            ->send();
    }

    /**
     * Notification de rapport généré
     */
    public static function reportGenerated(string $reportType, ?string $downloadUrl = null): void
    {
        $notification = Notification::make()
            ->title('Rapport généré 📊')
            ->body("Le rapport {$reportType} est prêt.")
            ->success()
            ->icon('heroicon-o-document-chart-bar')
            ->duration(6000);

        if ($downloadUrl) {
            $notification->actions([
                Action::make('download')
                    ->label('Télécharger')
                    ->url($downloadUrl)
                    ->openUrlInNewTab(),
            ]);
        }

        $notification->send();
    }

    /**
     * Notification de backup
     */
    public static function backupCompleted(string $filename, string $size): void
    {
        Notification::make()
            ->title('Sauvegarde terminée ✅')
            ->body("Fichier: {$filename} ({$size})")
            ->success()
            ->icon('heroicon-o-cloud-arrow-up')
            ->duration(5000)
            ->send();
    }

    /**
     * Notification système (persistante en base)
     */
    public static function system(string $title, string $message, string $type = 'info', ?User $recipient = null): void
    {
        $user = $recipient ?? Auth::user();

        if (! $user) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($message)
            ->icon(match ($type) {
                'success' => 'heroicon-o-check-circle',
                'warning' => 'heroicon-o-exclamation-triangle',
                'danger' => 'heroicon-o-x-circle',
                default => 'heroicon-o-bell',
            });

        match ($type) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification->sendToDatabase($user);
    }

    /**
     * Broadcast notification à tous les admins
     */
    public static function broadcastToAdmins(string $title, string $message, string $type = 'info'): void
    {
        // Rôles Spatie (la colonne users.role n'existe pas) ; limité au tenant courant par le scope de User.
        $admins = User::role(['master', 'admin'])->get();

        foreach ($admins as $admin) {
            self::system($title, $message, $type, $admin);
        }
    }
}
