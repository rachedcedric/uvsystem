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
        Schema::create('approvisionnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('Master qui approvisionne');
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->cascadeOnDelete();
            $table->decimal('quantity_uv', 15, 2);
            $table->decimal('amount', 15, 2)->comment('Montant payé pour l\'achat');
            $table->string('reference')->unique()->comment('REF auto-générée');
            $table->date('date_approvisionnement');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Index pour recherche et rapports
            $table->index(['user_id', 'date_approvisionnement']);
            $table->index('fournisseur_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvisionnements');
    }
};
