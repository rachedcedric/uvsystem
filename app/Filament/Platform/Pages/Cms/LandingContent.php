<?php

namespace App\Filament\Platform\Pages\Cms;

use App\Cms\ContentRegistry;

class LandingContent extends CmsSectionPage
{
    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationLabel = 'Page d\'accueil';

    protected static ?string $title = 'Page d\'accueil';

    protected static ?string $slug = 'cms/page-d-accueil';

    protected static ?int $navigationSort = 1;

    protected static function section(): string
    {
        return ContentRegistry::SECTION_LANDING;
    }

    protected static function previewUrl(): ?string
    {
        return url('/');
    }
}
