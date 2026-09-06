<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Supprime la colonne quantity_uv des approvisionnements
     * Le solde principal est désormais basé uniquement sur les montants en FCFA
     */
    public function up(): void
    {
        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->dropColumn('quantity_uv');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->decimal('quantity_uv', 15, 2)->after('fournisseur_id')->default(0);
        });
    }
};
