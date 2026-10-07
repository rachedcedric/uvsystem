<?php

namespace App\Filament\Platform\Pages\Cms;

use App\Cms\ContentRegistry;

class EmailContent extends CmsSectionPage
{
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'E-mails et messages';

    protected static ?string $title = 'E-mails et messages';

    protected static ?string $slug = 'cms/emails';

    protected static ?int $navigationSort = 3;

    protected static function section(): string
    {
        return ContentRegistry::SECTION_EMAILS;
    }
}
