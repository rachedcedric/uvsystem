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
        Schema::table('agents', function (Blueprint $table) {
            if (! Schema::hasColumn('agents', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('agents', 'address')) {
                $table->string('address', 500)->nullable()->after('email');
            }
            if (! Schema::hasColumn('agents', 'zone')) {
                $table->string('zone', 100)->nullable()->after('address');
            }
            if (! Schema::hasColumn('agents', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->nullable()->after('debt_limit');
            }
            if (! Schema::hasColumn('agents', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
            if (! Schema::hasColumn('agents', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['email', 'address', 'zone', 'commission_rate', 'notes']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
