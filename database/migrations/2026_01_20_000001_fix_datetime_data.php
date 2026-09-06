<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Met à jour l'heure des transactions existantes en utilisant la date de création
        // uniquement si la date correspond (pour ne pas écraser les backdates volontaires)
        DB::statement("UPDATE uv_transactions SET transaction_date = created_at WHERE DATE(transaction_date) = DATE(created_at)");
        
        // Idem pour les remboursements
        DB::statement("UPDATE remboursements SET payment_date = created_at WHERE DATE(payment_date) = DATE(created_at)");
    }

    public function down(): void
    {
        // Pas de rollback possible précis car l'info 00:00:00 est perdue, 
        // mais ce n'est pas grave car on a amélioré la précision.
    }
};
