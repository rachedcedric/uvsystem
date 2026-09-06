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
            $table->decimal('solde_uv_avant', 15, 2)->nullable()->after('status')
                ->comment('Solde UV Disponible juste avant cette transaction');
            $table->decimal('solde_uv_apres', 15, 2)->nullable()->after('solde_uv_avant')
                ->comment('Solde UV Disponible juste après cette transaction');
        });

        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->decimal('solde_uv_avant', 15, 2)->nullable()->after('notes')
                ->comment('Solde UV Disponible juste avant cet approvisionnement');
            $table->decimal('solde_uv_apres', 15, 2)->nullable()->after('solde_uv_avant')
                ->comment('Solde UV Disponible juste après cet approvisionnement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->dropColumn(['solde_uv_avant', 'solde_uv_apres']);
        });

        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->dropColumn(['solde_uv_avant', 'solde_uv_apres']);
        });
    }
};
