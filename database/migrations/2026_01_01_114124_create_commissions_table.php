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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vente_uv_id')->nullable()->constrained('ventes_uv')->nullOnDelete(); // Lien avec vente
            $table->decimal('montant_vente', 15, 2); // Montant total de la vente
            $table->decimal('montant_commission', 15, 2); // Commission calculée
            $table->decimal('seuil_min', 15, 2); // Seuil minimum du palier
            $table->decimal('seuil_max', 15, 2); // Seuil maximum du palier
            $table->date('date_commission'); // Date de génération
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('date_commission');
            $table->index(['montant_vente', 'date_commission']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
