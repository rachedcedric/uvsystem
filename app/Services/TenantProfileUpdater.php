<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Enregistrement du profil de l'entreprise, partagé par la page web (Filament) et l'écran mobile : mêmes champs,
 * mêmes règles de logo (dossier du tenant, image réduite, ancien fichier supprimé), même journal d'activité.
 */
class TenantProfileUpdater
{
    public function __construct(private readonly TenantDocumentBranding $branding) {}

    /**
     * @param  array{name: string, contact_name?: ?string, contact_email?: ?string, contact_phone?: ?string, legal_name?: ?string, address?: ?string, city?: ?string, country?: ?string, website?: ?string, document_footer?: ?string, logo_path?: ?string}  $state
     */
    public function update(Tenant $tenant, array $state): void
    {
        $oldLogo = data_get($tenant->settings, 'branding.logo_path');
        $newLogo = $state['logo_path'] ?? null;

        DB::transaction(function () use ($tenant, $state, $newLogo) {
            $settings = $tenant->settings ?? [];
            $settings['profile'] = [
                'legal_name' => $state['legal_name'] ?? null,
                'address' => $state['address'] ?? null,
                'city' => $state['city'] ?? null,
                'country' => $state['country'] ?? null,
                'website' => $state['website'] ?? null,
                'document_footer' => $state['document_footer'] ?? null,
            ];
            $settings['branding'] = ['logo_path' => $newLogo];

            $tenant->forceFill([
                'name' => $state['name'],
                'contact_name' => $state['contact_name'] ?? null,
                'contact_email' => $state['contact_email'] ?? null,
                'contact_phone' => $state['contact_phone'] ?? null,
                'settings' => $settings,
            ])->save();
        });

        if (is_string($newLogo) && $newLogo !== $oldLogo) {
            $this->branding->normalizeStoredLogo($tenant, $newLogo);
        }

        if (is_string($oldLogo) && $oldLogo !== $newLogo && $this->branding->isTenantLogoPath($tenant, $oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        activity('tenant-profile')
            ->causedBy(auth()->user())
            ->withProperties(['tenant_id' => $tenant->getKey()])
            ->log('Profil de l’entreprise mis à jour');
    }

    /** Range un logo envoyé depuis l'écran mobile dans le dossier du tenant et renvoie son chemin. */
    public function storeUploadedLogo(Tenant $tenant, UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return $file->storeAs($tenant->storagePath('branding'), Str::ulid().'.'.$extension, 'public');
    }
}
