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
use App\Models\User;
use App\Services\TenantDocumentBranding;
use App\Support\Mobile\Crud\Registry;
use App\Support\Mobile\Nav;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Plus')]
class More extends Component
{
    #[Url(except: '')]
    public string $search = '';

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

    private function matchesSearch(string $value): bool
    {
        $needle = Str::lower(Str::ascii(trim($this->search)));

        return $needle === '' || Str::contains(Str::lower(Str::ascii($value)), $needle);
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();

        $company = $user->tenant;
        $hasLogo = $company && app(TenantDocumentBranding::class)->storedLogoPath($company) !== null;

        $native = Nav::moreItems($user);
        $resourceGroups = Registry::menuFor($user);
        $groups = $this->fullVersionGroups();
        $searchActive = trim($this->search) !== '';

        if ($searchActive) {
            $native = array_values(array_filter($native, fn (array $item): bool => $this->matchesSearch($item['label'])));
            $resourceGroups = collect($resourceGroups)
                ->map(fn (array $resources): array => array_values(array_filter(
                    $resources,
                    fn (string $resource): bool => $this->matchesSearch($resource::plural()),
                )))
                ->filter()
                ->all();
            $groups = collect($groups)
                ->map(function (array $group): array {
                    if ($this->matchesSearch($group['label'])) {
                        return $group;
                    }

                    $group['items'] = array_values(array_filter(
                        $group['items'],
                        fn (array $item): bool => $this->matchesSearch($item['label']),
                    ));

                    return $group;
                })
                ->filter(fn (array $group): bool => $group['items'] !== [])
                ->values()
                ->all();
        }

        $resultCount = count($native)
            + collect($resourceGroups)->sum(fn (array $resources): int => count($resources))
            + collect($groups)->sum(fn (array $group): int => count($group['items']));

        return view('livewire.mobile.more', [
            'user' => $user,
            'company' => $company,
            'companyLogo' => $hasLogo ? route('tenant.branding.logo', ['v' => $company->updated_at?->timestamp], false) : null,
            'role' => $user->getRoleNames()->first(),
            'native' => $native,
            'resourceGroups' => $resourceGroups,
            'groups' => $groups,
            'searchActive' => $searchActive,
            'resultCount' => $resultCount,
        ]);
    }
}
