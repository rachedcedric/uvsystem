<?php

use App\Services\BusinessPeriodResolver;
use App\Services\CommissionBusinessDateResolver;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function periodResolver(): BusinessPeriodResolver
{
    return app(BusinessPeriodResolver::class);
}

describe('BusinessPeriodResolver::today', function () {
    it('keeps 17:59:59 on the current business day, capped at now', function () {
        $now = Carbon::create(2026, 3, 10, 17, 59, 59);
        $range = periodResolver()->today($now);

        expect($range['businessDate']->toDateString())->toBe('2026-03-10')
            ->and($range['start']->toDateTimeString())->toBe('2026-03-09 18:02:00')
            ->and($range['end']->toDateTimeString())->toBe('2026-03-10 17:59:59');
    });

    it('bumps 18:02:00 to the next business day', function () {
        $now = Carbon::create(2026, 3, 10, 18, 2, 0);
        $range = periodResolver()->today($now);

        expect($range['businessDate']->toDateString())->toBe('2026-03-11')
            ->and($range['start']->toDateTimeString())->toBe('2026-03-10 18:02:00')
            ->and($range['end']->toDateTimeString())->toBe('2026-03-10 18:02:00');
    });

    it('matches CommissionBusinessDateResolver::resolve() for the same instant', function () {
        $now = Carbon::create(2026, 3, 10, 10, 0, 0);
        $expected = app(CommissionBusinessDateResolver::class)->resolve($now);

        $range = periodResolver()->today($now);

        expect($range['businessDate']->toDateString())->toBe($expected->toDateString())
            ->and($range['end']->equalTo($now))->toBeTrue();
    });
});

describe('BusinessPeriodResolver::yesterday', function () {
    it('returns the previous business day, fully closed', function () {
        $now = Carbon::create(2026, 3, 10, 10, 0, 0);
        $range = periodResolver()->yesterday($now);

        expect($range['businessDate']->toDateString())->toBe('2026-03-09')
            ->and($range['start']->toDateTimeString())->toBe('2026-03-08 18:02:00')
            ->and($range['end']->toDateTimeString())->toBe('2026-03-09 18:01:59');
    });
});

describe('BusinessPeriodResolver::thisWeek / thisMonth', function () {
    it('starts the week at the business-day boundary of the week start', function () {
        $now = Carbon::create(2026, 3, 10, 10, 0, 0);
        $expectedStart = $now->copy()->startOfWeek()->subDay()->setTime(18, 2, 0);

        $range = periodResolver()->thisWeek($now);

        expect($range['start']->equalTo($expectedStart))->toBeTrue()
            ->and($range['end']->equalTo($now))->toBeTrue();
    });

    it('starts the month at the business-day boundary of the 1st', function () {
        $now = Carbon::create(2026, 3, 10, 10, 0, 0);
        $expectedStart = $now->copy()->startOfMonth()->subDay()->setTime(18, 2, 0);

        $range = periodResolver()->thisMonth($now);

        expect($range['start']->equalTo($expectedStart))->toBeTrue()
            ->and($range['end']->equalTo($now))->toBeTrue();
    });
});

describe('BusinessPeriodResolver::nextCutoff', function () {
    it('points to today at 18:02 when now is before the cutoff', function () {
        $now = Carbon::create(2026, 3, 10, 9, 0, 0);
        expect(periodResolver()->nextCutoff($now)->toDateTimeString())->toBe('2026-03-10 18:02:00');
    });

    it('points to tomorrow at 18:02 when now is at or after the cutoff', function () {
        $now = Carbon::create(2026, 3, 10, 18, 2, 0);
        expect(periodResolver()->nextCutoff($now)->toDateTimeString())->toBe('2026-03-11 18:02:00');
    });
});
