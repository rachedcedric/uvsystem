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
        Schema::table('fournisseurs', function (Blueprint $table) {
            if (! Schema::hasColumn('fournisseurs', 'email')) {
                $table->string('email')->nullable()->after('contact');
            }
            if (! Schema::hasColumn('fournisseurs', 'address')) {
                $table->text('address')->nullable()->after('email');
            }
            if (! Schema::hasColumn('fournisseurs', 'notes')) {
                $table->text('notes')->nullable()->after('address');
            }
            if (! Schema::hasColumn('fournisseurs', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropColumn(['email', 'address', 'notes', 'is_active']);
        });
    }
};
