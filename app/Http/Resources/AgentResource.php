<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'nom_complet' => $this->nom_complet,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'adresse' => $this->adresse,
            'zone' => $this->zone,
            'status' => $this->status,
            'commission_rate' => $this->commission_rate,
            'plafond_dette' => $this->plafond_dette,
            'current_debt' => $this->current_debt,
            'debt_ratio' => $this->debt_ratio,
            'available_credit' => $this->available_credit,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Include relations if loaded
            'solde' => $this->whenLoaded('solde'),
            'transactions' => $this->whenLoaded('transactions'),
            'remboursements' => $this->whenLoaded('remboursements'),
        ];
    }
}
