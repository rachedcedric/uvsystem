<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remboursements', function (Blueprint $table) {
            $table->dateTime('payment_date')->change();
        });

        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->dateTime('transaction_date')->change();
        });
    }

    public function down(): void
    {
        Schema::table('remboursements', function (Blueprint $table) {
            $table->date('payment_date')->change();
        });

        Schema::table('uv_transactions', function (Blueprint $table) {
            $table->date('transaction_date')->change();
        });
    }
};
