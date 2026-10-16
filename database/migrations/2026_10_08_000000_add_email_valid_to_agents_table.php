<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * agents.email_valid : le webhook e-mail (Resend) la passe à false quand l'adresse d'un agent rebondit
 * ou est signalée comme spam. Le code l'écrivait depuis longtemps, mais aucune migration ne la créait : la mise à
 * jour échouait silencieusement. Par défaut true : toutes les adresses existantes restent considérées valides.
 * Additive et rejouable ; aucune donnée existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agents', 'email_valid')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->boolean('email_valid')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agents', 'email_valid')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->dropColumn('email_valid');
            });
        }
    }
};
