<?php

namespace App\Filament\Platform\Pages\Cms;

use App\Cms\ContentRegistry;

class PublicPagesContent extends CmsSectionPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Pages publiques';

    protected static ?string $title = 'Pages publiques (offres et inscription)';

    protected static ?string $slug = 'cms/pages-publiques';

    protected static ?int $navigationSort = 2;

    protected static function section(): string
    {
        return ContentRegistry::SECTION_PUBLIC;
    }

    protected static function previewUrl(): ?string
    {
        return url('/offres');
    }
}
