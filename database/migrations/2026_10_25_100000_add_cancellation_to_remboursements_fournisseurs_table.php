<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annulation d'un remboursement fournisseur : annulation logique (jamais de suppression). La ligne d'origine reste
 * dans l'historique avec le statut « Annulé », son auteur, sa date et son motif ; elle ne compte plus dans le solde
 * (voir FournisseurDebtService). Migration purement additive : aucune ligne existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remboursements_fournisseurs', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('notes');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->index(['fournisseur_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('remboursements_fournisseurs', function (Blueprint $table) {
            $table->dropIndex(['fournisseur_id', 'cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
