<?php

namespace App\Support\Subscriptions;

use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\TenantContext;

/**
 * Applique les fonctionnalités d'export de l'offre à TOUTE génération de PDF ou d'Excel (Filament, mobile, API,
 * commandes), sans modifier la trentaine d'endroits qui les produisent : GatedPdf et GatedExcel (liés au conteneur
 * dans AppServiceProvider) appellent cette classe.
 *
 *   exports_pdf / exports_excel : exports de listes (transactions, remboursements, agents, charges, dettes…) ;
 *   exports_avances             : états consolidés (commissions, caisse, fiche agent, exports par période) ;
 *   rapports_avances            : rapports quotidiens et planifiés.
 * Hors tenant (plateforme, système sans tenant) rien n'est restreint. En accès restreint les exports de listes
 * restent permis (récupération des données) : voir subscriptions.recovery_features.
 */
class ExportGate
{
    private const ADVANCED_PDF_VIEWS = ['exports.commissions-pdf', 'exports.caisse-jour-pdf', 'exports.caisse-historique-pdf', 'exports.agent-fiche-pdf', 'exports.period-pdf'];

    private const REPORT_PDF_VIEWS = ['exports.daily-report-pdf', 'exports.scheduled-report-pdf'];

    private const ADVANCED_EXCEL = [\App\Exports\CommissionsExport::class];

    public static function featureForPdfView(string $view): ?string
    {
        return match (true) {
            in_array($view, self::REPORT_PDF_VIEWS, true) => 'rapports_avances',
            in_array($view, self::ADVANCED_PDF_VIEWS, true) => 'exports_avances',
            str_starts_with($view, 'exports.') => 'exports_pdf',
            default => null, // reçus et documents transactionnels : jamais bloqués
        };
    }

    public static function featureForExcel(object $export): string
    {
        return in_array($export::class, self::ADVANCED_EXCEL, true) ? 'exports_avances' : 'exports_excel';
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
