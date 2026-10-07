<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantDocumentBranding;
use App\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class TenantBrandingLogoController extends Controller
{
    public function __invoke(TenantDocumentBranding $branding): Response
    {
        $tenantId = TenantContext::requireId();
        $tenant = Tenant::query()->find($tenantId);

        abort_unless($tenant && (int) auth()->user()?->tenant_id === $tenantId, 403);

        $path = $branding->storedLogoPath($tenant);
        $disk = Storage::disk('public');

        abort_unless($path !== null && $disk->exists($path), 404);

        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
