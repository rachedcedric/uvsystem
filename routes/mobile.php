<?php

use App\Http\Controllers\Mobile\ExportController;
use App\Http\Controllers\Mobile\LogoutController;
use App\Http\Controllers\Mobile\PushTokenController;
use App\Http\Controllers\ReportExportController;
use App\Livewire\Mobile\Account\Profile;
use App\Livewire\Mobile\Admin;
use App\Livewire\Mobile\Agents;
use App\Livewire\Mobile\Analytics;
use App\Livewire\Mobile\Approvisionnements;
use App\Livewire\Mobile\Caisse;
use App\Livewire\Mobile\Charges;
use App\Livewire\Mobile\Commissions;
use App\Livewire\Mobile\Crud;
use App\Livewire\Mobile\Dettes;
use App\Livewire\Mobile\Home;
use App\Livewire\Mobile\Login;
use App\Livewire\Mobile\More;
use App\Livewire\Mobile\Notifications;
use App\Livewire\Mobile\Remboursements;
use App\Livewire\Mobile\RemboursementsFournisseurs;
use App\Livewire\Mobile\Subscription\Show as SubscriptionShow;
use App\Livewire\Mobile\Ventes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Interface mobile (APK Android) — préfixe /m
|--------------------------------------------------------------------------
| Blade + Livewire, sans Filament. Réutilise les modèles, policies et
| services existants : aucune règle métier n'est dupliquée ici.
*/

