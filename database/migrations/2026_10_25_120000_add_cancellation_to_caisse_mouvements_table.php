<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annulation des mouvements de caisse d'une journée : annulation logique (jamais de suppression physique). Chaque
 * mouvement annulé garde sa référence, sa date et son auteur d'origine, avec la date, l'auteur, le motif et le lot
 * d'annulation (toutes les lignes d'une même journée partagent le même lot). Migration additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisse_mouvements', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('date_mouvement');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->string('cancellation_batch', 36)->nullable()->after('cancellation_reason')->index();
        });
    }

    public function down(): void
    {
        Schema::table('caisse_mouvements', function (Blueprint $table) {
            $table->dropIndex(['cancellation_batch']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'cancellation_batch']);
        });
    }
};
