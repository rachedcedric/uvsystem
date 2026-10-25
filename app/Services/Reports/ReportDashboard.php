<?php

namespace App\Services\Reports;

use App\Models\User;

/**
 * Tuiles du tableau de bord « Rapports et exports » (Web et mobile) pour une période : chaque chiffre vient de la
 * catégorie correspondante du catalogue (mêmes règles que ses lignes et ses exports). Les dettes et crédits sont la
 * situation à la fin de la période. Une tuile n'apparaît que si l'utilisateur a le droit de voir sa catégorie.
 *
 * Dettes agents (ce que les agents doivent) et dettes fournisseurs (ce que le Master doit) sont présentées
 * séparément : additionner une créance et une dette n'aurait pas de sens.
 */
class ReportDashboard
{
    public function __construct(private readonly ReportCatalog $catalog) {}

    /** @return list<array{key: string, label: string, value: float, sub: string, icon: string, tone: ?string}> */
    public function tiles(DateRange $range, ?User $user): array
    {
        $allowed = $this->catalog->for($user);
        $tiles = [];
        $count = fn (int $n, string $one, string $many) => $n.' '.($n > 1 ? $many : $one);

        foreach (['ventes' => ['Total des ventes', 'vente', 'ventes'], 'approvisionnements' => ['Total des approvisionnements', 'approvisionnement', 'approvisionnements'], 'remboursements' => ['Total des remboursements', 'remboursement', 'remboursements'], 'commissions' => ['Total des commissions', 'jour', 'jours']] as $key => [$label, $one, $many]) {
            if (! isset($allowed[$key])) {
                continue;
            }

            $s = $allowed[$key]->summary($range);
            $sub = $count($s['count'], $one, $many);

            if ($key === 'remboursements' && isset($allowed['remboursements_fournisseurs'])) {
                $sub .= ' · fournisseurs payés : '.ReportPresenter::fcfa($allowed['remboursements_fournisseurs']->summary($range)['total']);
            }

            $tiles[] = ['key' => $key, 'label' => $label, 'value' => $s['total'], 'sub' => $sub, 'icon' => $allowed[$key]->icon(), 'tone' => null];
        }

        $seesAgents = isset($allowed['dettes_agents']);
        $seesFournisseurs = isset($allowed['dettes_fournisseurs']);

        if ($seesAgents || $seesFournisseurs) {
            $d = app(DebtHistoryService::class)->summary($range);

            if ($seesAgents) {
                $tiles[] = ['key' => 'dettes_agents', 'label' => 'Dettes agents', 'value' => $d['dettes_agents'], 'sub' => 'À encaisser au '.$range->toLabel(), 'icon' => 'users', 'tone' => $d['dettes_agents'] > 0 ? 'danger' : null];
            }
            if ($seesFournisseurs) {
                $tiles[] = ['key' => 'dettes_fournisseurs', 'label' => 'Dettes fournisseurs', 'value' => $d['dettes_fournisseurs'], 'sub' => 'À payer au '.$range->toLabel(), 'icon' => 'building-storefront', 'tone' => $d['dettes_fournisseurs'] > 0 ? 'danger' : null];
            }

            $credits = ($seesFournisseurs ? $d['decouverts_fournisseurs'] : 0) + ($seesAgents ? $d['credits_agents'] : 0);
            $tiles[] = [
                'key' => 'credits_decouverts', 'label' => 'Crédits et découverts', 'value' => $credits,
                'sub' => collect([$seesFournisseurs ? 'Découverts : '.ReportPresenter::fcfa($d['decouverts_fournisseurs']) : null, $seesAgents ? 'crédits agents : '.ReportPresenter::fcfa($d['credits_agents']) : null])->filter()->implode(' · '),
                'icon' => 'banknotes', 'tone' => $credits < 0 ? 'ok' : null,
            ];
        }

        return $tiles;
    }
}
