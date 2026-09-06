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
        Schema::table('uv_transactions', function (Blueprint $table) {
            // Ajouter la colonne signature pour l'intégrité des transactions
            $table->string('signature', 64)->nullable()->after('amount');
            $table->index('signature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->dropIndex(['signature']);
            $table->dropColumn('signature');
        });
    }
};
