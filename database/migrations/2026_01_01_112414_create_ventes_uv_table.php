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
        Schema::create('ventes_uv', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Master qui vend
            $table->foreignId('agent_partenaire_id')->constrained()->cascadeOnDelete(); // Agent acheteur
            $table->decimal('quantity_uv', 15, 2); // Quantité UV vendue
            $table->decimal('montant_total', 15, 2); // Montant total de la vente
            $table->decimal('prix_unitaire', 10, 2); // Prix par UV (calculé automatiquement)
            $table->string('reference')->unique(); // Référence unique (VENTE-XXXXX)
            $table->date('date_vente'); // Date de la vente
            $table->text('notes')->nullable(); // Notes optionnelles
            $table->timestamps();
            $table->softDeletes();

            // Index pour optimiser les requêtes
            $table->index(['user_id', 'date_vente']);
            $table->index('agent_partenaire_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventes_uv');
    }
};
