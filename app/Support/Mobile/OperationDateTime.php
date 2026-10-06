<?php

namespace App\Support\Mobile;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class OperationDateTime
{
    /**
     * @return array{date: string, time: string}
     */
    public static function defaults(): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));

        return [
            'date' => $now->toDateString(),
            'time' => $now->format('H:i'),
        ];
    }

    public static function fromInputs(string $date, string $time, string $errorField = 'time'): string
    {
        $operationAt = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            "{$date} {$time}",
            config('app.timezone'),
        );

        if ($operationAt->greaterThan(CarbonImmutable::now(config('app.timezone'))->endOfMinute())) {
            throw ValidationException::withMessages([
                $errorField => 'La date et l’heure de l’opération ne peuvent pas être dans le futur.',
            ]);
        }

        return $operationAt->toDateTimeString();
    }
}
