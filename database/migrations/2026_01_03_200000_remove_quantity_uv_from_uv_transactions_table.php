<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Supprime la colonne quantity_uv des transactions UV
     */
    public function up(): void
    {
        Schema::table('uv_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('uv_transactions', 'quantity_uv')) {
                $table->dropColumn('quantity_uv');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->integer('quantity_uv')->default(0)->after('fournisseur_id');
        });
    }
};
