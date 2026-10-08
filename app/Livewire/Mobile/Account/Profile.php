<?php

namespace App\Livewire\Mobile\Account;

use App\Models\Tenant;
use App\Services\TenantDocumentBranding;
use App\Services\TenantProfileUpdater;
use App\Tenancy\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Profil de l'entreprise (identité, coordonnées, logo, pied de page des documents), équivalent mobile de la page web.
 * Le tenant vient UNIQUEMENT de l'utilisateur connecté ; l'enregistrement passe par TenantProfileUpdater, le même
 * code que le panneau web (même dossier de logo, même réduction d'image, même journal).
 */
#[Layout('components.layouts.mobile')]
#[Title('Profil de l’entreprise')]
class Profile extends Component
{
    use WithFileUploads;

    public string $name = '';

    public ?string $legal_name = null;

    public ?string $contact_name = null;

    public ?string $contact_email = null;

    public ?string $contact_phone = null;

    public ?string $website = null;

    public ?string $address = null;

    public ?string $city = null;

    public ?string $country = null;

    public ?string $document_footer = null;

    /** Fichier choisi (pas encore enregistré). */
    public $logo = null;

    public bool $removeLogo = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view_system_settings'), 403);

        $tenant = $this->tenant();
        $profile = (array) data_get($tenant->settings, 'profile', []);

        $this->name = (string) $tenant->name;
        $this->contact_name = $tenant->contact_name;
        $this->contact_email = $tenant->contact_email;
        $this->contact_phone = $tenant->contact_phone;
        $this->legal_name = $profile['legal_name'] ?? null;
        $this->website = $profile['website'] ?? null;
        $this->address = $profile['address'] ?? null;
        $this->city = $profile['city'] ?? null;
        $this->country = $profile['country'] ?? 'Côte d’Ivoire';
        $this->document_footer = $profile['document_footer'] ?? null;
    }

    public function removeLogoChoice(): void
    {
        $this->logo = null;
        $this->removeLogo = true;
    }

    public function updatedLogo(): void
    {
        $this->removeLogo = false;
    }

    public function save()
    {
        abort_unless(auth()->user()?->can('edit_system_settings'), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'document_footer' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2500,max_height=2500'],
        ], [
            'name.required' => 'Le nom de l’entreprise est obligatoire.',
            'contact_email.email' => 'Saisissez une adresse e-mail valide.',
            'website.url' => 'Saisissez une adresse complète, par exemple https://exemple.ci.',
            'logo.mimes' => 'Le logo doit être une image PNG, JPG ou WebP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
            'logo.dimensions' => 'Le logo ne doit pas dépasser 2 500 px de côté.',
        ]);

        $tenant = $this->tenant();
        $updater = app(TenantProfileUpdater::class);

        $logoPath = match (true) {
            $this->logo !== null => $updater->storeUploadedLogo($tenant, $this->logo),
            $this->removeLogo => null,
            default => app(TenantDocumentBranding::class)->storedLogoPath($tenant),
        };

        $updater->update($tenant, [
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'document_footer' => $this->document_footer,
            'logo_path' => $logoPath,
        ]);

        session()->flash('m-toast', 'Profil de l’entreprise enregistré.');

        return $this->redirectRoute('m.more', navigate: true);
    }

    public function render()
    {
        $tenant = $this->tenant();

        return view('livewire.mobile.account.profile', [
            'canEdit' => (bool) auth()->user()?->can('edit_system_settings'),
            'hasLogo' => ! $this->removeLogo && app(TenantDocumentBranding::class)->storedLogoPath($tenant) !== null,
            'logoVersion' => $tenant->updated_at?->timestamp,
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = auth()->user()?->tenant;

        abort_unless($tenant !== null && (int) $tenant->getKey() === TenantContext::requireId(), 403);

        return $tenant;
    }
}
