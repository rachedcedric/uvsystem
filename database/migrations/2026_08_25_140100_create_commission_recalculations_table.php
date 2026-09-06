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
        Schema::create('commission_recalculations', function (Blueprint $table) {
            $table->id();
            $table->date('date_commission');
            $table->foreignId('commission_id')->nullable()->constrained('commissions')->nullOnDelete();
            $table->unsignedInteger('nombre_ventes')->default(0);
            $table->decimal('montant_total_ventes', 15, 2)->default(0);
            $table->decimal('ancienne_commission', 15, 2)->nullable();
            $table->decimal('nouvelle_commission', 15, 2)->nullable();
            // created | updated | unchanged | failed
            $table->string('statut', 20);
            // scheduled | catchup | manual | historical_backfill | late_sale
            $table->string('source', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('date_commission');
            $table->index(['source', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_recalculations');
    }
};
