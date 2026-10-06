<?php

namespace App\Livewire\Mobile;

use Filament\Facades\Filament;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Plus')]
class More extends Component
{
    /**
     * Rubriques du panneau complet (Filament) accessibles à l'utilisateur.
     * Sert de filet de sécurité : tout écran pas encore porté en mobile reste
     * atteignable depuis l'application.
     *
     * @return list<array{label:string, items:list<array{label:string,url:string}>}>
     */
    private function fullVersionGroups(): array
    {
        try {
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            // Écrans déjà disponibles en natif : inutile de les proposer en version complète.
            $ported = [
                \App\Filament\Pages\Dashboard::getUrl(),
                \App\Filament\Resources\UvTransactionResource::getUrl('index'),
                \App\Filament\Resources\RemboursementResource::getUrl('index'),
                \App\Filament\Resources\AgentResource::getUrl('index'),
                \App\Filament\Resources\ApprovisionnementResource::getUrl('index'),
                \App\Filament\Pages\ChargesPage::getUrl(),
                \App\Filament\Pages\MaCaissePage::getUrl(),
                \App\Filament\Pages\NotificationsPage::getUrl(),
                \App\Filament\Resources\FournisseurResource::getUrl('index'),
                \App\Filament\Resources\PalierCommissionResource::getUrl('index'),
                \App\Filament\Resources\PaymentMethodResource::getUrl('index'),
                \App\Filament\Resources\ChargeCategorieResource::getUrl('index'),
                \App\Filament\Resources\UserResource::getUrl('index'),
                \App\Filament\Resources\SousDistributeurResource::getUrl('index'),
                \App\Filament\Resources\AgentPartenaireResource::getUrl('index'),
                \App\Filament\Resources\CaisseCategorieResource::getUrl('index'),
                \App\Filament\Resources\CommissionResource::getUrl('index'),
                \App\Filament\Resources\ActivityLogResource::getUrl('index'),
                \App\Filament\Resources\RemboursementFournisseurResource::getUrl('index'),
                \App\Filament\Pages\DettesPage::getUrl(),
                \App\Filament\Pages\DettesFournisseursPage::getUrl(),
                \App\Filament\Pages\StatisticsPage::getUrl(),
                \App\Filament\Pages\RapportStatistiques::getUrl(),
                \App\Filament\Pages\AgentScoringPage::getUrl(),
                \App\Filament\Pages\ReportsPage::getUrl(),
                \App\Filament\Pages\ImportPage::getUrl(),
                \App\Filament\Pages\BackupPage::getUrl(),
                \App\Filament\Pages\TwoFactorAuthPage::getUrl(),
                \App\Filament\Pages\ActivityLogPage::getUrl(),
            ];

            return collect(Filament::getNavigation())
                ->map(fn ($group): array => [
                    'label' => $group->getLabel() ?? 'Général',
                    'items' => collect($group->getItems())
                        ->map(fn ($item): array => ['label' => (string) $item->getLabel(), 'url' => (string) $item->getUrl()])
                        ->filter(fn (array $i): bool => $i['url'] !== '' && ! in_array($i['url'], $ported, true))
                        ->values()
                        ->all(),
                ])
                ->filter(fn (array $g): bool => $g['items'] !== [])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.mobile.more', [
            'user' => $user,
            'role' => $user->getRoleNames()->first(),
            'native' => \App\Support\Mobile\Nav::moreItems($user),
            'resourceGroups' => \App\Support\Mobile\Crud\Registry::menuFor($user),
            'groups' => $this->fullVersionGroups(),
        ]);
    }
}
