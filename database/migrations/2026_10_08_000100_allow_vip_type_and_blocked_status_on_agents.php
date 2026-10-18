<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les formulaires (web, mobile) et l'API proposent le type « VIP » et le statut « Bloqué », mais la colonne n'acceptait
 * que standard/premium et active/inactive/suspended : MySQL refusait l'enregistrement
 * (« Data truncated for column 'type' »). On élargit les deux listes ; aucune ligne existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->enum('type', ['standard', 'premium', 'vip'])->default('standard')->change();
            $table->enum('status', ['active', 'inactive', 'suspended', 'blocked'])->default('active')->change();
        });
    }

    /**
     * Pas de retour arrière : rétrécir les listes tronquerait (ou refuserait) les agents déjà enregistrés en VIP ou Bloqué.
     */
    public function down(): void {}
};
