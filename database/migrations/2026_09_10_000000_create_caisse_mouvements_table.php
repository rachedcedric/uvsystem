<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisse_mouvements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['entree', 'sortie']);
            $table->decimal('amount', 15, 2);
            $table->string('libelle');
            $table->text('notes')->nullable();
            $table->string('reference')->unique();
            $table->dateTime('date_mouvement');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date_mouvement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisse_mouvements');
    }
};