Route::prefix('m')->name('m.')->group(function () {
    Route::get('login', Login::class)->name('login');

    Route::middleware(['mobile.access', '2fa'])->group(function () {
        Route::post('push-token', [PushTokenController::class, 'store'])->name('push-token.store');
        Route::delete('push-token', [PushTokenController::class, 'destroy'])->name('push-token.destroy');
        Route::get('/', Home::class)->name('home');
        Route::get('plus', More::class)->name('more');
        Route::get('notifications', Notifications::class)->name('notifications');

        Route::get('caisse', Caisse\Index::class)->name('caisse');
        Route::get('caisse/mouvement', Caisse\Create::class)->name('caisse.create');
        Route::get('caisse/{date}', Caisse\Jour::class)->name('caisse.jour')->where('date', '\d{4}-\d{2}-\d{2}');

        Route::get('approvisionnements', Approvisionnements\Index::class)->name('approvisionnements');
        Route::get('approvisionnements/nouveau', Approvisionnements\Create::class)->name('approvisionnements.create');

        Route::get('charges', Charges\Index::class)->name('charges');
        Route::get('charges/nouvelle', Charges\Create::class)->name('charges.create');
        Route::get('charges/{charge}', Charges\Show::class)->name('charges.show');

        Route::get('g/{resource}', Crud\Index::class)->name('crud.index');
        Route::get('g/{resource}/nouveau', Crud\Form::class)->name('crud.create');
        Route::get('g/{resource}/{id}', Crud\Show::class)->name('crud.show');
        Route::get('g/{resource}/{id}/modifier', Crud\Form::class)->name('crud.edit');

        Route::get('activite', Admin\ActivityLog::class)->name('activite');
        Route::get('activite/{activity}', Admin\ActivityShow::class)->name('activite.show');
        Route::get('sauvegardes', Admin\Backups::class)->name('sauvegardes');
        Route::get('sauvegardes/telecharger', [ExportController::class, 'sauvegarde'])->name('sauvegardes.telecharger');
        Route::get('import', Admin\Import::class)->name('import');
        Route::get('securite', Admin\TwoFactor::class)->name('securite');
        // Abonnement du tenant courant (master/admin) : reste accessible quand l'abonnement est expiré.
        Route::get('abonnement', SubscriptionShow::class)->name('abonnement');
        // Profil de l'entreprise (nom, coordonnées, logo, pied de page des documents).
        Route::get('profil-entreprise', Profile::class)->name('profil');
        // Un compte lié à une fiche agent (master compris) retrouve son espace agent (autre panneau : navigation complète).
        Route::get('espace-agent', function () {
            abort_unless(auth()->user()?->agent()->exists(), 403);

            return redirect('/agent');
        })->name('espace-agent');
        Route::get('diagnostic-notifications', Admin\NotificationsDiagnostic::class)->name('diagnostic-notifications');
        Route::get('exports/caisse/historique', [ExportController::class, 'caisseHistorique'])->name('exports.caisse-historique')->middleware('not.read-only');
        Route::get('exports/caisse/{date}', [ExportController::class, 'caisseJour'])->name('exports.caisse-jour')->middleware('not.read-only')->where('date', '\d{4}-\d{2}-\d{2}');
        Route::get('exports/fiche-agent/{agent}', [ExportController::class, 'ficheAgent'])->name('exports.fiche-agent')->middleware('not.read-only');
        Route::get('exports/charges/{period}/{format}', [ExportController::class, 'charges'])->name('exports.charges')->middleware('not.read-only')
            ->where(['period' => 'today|week|month|last|all', 'format' => 'pdf|xlsx|csv']);
        Route::get('exports/periode/{module}/{period}/{format}', [ExportController::class, 'period'])->name('exports.period')->middleware('not.read-only')
            ->where(['module' => 'ventes|remboursements|approvisionnements', 'period' => 'today|week|month|all', 'format' => 'pdf|xlsx']);

        Route::get('statistiques', Analytics\Statistics::class)->name('statistiques');
        Route::get('scoring', Analytics\Scoring::class)->name('scoring');
        Route::get('scoring/{agent}', Analytics\ScoringAgent::class)->name('scoring.agent');
        Route::get('rapports', Analytics\Reports::class)->name('rapports');
        Route::get('exports/rapport/{type}/{format}', [ExportController::class, 'report'])->name('exports.report')->middleware('not.read-only');
        // Rapports et exports par catégorie (dates libres, fin incluse) : même contrôleur que l'interface Web.
        Route::get('exports/rapports/{category}/{format}', ReportExportController::class)->name('exports.categorie')->middleware('not.read-only')
            ->where(['category' => '[a-z_]+', 'format' => 'pdf|xlsx']);
        Route::get('dettes/historique', Dettes\Historique::class)->name('dettes.historique');

        Route::get('commissions', Commissions\Index::class)->name('commissions');
        Route::get('commissions/{commission}', Commissions\Show::class)->name('commissions.show');
        Route::get('exports/commissions/{format}', [ExportController::class, 'commissions'])->name('exports.commissions')->middleware('not.read-only');

        Route::get('dettes', Dettes\Index::class)->name('dettes');
        Route::get('dettes/agent/{agent}', Dettes\AgentDetail::class)->name('dettes.agent');
        Route::get('dettes/fournisseur/{fournisseur}', Dettes\FournisseurDetail::class)->name('dettes.fournisseur.show');
        Route::get('dettes/fournisseur/{fournisseur}/payer', Dettes\PayFournisseur::class)->name('dettes.fournisseur');
        Route::get('paiements-fournisseurs', RemboursementsFournisseurs\Index::class)->name('remboursements-fournisseurs');

        Route::get('exports/dette-agent/{agent}/{format}', [ExportController::class, 'detteAgent'])->name('exports.dette-agent')->middleware('not.read-only');

        Route::get('agents', Agents\Index::class)->name('agents');
        Route::get('agents/{agent}', Agents\Show::class)->name('agents.show');

        Route::get('ventes', Ventes\Index::class)->name('ventes');
        Route::get('ventes/nouvelle', Ventes\Create::class)->name('ventes.create');
        Route::get('ventes/{transaction}', Ventes\Show::class)->name('ventes.show');

        Route::get('remboursements', Remboursements\Index::class)->name('remboursements');
        Route::get('remboursements/nouveau', Remboursements\Create::class)->name('remboursements.create');
        Route::get('remboursements/{remboursement}', Remboursements\Show::class)->name('remboursements.show');
        Route::post('logout', LogoutController::class)->name('logout');
    });
});
