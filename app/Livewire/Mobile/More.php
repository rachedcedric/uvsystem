<?php

namespace App\Livewire\Mobile;

use App\Filament\Pages\ActivityLogPage;
use App\Filament\Pages\AgentScoringPage;
use App\Filament\Pages\ChargesPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DettesFournisseursPage;
use App\Filament\Pages\DettesPage;
use App\Filament\Pages\ImportPage;
use App\Filament\Pages\MaCaissePage;
use App\Filament\Pages\NotificationsPage;
use App\Filament\Pages\RapportStatistiques;
use App\Filament\Pages\ReportsPage;
use App\Filament\Pages\StatisticsPage;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Pages\TenantProfilePage;
use App\Filament\Pages\TwoFactorAuthPage;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\AgentPartenaireResource;
use App\Filament\Resources\AgentResource;
use App\Filament\Resources\ApprovisionnementResource;
use App\Filament\Resources\CaisseCategorieResource;
use App\Filament\Resources\ChargeCategorieResource;
use App\Filament\Resources\CommissionResource;
use App\Filament\Resources\FournisseurResource;
use App\Filament\Resources\PalierCommissionResource;
use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Resources\RemboursementFournisseurResource;
use App\Filament\Resources\RemboursementResource;
use App\Filament\Resources\SousDistributeurResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UvTransactionResource;
use App\Services\TenantDocumentBranding;
use App\Support\Mobile\Crud\Registry;
use App\Support\Mobile\Nav;
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
                Dashboard::getUrl(),
                UvTransactionResource::getUrl('index'),
                RemboursementResource::getUrl('index'),
                AgentResource::getUrl('index'),
                ApprovisionnementResource::getUrl('index'),
                ChargesPage::getUrl(),
                MaCaissePage::getUrl(),
                NotificationsPage::getUrl(),
                FournisseurResource::getUrl('index'),
                PalierCommissionResource::getUrl('index'),
                PaymentMethodResource::getUrl('index'),
                ChargeCategorieResource::getUrl('index'),
                UserResource::getUrl('index'),
                SousDistributeurResource::getUrl('index'),
                AgentPartenaireResource::getUrl('index'),
                CaisseCategorieResource::getUrl('index'),
                CommissionResource::getUrl('index'),
                ActivityLogResource::getUrl('index'),
                RemboursementFournisseurResource::getUrl('index'),
                DettesPage::getUrl(),
                DettesFournisseursPage::getUrl(),
                StatisticsPage::getUrl(),
                RapportStatistiques::getUrl(),
                AgentScoringPage::getUrl(),
                ReportsPage::getUrl(),
                ImportPage::getUrl(),
                TwoFactorAuthPage::getUrl(),
                ActivityLogPage::getUrl(),
                SubscriptionPage::getUrl(),
                TenantProfilePage::getUrl(),
                '/agent', // « Mon espace agent » : déjà proposé en natif
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

        $company = $user->tenant;
        $hasLogo = $company && app(TenantDocumentBranding::class)->storedLogoPath($company) !== null;

        return view('livewire.mobile.more', [
            'user' => $user,
            'company' => $company,
            'companyLogo' => $hasLogo ? route('tenant.branding.logo', ['v' => $company->updated_at?->timestamp], false) : null,
            'role' => $user->getRoleNames()->first(),
            'native' => Nav::moreItems($user),
            'resourceGroups' => Registry::menuFor($user),
            'groups' => $this->fullVersionGroups(),
        ]);
    }
}
