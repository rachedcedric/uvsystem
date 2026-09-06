<?php

use App\Services\CommissionBusinessDateResolver;
use Carbon\Carbon;

function resolver(): CommissionBusinessDateResolver
{
    return new CommissionBusinessDateResolver;
}

describe('CommissionBusinessDateResolver', function () {

    describe('resolve()', function () {

        it('keeps a sale at 17:59:59 on the same business day', function () {
            $instant = Carbon::create(2026, 8, 25, 17, 59, 59);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-08-25');
        });

        it('keeps a sale at 18:01:59 on the same business day', function () {
            $instant = Carbon::create(2026, 8, 25, 18, 1, 59);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-08-25');
        });

        it('pushes a sale at exactly 18:02:00 to the next business day', function () {
            $instant = Carbon::create(2026, 8, 25, 18, 2, 0);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-08-26');
        });

        it('pushes a sale at 18:02:01 to the next business day', function () {
            $instant = Carbon::create(2026, 8, 25, 18, 2, 1);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-08-26');
        });

        it('pushes a sale at 23:30 to the next business day', function () {
            $instant = Carbon::create(2026, 8, 25, 23, 30, 0);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-08-26');
        });

        it('keeps a sale at 00:30 on the business day opened by the previous evening\'s 18:02', function () {
            $instant = Carbon::create(2026, 8, 26, 0, 30, 0);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-08-26');
        });

        it('rolls over correctly across a month boundary', function () {
            $instant = Carbon::create(2026, 8, 31, 18, 2, 0);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-09-01');
        });

        it('rolls over correctly across a year boundary', function () {
            $instant = Carbon::create(2026, 12, 31, 19, 30, 0);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2027-01-01');
        });

        it('keeps 31 December just before cutoff in the current year', function () {
            $instant = Carbon::create(2026, 12, 31, 18, 1, 59);

            expect(resolver()->resolve($instant)->toDateString())->toBe('2026-12-31');
        });
    });

    describe('periodFor()', function () {

        it('returns the exact [J-1 18:02:00, J 18:01:59] window', function () {
            [$debut, $fin] = resolver()->periodFor(Carbon::create(2026, 8, 25));

            expect($debut->toDateTimeString())->toBe('2026-08-24 18:02:00')
                ->and($fin->toDateTimeString())->toBe('2026-08-25 18:01:59');
        });
    });

    describe('isClosed()', function () {

        it('considers yesterday\'s business day closed', function () {
            $now = Carbon::create(2026, 8, 25, 20, 0, 0);
            $hier = Carbon::create(2026, 8, 24);

            expect(resolver()->isClosed($hier, $now))->toBeTrue();
        });

        it('considers the currently open business day not closed', function () {
            $now = Carbon::create(2026, 8, 25, 20, 0, 0);
            // À 20h, la journée métier en cours est déjà le 26 (après 18:02).
            $enCours = Carbon::create(2026, 8, 26);

            expect(resolver()->isClosed($enCours, $now))->toBeFalse();
        });
    });
});
