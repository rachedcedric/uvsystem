<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Renomme les colonnes pour refléter les montants en FCFA au lieu des UV
     */
    public function up(): void
    {
        Schema::table('master_soldes', function (Blueprint $table) {
            $table->renameColumn('total_uv_achetes', 'total_approvisionne');
            $table->renameColumn('total_uv_distribues', 'total_distribue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_soldes', function (Blueprint $table) {
            $table->renameColumn('total_approvisionne', 'total_uv_achetes');
            $table->renameColumn('total_distribue', 'total_uv_distribues');
        });
    }
};
