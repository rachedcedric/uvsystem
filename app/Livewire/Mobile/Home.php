<?php

namespace App\Livewire\Mobile;

use App\Services\Mobile\HomeDashboard;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Accueil')]
class Home extends Component
{
    public function render()
    {
        $user = auth()->user();
        $dashboard = app(HomeDashboard::class);

        $hour = (int) now()->format('G');

        return view('livewire.mobile.home', [
            'greeting' => $hour >= 18 || $hour < 4 ? 'Bonsoir' : 'Bonjour',
            'firstName' => Str($user->name)->before(' ')->toString(),
            'unread' => Route::has('m.notifications') ? $user->unreadNotifications()->count() : 0,
            'solde' => $dashboard->solde(),
            'kpis' => $dashboard->kpis(),
            'kpiLinks' => $this->kpiLinks($user),
        ]);
    }

    /**
     * Page ouverte au toucher de chaque carte du bandeau (seulement si l'utilisateur y a accès).
     *
     * @return array<string, string>
     */
    private function kpiLinks($user): array
    {
        $targets = [
            'ventes' => ['m.ventes', 'view_uv_transactions'],
            'commission' => ['m.commissions', 'view_commissions'],
            'rembs' => ['m.remboursements', 'view_remboursements'],
            'dette' => ['m.dettes', 'view_remboursements'],
            'appro' => ['m.approvisionnements', 'view_approvisionnements'],
        ];

        $links = [];
        foreach ($targets as $key => [$route, $permission]) {
            if (Route::has($route) && $user->can($permission)) {
                $links[$key] = route($route);
            }
        }

        return $links;
    }
}
