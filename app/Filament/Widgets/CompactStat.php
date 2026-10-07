<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\View\View;

class CompactStat extends Stat
{
    public function render(): View
    {
        return view('filament.widgets.compact-stat', $this->data());
    }
}
