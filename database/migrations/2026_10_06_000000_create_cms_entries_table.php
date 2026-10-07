<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS de la plateforme : surcharges de contenu (textes, liens, coordonnées) modifiables par le super admin.
 * Table GLOBALE (aucun tenant_id) : le contenu public est celui de la plateforme. Une ligne n'existe que pour un
 * contenu modifié ; sans ligne, la valeur par défaut définie dans le code (App\Cms\ContentRegistry) s'applique.
 * Purement additive ; le retour arrière supprime seulement cette table.
 *
 * Indépendante de la multi-location et des abonnements (aucune clé étrangère) : son horodatage la place AVANT eux, ce qui
 * laisse intacts les retours arrière « des N dernières migrations » (tenants, abonnements) qu'utilisent les tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_entries', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->text('value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_entries');
    }
};
