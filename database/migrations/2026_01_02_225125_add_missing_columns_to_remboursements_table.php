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
        Schema::table('remboursements', function (Blueprint $table) {
            // Ajouter user_id si manquant
            if (! Schema::hasColumn('remboursements', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            }

            // Ajouter deleted_at pour SoftDeletes
            if (! Schema::hasColumn('remboursements', 'deleted_at')) {
                $table->softDeletes();
            }

            // Ajouter status si manquant
            if (! Schema::hasColumn('remboursements', 'status')) {
                $table->string('status', 50)->default('completed')->after('payment_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remboursements', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'status']);
        });
    }
};
