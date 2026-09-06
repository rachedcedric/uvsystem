<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sous_distributeurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->onDelete('cascade');
            $table->string('nom');
            $table->string('telephone')->unique();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->string('ville')->nullable();
            $table->enum('type', ['standard', 'premium', 'vip'])->default('standard');
            $table->decimal('plafond_dette', 15, 2)->nullable()->comment('Plafond de dette max');
            $table->decimal('commission_rate', 5, 2)->default(0)->comment('Taux de commission en %');
            $table->enum('statut', ['actif', 'inactif', 'suspendu'])->default('actif');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agent_id', 'statut']);
            $table->index('telephone');
        });

        // Table des soldes des sous-distributeurs
        Schema::create('sous_distributeur_soldes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sous_distributeur_id')->constrained('sous_distributeurs')->onDelete('cascade');
            $table->decimal('total_uv_distribues', 15, 2)->default(0);
            $table->decimal('total_rembourse', 15, 2)->default(0);
            $table->decimal('solde_restant', 15, 2)->default(0);
            $table->timestamps();

            $table->unique('sous_distributeur_id');
        });

        // Table des transactions des sous-distributeurs
        Schema::create('sous_distributeur_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sous_distributeur_id')->constrained('sous_distributeurs')->onDelete('cascade');
            $table->foreignId('agent_id')->constrained('agents')->onDelete('cascade');
            $table->integer('quantity_uv');
            $table->decimal('amount', 15, 2);
            $table->decimal('prix_unitaire', 10, 2);
            $table->string('reference')->unique();
            $table->enum('type', ['distribution', 'retour'])->default('distribution');
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sous_distributeur_id', 'transaction_date'], 'sd_trans_sd_id_date_idx');
            $table->index(['agent_id', 'transaction_date'], 'sd_trans_agent_id_date_idx');
        });

        // Table des remboursements des sous-distributeurs
        Schema::create('sous_distributeur_remboursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sous_distributeur_id')->constrained('sous_distributeurs')->onDelete('cascade');
            $table->foreignId('agent_id')->constrained('agents')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['especes', 'mobile_money', 'virement', 'cheque'])->default('especes');
            $table->string('reference')->unique();
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sous_distributeur_id', 'payment_date'], 'sd_rembours_sd_id_pay_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sous_distributeur_remboursements');
        Schema::dropIfExists('sous_distributeur_transactions');
        Schema::dropIfExists('sous_distributeur_soldes');
        Schema::dropIfExists('sous_distributeurs');
    }
};
