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
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'debt_limit' => $this->debt_limit,
            // Anciennes clés conservées pour les clients existants (elles retournaient null : colonnes disparues)
            'nom' => $this->name,
            'prenom' => null,
            'nom_complet' => $this->nom_complet,
            'telephone' => $this->phone,
            'email' => $this->email,
            'adresse' => $this->address,
            'zone' => $this->zone,
            'status' => $this->status,
            'commission_rate' => $this->commission_rate,
            'plafond_dette' => $this->debt_limit,
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
