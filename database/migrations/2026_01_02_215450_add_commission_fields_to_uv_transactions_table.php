<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('amount')->comment('Taux de commission appliqué (%)');
            $table->decimal('commission_amount', 15, 2)->nullable()->after('commission_rate')->comment('Montant de la commission en FCFA');
            $table->text('notes')->nullable()->after('transaction_date')->comment('Notes supplémentaires');
            $table->string('status', 50)->default('completed')->after('notes')->comment('Statut: completed, pending, cancelled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_amount', 'notes', 'status']);
        });
    }
};
