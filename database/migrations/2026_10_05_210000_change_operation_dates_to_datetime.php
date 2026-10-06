<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->dateTime('date_approvisionnement')->change();
        });

        Schema::table('ventes_uv', function (Blueprint $table) {
            $table->dateTime('date_vente')->change();
        });

        Schema::table('remboursements_agents', function (Blueprint $table) {
            $table->dateTime('date_remboursement')->change();
        });

        Schema::table('sous_distributeur_transactions', function (Blueprint $table) {
            $table->dateTime('transaction_date')->change();
        });

        Schema::table('sous_distributeur_remboursements', function (Blueprint $table) {
            $table->dateTime('payment_date')->change();
        });
    }

    public function down(): void
    {
        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->date('date_approvisionnement')->change();
        });

        Schema::table('ventes_uv', function (Blueprint $table) {
            $table->date('date_vente')->change();
        });

        Schema::table('remboursements_agents', function (Blueprint $table) {
            $table->date('date_remboursement')->change();
        });

        Schema::table('sous_distributeur_transactions', function (Blueprint $table) {
            $table->date('transaction_date')->change();
        });

        Schema::table('sous_distributeur_remboursements', function (Blueprint $table) {
            $table->date('payment_date')->change();
        });
    }
};
