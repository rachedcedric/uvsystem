<?php

namespace App\Livewire\Concerns;

use App\Services\Reports\DateRange;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Période des écrans « Rapports et exports » et « Historique des dettes et crédits » (Web et mobile) : raccourcis
 * (aujourd'hui, hier, 7 derniers jours, ce mois, mois précédent) ou dates libres, conservés dans l'URL. Une période
 * incohérente (fin avant début, date invalide ou future) n'affiche ni données ni boutons d'export, mais un message.
 */
trait WithReportPeriod
{
    #[Url(as: 'periode', except: 'month')]
    public string $periode = 'month';

    #[Url(as: 'du', except: '')]
    public string $du = '';

    #[Url(as: 'au', except: '')]
    public string $au = '';

    public function updatedPeriode(): void
    {
        if ($this->periode === 'custom' && ($this->du === '' || $this->au === '')) {
            $this->du = now()->startOfMonth()->toDateString();
            $this->au = now()->toDateString();
        }

        if ($this->periode !== 'custom') {
            $this->du = '';
            $this->au = '';
        }

        $this->onPeriodChanged();
    }

    public function updatedDu(): void
    {
        $this->onPeriodChanged();
    }

    public function updatedAu(): void
    {
        $this->onPeriodChanged();
    }

    protected function onPeriodChanged(): void
    {
        if (property_exists($this, 'limit')) {
            $this->limit = static::PAGE;
        }
    }

    /** @return array{0: ?DateRange, 1: ?string} [période, message d'erreur] */
    protected function resolveRange(): array
    {
        try {
            return [DateRange::fromInput(['periode' => $this->periode, 'du' => $this->du, 'au' => $this->au]), null];
        } catch (ValidationException $e) {
            return [null, collect($e->errors())->flatten()->implode(' ')];
        }
    }
}
