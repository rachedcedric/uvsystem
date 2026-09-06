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
        Schema::create('master_soldes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('total_uv_achetes', 15, 2)->default(0)->comment('Total UV achetés via approvisionnements');
            $table->decimal('total_uv_distribues', 15, 2)->default(0)->comment('Total UV distribués aux agents');
            $table->decimal('solde_disponible', 15, 2)->default(0)->comment('Solde = achetes - distribues');
            $table->timestamps();

            // Un seul enregistrement par Master
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_soldes');
    }
};
