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
        Schema::create('remboursements_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_partenaire_id')->constrained()->cascadeOnDelete();
            $table->decimal('montant', 15, 2); // Montant remboursé
            $table->string('reference')->unique(); // Référence unique (REMB-XXXXX)
            $table->date('date_remboursement'); // Date du remboursement
            $table->text('notes')->nullable(); // Notes optionnelles
            $table->timestamps();
            $table->softDeletes();

            // Index raccourci
            $table->index('agent_partenaire_id', 'idx_remb_agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remboursements_agents');
    }
};
