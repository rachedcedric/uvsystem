<?php

namespace App\Support\Subscriptions;

use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\TenantContext;

/**
 * Applique les fonctionnalités d'export de l'offre à TOUTE génération de PDF ou d'Excel (Filament, mobile, API,
 * commandes), sans modifier la trentaine d'endroits qui les produisent : GatedPdf et GatedExcel (liés au conteneur
 * dans AppServiceProvider) appellent cette classe.
 *
 *   exports_pdf / exports_excel : tous les exports téléchargés depuis les interfaces Master (listes, commissions,
 *                                 caisse, fiche agent, exports par période, rapports et historiques) ;
 *   rapports_avances            : rapports quotidiens et planifiés générés automatiquement.
 * « exports_avances » n'est plus appliquée (fonctionnalité ouverte, voir FeatureCatalog::openFeatures()) : un Master
 * dont l'abonnement est actif exporte toutes ses opérations, quelle que soit son offre.
 * Hors tenant (plateforme, système sans tenant) rien n'est restreint. En accès restreint les exports de listes
 * restent permis (récupération des données) : voir subscriptions.recovery_features.
 */
class ExportGate
{
    private const REPORT_PDF_VIEWS = ['exports.daily-report-pdf', 'exports.scheduled-report-pdf'];

    public static function featureForPdfView(string $view): ?string
    {
        return match (true) {
            in_array($view, self::REPORT_PDF_VIEWS, true) => 'rapports_avances',
            str_starts_with($view, 'exports.') => 'exports_pdf',
            default => null, // reçus et documents transactionnels : jamais bloqués
        };
    }

    public static function featureForExcel(object $export): string
    {
        return 'exports_excel';
    }

    public static function checkPdfView(string $view): void
    {
        if ($feature = self::featureForPdfView($view)) {
            self::require($feature);
        }
    }

    public static function checkExcel(mixed $export): void
    {
        if (is_object($export)) {
            self::require(self::featureForExcel($export));
        }
    }

    public static function require(string $feature): void
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            return;
        }

        abort_unless(
            app(SubscriptionEntitlements::class)->hasFeature($tenantId, $feature),
            403,
            "Cet export n'est pas inclus dans votre offre. Passez à une offre supérieure pour l'utiliser."
        );
    }
}
