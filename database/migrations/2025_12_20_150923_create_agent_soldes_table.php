<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_soldes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('total_uv_distribues', 15, 2)->default(0);
            $table->decimal('total_rembourse', 15, 2)->default(0);
            $table->decimal('solde_restant', 15, 2)->default(0);
            $table->timestamps();

            // Index
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_soldes');
    }
};
