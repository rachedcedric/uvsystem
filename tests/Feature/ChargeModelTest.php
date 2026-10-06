<?php

use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Activity;

uses(RefreshDatabase::class);

describe('Charge model', function () {

    it('creates a charge with created_by populated', function () {
        $user = User::factory()->create();
        $category = ChargeCategorie::first() ?? ChargeCategorie::create(['code' => 'autres', 'name' => 'Autres']);

        $charge = Charge::create([
            'category_id' => $category->id,
            'libelle' => 'Facture internet',
            'montant' => 30000,
            'date_charge' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        expect($charge->created_by)->toBe($user->id)
            ->and($charge->creator->id)->toBe($user->id)
            ->and((float) $charge->montant)->toBe(30000.0);
    });

    it('updates updated_by on edit, independently of created_by', function () {
        $creator = User::factory()->create();
        $editor = User::factory()->create();

        $charge = Charge::create([
            'libelle' => 'Transport',
            'montant' => 15000,
            'date_charge' => now()->toDateString(),
            'created_by' => $creator->id,
        ]);

        $charge->update(['montant' => 20000, 'updated_by' => $editor->id]);

        expect($charge->fresh()->created_by)->toBe($creator->id)
            ->and($charge->fresh()->updated_by)->toBe($editor->id)
            ->and((float) $charge->fresh()->montant)->toBe(20000.0);
    });

    it('soft-deletes a charge instead of removing it permanently', function () {
        $charge = Charge::create([
            'libelle' => 'Loyer',
            'montant' => 150000,
            'date_charge' => now()->toDateString(),
        ]);

        $charge->delete();

        expect(Charge::find($charge->id))->toBeNull()
            ->and(Charge::withTrashed()->find($charge->id))->not->toBeNull()
            ->and(Charge::withTrashed()->find($charge->id)->trashed())->toBeTrue();
    });

    it('excludes a soft-deleted charge from period totals', function () {
        $charge = Charge::create([
            'libelle' => 'Maintenance',
            'montant' => 40000,
            'date_charge' => now()->toDateString(),
        ]);
        $charge->delete();

        $total = Charge::forPeriod(now()->startOfMonth(), now()->endOfMonth())->sum('montant');

        expect((float) $total)->toBe(0.0);
    });

    it('logs an activity entry when a charge is created', function () {
        $before = Activity::count();

        Charge::create([
            'libelle' => 'Fournitures de bureau',
            'montant' => 12000,
            'date_charge' => now()->toDateString(),
        ]);

        expect(Activity::count())->toBe($before + 1);
    });

    it('logs an activity entry when a charge is modified', function () {
        $charge = Charge::create([
            'libelle' => 'Électricité',
            'montant' => 45000,
            'date_charge' => now()->toDateString(),
        ]);

        $before = Activity::count();
        $charge->update(['montant' => 48000]);

        expect(Activity::count())->toBe($before + 1);
    });
});
