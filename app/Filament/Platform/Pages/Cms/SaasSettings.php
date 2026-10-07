<?php

namespace App\Filament\Platform\Pages\Cms;

use App\Cms\ContentRegistry;

class SaasSettings extends CmsSectionPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Réglages du SaaS';

    protected static ?string $title = 'Réglages du SaaS';

    protected static ?string $slug = 'cms/reglages';

    protected static ?int $navigationSort = 4;

    protected static function section(): string
    {
        return ContentRegistry::SECTION_SETTINGS;
    }
}
