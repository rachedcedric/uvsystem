<?php

namespace App\Services;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

class TenantDocumentBranding
{
    /**
     * @return array{
     *     name: string,
     *     legal_name: string|null,
     *     contact_email: string|null,
     *     contact_phone: string|null,
     *     address: string|null,
     *     city: string|null,
     *     country: string|null,
     *     website: string|null,
     *     document_footer: string|null,
     *     logo_data_uri: string|null
     * }|null
     */
    public function current(): ?array
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            return null;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            return null;
        }

        return [
            'name' => $tenant->name,
            'legal_name' => data_get($tenant->settings, 'profile.legal_name'),
            'contact_email' => $tenant->contact_email,
            'contact_phone' => $tenant->contact_phone,
            'address' => data_get($tenant->settings, 'profile.address'),
            'city' => data_get($tenant->settings, 'profile.city'),
            'country' => data_get($tenant->settings, 'profile.country'),
            'website' => data_get($tenant->settings, 'profile.website'),
            'document_footer' => data_get($tenant->settings, 'profile.document_footer'),
            'logo_data_uri' => $this->logoDataUri($tenant),
        ];
    }

    private function logoDataUri(Tenant $tenant): ?string
    {
        $path = $this->storedLogoPath($tenant);
        $disk = Storage::disk('public');

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    public function storedLogoPath(Tenant $tenant): ?string
    {
        $path = data_get($tenant->settings, 'branding.logo_path');

        return is_string($path) && $this->isTenantLogoPath($tenant, $path) ? $path : null;
    }

    public function isTenantLogoPath(Tenant $tenant, string $path): bool
    {
        $filename = str($path)->after($tenant->storagePath('branding/'))->value();

        return $path === $tenant->storagePath('branding/'.$filename)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $filename) === 1;
    }
}
