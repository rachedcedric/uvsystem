<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Avant d'ajouter la contrainte d'unicité, on fusionne les éventuels
     * doublons hérités du bug de recalcul destructif (forceDelete + create) :
     * la ligne la plus récente est conservée, les autres sont *soft-deleted*
     * (jamais supprimées définitivement) afin de ne perdre aucune donnée.
     */
    public function up(): void
    {
        $this->dedupliquerCommissionsExistantes();

        Schema::table('commissions', function (Blueprint $table) {
            $table->string('statut', 30)->default('calculated')->after('is_global');
            $table->timestamp('calculated_at')->nullable()->after('statut');
            $table->index('statut', 'idx_commissions_statut');
        });

        DB::table('commissions')->update([
            'statut' => 'calculated',
        ]);
        DB::statement('UPDATE commissions SET calculated_at = updated_at WHERE calculated_at IS NULL');

        // Colonne calculée : NULL pour toute ligne soft-deleted ou qui
        // n'est pas la commission globale du jour (is_global = false —
        // les lignes historiques par agent/par vente, héritées de l'ancien
        // CommissionObserver désactivé, peuvent légitimement partager une
        // même date_commission entre elles). MySQL/SQLite traitent chaque
        // NULL comme distinct dans un index unique : seule la commission
        // globale (is_global = true) est donc contrainte à une ligne par
        // jour, ce qui correspond exactement à l'invariant réellement
        // utilisé par le calcul actif.
        Schema::table('commissions', function (Blueprint $table) {
            $table->date('dedup_key')->nullable()->storedAs(
                'CASE WHEN deleted_at IS NULL AND is_global = 1 THEN date_commission ELSE NULL END'
            );
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->unique('dedup_key', 'commissions_dedup_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_dedup_key_unique');
            $table->dropColumn('dedup_key');
            $table->dropIndex('idx_commissions_statut');
            $table->dropColumn(['statut', 'calculated_at']);
        });
    }

    private function dedupliquerCommissionsExistantes(): void
    {
        if (! Schema::hasTable('commissions')) {
            return;
        }

        $doublons = DB::table('commissions')
            ->select('date_commission')
            ->where('is_global', true)
            ->whereNull('deleted_at')
            ->groupBy('date_commission')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($doublons as $doublon) {
            $lignes = DB::table('commissions')
                ->where('date_commission', $doublon->date_commission)
                ->where('is_global', true)
                ->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            // On garde la ligne la plus récemment mise à jour, on
            // soft-delete les autres (aucune perte de données).
            foreach ($lignes->skip(1) as $ligneObsolete) {
                DB::table('commissions')
                    ->where('id', $ligneObsolete->id)
                    ->update(['deleted_at' => now()]);
            }
        }
    }
};
