<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('paliers_commission', function (Blueprint $table) {
            $table->id();
            $table->decimal('seuil_min', 15, 2); // Montant minimum
            $table->decimal('seuil_max', 15, 2); // Montant maximum
            $table->decimal('commission', 15, 2); // Montant de la commission
            $table->boolean('is_active')->default(true); // Actif/Inactif
            $table->timestamps();
        });

        // Insérer les paliers par défaut
        DB::table('paliers_commission')->insert([
            ['seuil_min' => 20000, 'seuil_max' => 599999, 'commission' => 1800, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['seuil_min' => 600000, 'seuil_max' => 4999999, 'commission' => 1800, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['seuil_min' => 5000000, 'seuil_max' => 9999999, 'commission' => 31915, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['seuil_min' => 10000000, 'seuil_max' => 19999999, 'commission' => 52725, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['seuil_min' => 20000000, 'seuil_max' => 29999999, 'commission' => 60125, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['seuil_min' => 30000000, 'seuil_max' => 999999999, 'commission' => 70000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paliers_commission');
    }
};
