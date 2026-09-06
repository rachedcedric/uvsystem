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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Identifiant unique (cash, mobile_money, etc.)
            $table->string('name'); // Nom affiché (Espèces, Mobile Money, etc.)
            $table->string('icon')->nullable(); // Emoji ou icône (💵, 📱, etc.)
            $table->string('description')->nullable(); // Description optionnelle
            $table->boolean('is_active')->default(true); // Actif ou non
            $table->integer('sort_order')->default(0); // Ordre d'affichage
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
