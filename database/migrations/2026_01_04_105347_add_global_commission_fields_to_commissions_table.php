<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nouvelle structure pour commissions globales journalières avec répartition par agent :
     * - commission_globale_id : Référence à la commission globale du jour (NULL si c'est la ligne globale)
     * - volume_total_jour : Volume total de ventes du jour (tous agents confondus)
     * - commission_globale : Montant total de la commission du jour
     * - volume_agent : Volume de ventes de l'agent ce jour
     * - part_agent : Part de commission attribuée à l'agent (proportionnelle)
     * - pourcentage_contribution : % de contribution de l'agent au volume total
     * - is_global : Indique si c'est la ligne de commission globale
     */
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // Référence à la commission globale (NULL si c'est la ligne globale elle-même)
            $table->unsignedBigInteger('commission_globale_id')->nullable()->after('id');

            // Volume total du jour (tous agents confondus)
            $table->decimal('volume_total_jour', 15, 2)->default(0)->after('montant_commission');

            // Commission globale du jour (basée sur le volume total)
            $table->decimal('commission_globale', 15, 2)->default(0)->after('volume_total_jour');

            // Volume de ventes de l'agent ce jour
            $table->decimal('volume_agent', 15, 2)->default(0)->after('commission_globale');

            // Part de commission de l'agent (proportionnelle à sa contribution)
            $table->decimal('part_agent', 15, 2)->default(0)->after('volume_agent');

            // Pourcentage de contribution de l'agent au volume total
            $table->decimal('pourcentage_contribution', 8, 4)->default(0)->after('part_agent');

            // Flag pour identifier la ligne de commission globale
            $table->boolean('is_global')->default(false)->after('pourcentage_contribution');

            // Nombre de transactions incluses
            $table->integer('nombre_transactions')->default(0)->after('is_global');

            // Index pour performance
            $table->index(['date_commission', 'is_global'], 'idx_commission_date_global');
            $table->index('commission_globale_id', 'idx_commission_globale_ref');

            // Clé étrangère auto-référencée
            $table->foreign('commission_globale_id')
                ->references('id')
                ->on('commissions')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropForeign(['commission_globale_id']);
            $table->dropIndex('idx_commission_date_global');
            $table->dropIndex('idx_commission_globale_ref');

            $table->dropColumn([
                'commission_globale_id',
                'volume_total_jour',
                'commission_globale',
                'volume_agent',
                'part_agent',
                'pourcentage_contribution',
                'is_global',
                'nombre_transactions',
            ]);
        });
    }
};
