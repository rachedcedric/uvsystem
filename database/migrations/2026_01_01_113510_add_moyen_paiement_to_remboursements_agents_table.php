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
        Schema::table('remboursements_agents', function (Blueprint $table) {
            $table->enum('moyen_paiement', ['CASH', 'WAVE', 'OM'])
                ->default('CASH')
                ->after('montant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remboursements_agents', function (Blueprint $table) {
            $table->dropColumn('moyen_paiement');
        });
    }
};
