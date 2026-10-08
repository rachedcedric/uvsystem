<?php

namespace App\Services;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

class TenantDocumentBranding
{
    /** Côté maximal (px) d'un logo enregistré ou intégré aux PDF : DomPDF est très gourmand en mémoire (hébergement 128 Mo). */
    public const LOGO_MAX_SIDE = 320;

    /** Au-delà de cette taille (octets), un logo est réduit à la volée avant d'être intégré à un PDF (anciens envois). */
    private const LOGO_EMBED_LIMIT = 120_000;

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

        $binary = $disk->get($path);

        if (strlen($binary) > self::LOGO_EMBED_LIMIT) {
            $compact = $this->compactLogo($binary);

            if ($compact !== null) {
                [$mime, $binary] = $compact;
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * Réduit un logo (PNG, JPEG ou WebP) à LOGO_MAX_SIDE px au plus, en gardant la transparence et le rapport.
     *
     * @return array{0: string, 1: string}|null [mime, contenu], null si l'image est illisible
     */
    public function compactLogo(string $binary): ?array
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        [$width, $height] = $info;

        // Refuse de décoder une image démesurée (le décodage GD occupe ~4 octets par pixel).
        if ($width * $height > 6_250_000) {
            return null;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return null;
        }

        $ratio = min(1, self::LOGO_MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        match ($info['mime']) {
            'image/jpeg' => imagejpeg($canvas, null, 85),
            'image/webp' => imagewebp($canvas, null, 85),
            default => imagepng($canvas, null, 9),
        };
        $output = (string) ob_get_clean();

        return [$info['mime'], $output];
    }

    /** Remplace, sur le disque, un logo envoyé par sa version réduite (même chemin, même format). */
    public function normalizeStoredLogo(Tenant $tenant, string $path): void
    {
        $disk = Storage::disk('public');

        if (! $this->isTenantLogoPath($tenant, $path) || ! $disk->exists($path)) {
            return;
        }

        $compact = $this->compactLogo($disk->get($path));

        if ($compact !== null) {
            $disk->put($path, $compact[1]);
        }
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
