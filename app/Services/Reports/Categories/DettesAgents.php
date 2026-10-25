<?php

namespace App\Services\Reports\Categories;

use App\Services\Reports\DebtCategory;

class DettesAgents extends DebtCategory
{
    public function key(): string
    {
        return 'dettes_agents';
    }

    public function label(): string
    {
        return 'Dettes agents';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function description(): string
    {
        return 'Ce que les agents doivent au Master';
    }

    protected function permissions(): array
    {
        return ['view_remboursements'];
    }

    protected function scope(): array
    {
        return ['type' => 'agent'];
    }
}
