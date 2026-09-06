<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uv_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fournisseur_id')->nullable()->constrained();
            $table->integer('quantity_uv');
            $table->decimal('amount', 15, 2);
            $table->string('transaction_type', 50)->default('distribution');
            $table->string('reference', 100)->unique();
            $table->date('transaction_date');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('agent_id');
            $table->index('transaction_date');
            $table->index(['agent_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uv_transactions');
    }
};
