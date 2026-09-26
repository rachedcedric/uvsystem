<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_NAME = 'Non catégorisé';

    private const INITIAL = ['Espèce', 'UV Djamo', 'UV Orange Money', 'UV Push', 'Wave'];

    public function up(): void
    {
        Schema::create('caisse_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('caisse_mouvements', function (Blueprint $table) {
            $table->unsignedBigInteger('caisse_categorie_id')->nullable()->after('type');
            $table->index('caisse_categorie_id');
        });

        $now = now();
        $row = fn (string $name, int $order, bool $system) => [
            'name' => $name,
            'normalized_name' => mb_strtolower(trim(preg_replace('/\s+/', ' ', $name))),
            'sort_order' => $order,
            'is_active' => true,
            'is_system' => $system,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Catégories initiales : uniquement si aucune catégorie utilisateur n'existe.
        if (DB::table('caisse_categories')->where('is_system', false)->doesntExist()) {
            foreach (self::INITIAL as $i => $name) {
                DB::table('caisse_categories')->insertOrIgnore($row($name, $i + 1, false));
            }
        }

        DB::table('caisse_categories')->insertOrIgnore($row(self::SYSTEM_NAME, 999, true));

        // Rattache les mouvements existants (aucune donnée perdue).
        $systemId = DB::table('caisse_categories')->where('is_system', true)->value('id');
        DB::table('caisse_mouvements')->whereNull('caisse_categorie_id')->update(['caisse_categorie_id' => $systemId]);
    }

    public function down(): void
    {
        Schema::table('caisse_mouvements', function (Blueprint $table) {
            $table->dropIndex(['caisse_categorie_id']);
            $table->dropColumn('caisse_categorie_id');
        });

        Schema::dropIfExists('caisse_categories');
    }
};
