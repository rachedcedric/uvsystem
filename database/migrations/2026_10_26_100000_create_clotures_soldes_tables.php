<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des dettes et crédits : photographie des soldes agents et fournisseurs à la clôture de chaque journée
 * (23:59:59, Africa/Abidjan). Une clôture par entreprise et par date (contrainte unique : aucun doublon possible) ;
 * une ligne par compte dont le solde est non nul. Une journée sans solde a sa clôture, sans aucune ligne.
 * Les lignes ne sont jamais recalculées : un écart constaté plus tard (opération antidatée ou annulée après la
 * clôture) est signalé sur l'en-tête, les soldes figés restent ceux constatés. Migration additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_journalieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->date('date');
            // Moment réel de l'enregistrement ; « automatique » le lendemain de la journée, « rattrapage » ensuite.
            $table->dateTime('closed_at');
            $table->string('source', 20)->default('automatique');
            $table->unsignedInteger('nb_dettes')->default(0);
            $table->unsignedInteger('nb_credits')->default(0);
            $table->decimal('total_dettes', 15, 2)->default(0);
            $table->decimal('total_credits', 15, 2)->default(0);
            // Contrôle a posteriori : la reconstitution depuis les mouvements diffère-t-elle des soldes figés ?
            $table->dateTime('verifiee_at')->nullable();
            $table->dateTime('ecart_constate_at')->nullable();
            $table->json('ecart_details')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
        });

        Schema::create('cloture_soldes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('cloture_id')->constrained('clotures_journalieres')->restrictOnDelete();
            $table->date('date');
            $table->string('compte_type', 20); // agent | fournisseur
            $table->unsignedBigInteger('compte_id'); // sans clé étrangère : l'historique survit au compte
            $table->string('nom');
            $table->string('type_label', 60);
            // Solde dans la convention du compte (agent > 0 : l'agent doit ; fournisseur > 0 : le Master doit).
            $table->decimal('solde', 15, 2);
            // dette : le Master doit ; credit : le compte doit au Master. Montant toujours positif.
            $table->string('nature', 10);
            $table->decimal('montant', 15, 2);
            $table->timestamps();

            $table->unique(['cloture_id', 'compte_type', 'compte_id']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'nature', 'date']);
            $table->index(['tenant_id', 'compte_type', 'compte_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloture_soldes');
        Schema::dropIfExists('clotures_journalieres');
    }
};
