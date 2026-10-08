<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Services\TenantDocumentBranding;
use App\Services\TenantProfileUpdater;
use App\Tenancy\TenantContext;
use Filament\Forms;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TenantProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Profil de l’entreprise';

    protected static ?string $title = 'Profil de l’entreprise';

    protected static ?string $navigationGroup = 'Mon Compte';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'profil-entreprise';

    protected static string $view = 'filament.pages.tenant-profile-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_system_settings') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $tenant = $this->tenant();
        $settings = $tenant->settings ?? [];
        $profile = (array) ($settings['profile'] ?? []);
        $branding = (array) ($settings['branding'] ?? []);

        $this->form->fill([
            'name' => $tenant->name,
            'contact_name' => $tenant->contact_name,
            'contact_email' => $tenant->contact_email,
            'contact_phone' => $tenant->contact_phone,
            'legal_name' => $profile['legal_name'] ?? null,
            'address' => $profile['address'] ?? null,
            'city' => $profile['city'] ?? null,
            'country' => $profile['country'] ?? 'Côte d’Ivoire',
            'website' => $profile['website'] ?? null,
            'document_footer' => $profile['document_footer'] ?? null,
            'logo_path' => $branding['logo_path'] ?? null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identité de l’entreprise')
                ->description('Ces informations identifient votre espace de travail et votre société.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nom affiché')->required()->maxLength(255),
                    Forms\Components\TextInput::make('legal_name')->label('Raison sociale')->maxLength(255),
                    Forms\Components\FileUpload::make('logo_path')
                        ->label('Logo')
                        ->disk('public')
                        ->directory(fn () => TenantContext::storagePath('branding'))
                        ->visibility('public')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => $this->uploadedLogo($component, $file, $storedFileNames))
                        ->imageEditor()
                        ->maxSize(2048)
                        ->rules(['dimensions:max_width=2500,max_height=2500'])
                        ->helperText('PNG, JPG ou WebP, 2 Mo et 2 500 px maximum (il est réduit automatiquement). Le logo apparaît dans le panneau et sur vos documents.')
                        ->columnSpanFull(),
                ])->columns(2),
            Forms\Components\Section::make('Coordonnées')
                ->schema([
                    Forms\Components\TextInput::make('contact_name')->label('Responsable')->maxLength(255),
                    Forms\Components\TextInput::make('contact_email')->label('E-mail')->email()->maxLength(255),
                    Forms\Components\TextInput::make('contact_phone')->label('Téléphone')->tel()->maxLength(30),
                    Forms\Components\TextInput::make('website')->label('Site web')->url()->maxLength(255),
                    Forms\Components\Textarea::make('address')->label('Adresse')->rows(2)->maxLength(500)->columnSpanFull(),
                    Forms\Components\TextInput::make('city')->label('Ville')->maxLength(120),
                    Forms\Components\TextInput::make('country')->label('Pays')->maxLength(120),
                ])->columns(2),
            Forms\Components\Section::make('Documents')
                ->description('Texte de contact ou mention légale à afficher sur les documents personnalisés.')
                ->schema([
                    Forms\Components\Textarea::make('document_footer')
                        ->label('Pied de page')
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('Exemple : adresse, téléphone et informations légales.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('edit_system_settings'), 403);

        app(TenantProfileUpdater::class)->update($this->tenant(), $this->form->getState());

        Notification::make()->title('Profil enregistré')->body('Les informations de votre entreprise sont à jour.')->success()->send();
    }

    private function tenant(): Tenant
    {
        $tenant = auth()->user()?->tenant;

        abort_unless($tenant !== null && (int) $tenant->getKey() === TenantContext::requireId(), 403);

        return $tenant;
    }

    /** @return array{name: string, size: int, type: string|null, url: string}|null */
    private function uploadedLogo(BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array
    {
        $tenant = $this->tenant();
        $disk = $component->getDisk();

        if (! app(TenantDocumentBranding::class)->isTenantLogoPath($tenant, $file) || ! $disk->exists($file)) {
            return null;
        }

        return [
            'name' => is_string($storedFileNames) ? $storedFileNames : basename($file),
            'size' => $disk->size($file),
            'type' => $disk->mimeType($file),
            'url' => route('tenant.branding.logo', ['v' => $disk->lastModified($file)]),
        ];
    }
}
