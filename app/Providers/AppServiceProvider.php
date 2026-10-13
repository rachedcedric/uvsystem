<?php

namespace App\Providers;

use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\MobileAccess;
use App\Models\Approvisionnement;
use App\Models\Remboursement;
use App\Models\RemboursementAgent;
use App\Models\UvTransaction;
use App\Models\VenteUv;
use App\Observers\ApprovisionnementObserver;
use App\Observers\CommissionObserver;
use App\Observers\CommissionRecalculationObserver;
use App\Observers\LedgerCascadeObserver;
use App\Observers\RemboursementAgentObserver;
use App\Observers\RemboursementObserver;
use App\Observers\UvTransactionObserver;
use App\Observers\UvTransactionSecurityObserver;
use App\Observers\VenteUvObserver;
use App\Services\Subscriptions\SubscriptionNotifier;
use App\Support\Money;
use App\Support\Subscriptions\GatedExcel;
use App\Support\Subscriptions\GatedPdf;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Maatwebsite\Excel\Files\Filesystem;
use Maatwebsite\Excel\QueuedWriter;
use Maatwebsite\Excel\Reader;
use Maatwebsite\Excel\Writer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Helpers du CMS : cms(), cms_html(), cms_lines().
        require_once app_path('Cms/helpers.php');

        // Un seul notifieur par processus : il cumule les échecs d'envoi rapportés par subscriptions:process.
        $this->app->singleton(SubscriptionNotifier::class);

        // Toute génération de PDF / Excel passe par les fonctionnalités d'export de l'offre (voir ExportGate).
        $this->app->extend('dompdf.wrapper', fn ($pdf, $app) => new GatedPdf($app['dompdf'], $app['config'], $app['files'], $app['view']));
        $this->app->extend('excel', fn ($excel, $app) => new GatedExcel(
            $app->make(Writer::class),
            $app->make(QueuedWriter::class),
            $app->make(Reader::class),
            $app->make(Filesystem::class),
        ));

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enregistrer les observers pour les transactions UV
        UvTransaction::observe(UvTransactionObserver::class);
        UvTransaction::observe(UvTransactionSecurityObserver::class);
        // Détecte les ventes ajoutées/modifiées/supprimées après la clôture
        // d'une journée de commission et déclenche sa correction
        UvTransaction::observe(CommissionRecalculationObserver::class);

        // Reconstruit le grand livre partagé (solde_uv_avant/apres) quand
        // une vente/retour ou un approvisionnement passé est édité,
        // supprimé, restauré, ou créé avec une date antidatée.
        UvTransaction::observe(LedgerCascadeObserver::class);
        Approvisionnement::observe(LedgerCascadeObserver::class);

        // Observer pour mise à jour automatique du solde Master
        Approvisionnement::observe(ApprovisionnementObserver::class);

        // Observer pour les remboursements
        Remboursement::observe(RemboursementObserver::class);

        // NOTE: Observers désactivés car modèles obsolètes (utiliser UvTransaction et Remboursement)
        // VenteUv::observe(VenteUvObserver::class);
        // RemboursementAgent::observe(RemboursementAgentObserver::class);

        // NOTE: CommissionObserver désactivé - Les commissions sont calculées
        // sur le TOTAL JOURNALIER à 18h02 via la commande "php artisan commissions:calculer"
        // et non pas par vente individuelle (CommissionRecalculationObserver
        // ci-dessus ne fait que détecter les ventes tardives sur une
        // journée déjà clôturée, il ne crée jamais de commission par vente)
        // VenteUv::observe(CommissionObserver::class);

        // Les actions Livewire (/livewire/update) ne repassent pas par les middlewares de route :
        // sans ceci, un tenant suspendu garderait la main sur une page /m déjà ouverte.
        Livewire::addPersistentMiddleware([MobileAccess::class, EnforceTwoFactor::class]);

        if (! config('app.debug')) {
            Livewire::setScriptRoute(fn ($handle) => Route::get('/livewire/livewire.min.js', $handle));
        }

        $this->configureRateLimiting();
        $this->registerMoneyInputMacro();
    }

    /**
     * Champ de saisie de montant réutilisable pour tous les formulaires
     * Filament de l'app : TextInput::make('amount')->money().
     *
     * - Affichage : espace comme séparateur de milliers, mis à jour en
     *   temps réel pendant la saisie (masque Alpine $money bundlé avec
     *   Livewire, curseur géré nativement par ce plugin — aucune logique
     *   JS maison à maintenir).
     * - Jamais type="number" : un input HTML5 numérique rejette les
     *   espaces, ce qui casserait le masque. inputmode="numeric" suffit à
     *   afficher le clavier numérique mobile.
     * - Jamais ->numeric()/->minValue()/->maxValue() natifs : leurs règles
     *   Laravel ("numeric", "min:x") comparent la valeur BRUTE encore
     *   formatée (espaces) avant toute dehydration, donc "1 000 000"
     *   échouerait la validation "numeric". Ce macro fournit sa propre
     *   règle qui nettoie d'abord la saisie avec Money::toFloat().
     * - Valeur envoyée au backend : ->dehydrateStateUsing() nettoie les
     *   espaces et convertit en nombre avant que le formulaire n'atteigne
     *   le contrôleur/service — jamais "1 250 000" en base.
     * - Toute règle ou calcul réactif ($get('amount') utilisé par un AUTRE
     *   champ pendant la saisie, avant soumission) doit lire la valeur via
     *   Money::toFloat($get('amount')), jamais un simple (float) cast, sous
     *   peine de tronquer au premier espace.
     */
    protected function registerMoneyInputMacro(): void
    {
        TextInput::macro('money', function (string $suffix = 'FCFA', float $min = 0, ?float $max = null) {
            /** @var TextInput $this */
            return $this
                ->numeric(false)
                ->inputMode('numeric')
                ->mask(RawJs::make("\$money(\$input, '.', ' ', 0)"))
                ->suffix($suffix)
                ->dehydrateStateUsing(fn ($state) => Money::toFloat($state))
                ->rule(function () use ($min, $max) {
                    return function (string $attribute, $value, \Closure $fail) use ($min, $max) {
                        if ($value === null || $value === '') {
                            return;
                        }

                        if (! Money::isValid($value)) {
                            $fail('Le champ :attribute doit être un montant valide.');

                            return;
                        }

                        $amount = Money::toFloat($value);

                        if ($amount < $min) {
                            $fail('Le champ :attribute doit être supérieur ou égal à '.number_format($min, 0, ',', ' ').'.');

                            return;
                        }

                        if ($max !== null && $amount > $max) {
                            $fail('Le champ :attribute doit être inférieur ou égal à '.number_format($max, 0, ',', ' ').'.');
                        }
                    };
                });
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Global API rate limit: 60 requests per minute
        // Inscription publique : plafond par adresse IP, réglable (des opérateurs mobiles partagent une même IP entre nombreux abonnés).
        RateLimiter::for('signup', fn (Request $request) => Limit::perHour((int) config('subscriptions.signup.max_requests_per_ip_per_hour', 20))->by($request->ip()));

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Authentication endpoints: 5 attempts per minute (prevents brute force)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Heavy operations (exports, reports): 10 per minute
        RateLimiter::for('heavy', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Critical operations (transactions, payments): 30 per minute
        RateLimiter::for('transactions', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Admin operations: 100 per minute for master/admin users
        RateLimiter::for('admin', function (Request $request) {
            $user = $request->user();

            if ($user?->hasAnyRole(['master', 'admin'])) {
                return Limit::perMinute(100)->by($user->id);
            }

            return Limit::perMinute(30)->by($request->ip());
        });

        // Agent portal: 60 per minute
        RateLimiter::for('agent', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Dashboard/Stats endpoints: 20 per minute (cached data)
        RateLimiter::for('dashboard', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });
    }
}
