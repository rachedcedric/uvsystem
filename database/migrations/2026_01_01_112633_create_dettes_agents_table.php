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
        Schema::create('dettes_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_partenaire_id')->unique()->constrained()->cascadeOnDelete(); // 1 agent = 1 solde dette
            $table->decimal('total_achats', 15, 2)->default(0); // Total montant des ventes
            $table->decimal('total_rembourse', 15, 2)->default(0); // Total remboursé
            $table->decimal('dette_restante', 15, 2)->default(0); // Dette = total_achats - total_rembourse
            $table->timestamps();

            // Index
            $table->index('dette_restante');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dettes_agents');
    }
};
