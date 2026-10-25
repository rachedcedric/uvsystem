<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retour Agent (rebalancing) : un agent qui rend des UV au Master devient son « fournisseur », traité comme un
 * Agent Premium (approvisionnement à crédit, dette du Master envers l'agent). On ajoute le type « AGENT », le lien
 * fournisseurs.agent_id et, pour chaque entreprise, l'entrée système « Retour Agent » (sélecteur du formulaire).
 * Migration additive : la liste des types est élargie, aucune ligne existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->enum('type', ['AGENT_PREMIUM', 'BANQUE', 'AGENT'])->default('BANQUE')->change();
        });

        // Compte fournisseur propre à chaque agent qui rend des UV (créé automatiquement au premier retour) : sa dette
        // (UV rendus à payer à l'agent) ne se confond jamais avec celle d'un autre agent. Nul pour l'entrée « Retour Agent ».
        if (! Schema::hasColumn('fournisseurs', 'agent_id')) {
            Schema::table('fournisseurs', function (Blueprint $table) {
                $table->foreignId('agent_id')->nullable()->after('type')->constrained('agents')->nullOnDelete();
                $table->unique(['tenant_id', 'agent_id']);
            });
        }

        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $exists = DB::table('fournisseurs')->where('tenant_id', $tenantId)->where('type', 'AGENT')->whereNull('agent_id')->exists();

            if (! $exists) {
                DB::table('fournisseurs')->insert([
                    'tenant_id' => $tenantId,
                    'name' => 'Retour Agent',
                    'type' => 'AGENT',
                    'notes' => 'Entrée système : retours d\'UV des agents (rebalancing). L\'agent est choisi à chaque retour.',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Retour arrière partiel : le lien agent_id est retiré ; la liste des types n'est pas rétrécie (cela refuserait les
     * fournisseurs « Agent » existants et leurs approvisionnements).
     */
    public function down(): void
    {
        if (Schema::hasColumn('fournisseurs', 'agent_id')) {
            Schema::table('fournisseurs', function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'agent_id']);
                $table->dropConstrainedForeignId('agent_id');
            });
        }
    }
};
