<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Période d'un rapport ou d'un export : raccourci (aujourd'hui, hier, 7 derniers jours, ce mois, mois précédent) ou
 * dates libres. Toujours dans le fuseau de l'application (Africa/Abidjan) ; la date de fin est INCLUSE en entier
 * (jusqu'à 23:59:59). Les filtres SQL utilisent [début, lendemain de la fin[ pour ne jamais perdre une seconde.
 */
final class DateRange
{
    public const PRESETS = [
        'today' => 'Aujourd\'hui',
        'yesterday' => 'Hier',
        '7d' => '7 derniers jours',
        'month' => 'Ce mois',
        'last_month' => 'Mois précédent',
        'custom' => 'Période personnalisée',
    ];

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $preset,
    ) {}

    /**
     * @param  array{periode?: ?string, du?: ?string, au?: ?string}  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $preset = (string) ($input['periode'] ?? 'month');
        $today = Carbon::today(config('app.timezone'));

        return match ($preset) {
            'today' => new self($today->copy(), $today->copy(), $preset),
            'yesterday' => new self($today->copy()->subDay(), $today->copy()->subDay(), $preset),
            '7d' => new self($today->copy()->subDays(6), $today->copy(), $preset),
            'last_month' => new self($today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(), $preset),
            'custom' => self::custom($input['du'] ?? null, $input['au'] ?? null),
            'month' => new self($today->copy()->startOfMonth(), $today->copy(), 'month'),
            default => throw ValidationException::withMessages(['periode' => 'Période inconnue.']),
        };
    }

    /**
     * @throws ValidationException
     */
    public static function custom(?string $du, ?string $au): self
    {
        $errors = [];
        $from = self::parse($du);
        $to = self::parse($au);

        if (! $from) {
            $errors['du'] = 'Indiquez une date de début valide.';
        }
        if (! $to) {
            $errors['au'] = 'Indiquez une date de fin valide.';
        }
        if ($from && $to && $from->greaterThan($to)) {
            $errors['au'] = 'La date de fin doit être égale ou postérieure à la date de début.';
        }
        if ($from && $from->greaterThan(Carbon::today(config('app.timezone')))) {
            $errors['du'] = 'La date de début ne peut pas être dans le futur.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($from, $to, 'custom');
    }

    private static function parse(?string $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Premier instant inclus. */
    public function start(): Carbon
    {
        return $this->from->copy()->startOfDay();
    }

    /** Dernier instant inclus (23:59:59 de la date de fin). */
    public function end(): Carbon
    {
        return $this->to->copy()->endOfDay();
    }

    /** Borne exclue : début du lendemain de la date de fin. */
    public function endExclusive(): Carbon
    {
        return $this->to->copy()->addDay()->startOfDay();
    }

    public function label(): string
    {
        return self::PRESETS[$this->preset] ?? 'Période';
    }

    public function fromLabel(): string
    {
        return $this->from->format('d/m/Y');
    }

    public function toLabel(): string
    {
        return $this->to->format('d/m/Y');
    }

    public function description(): string
    {
        return $this->from->equalTo($this->to)
            ? 'Le '.$this->fromLabel()
            : 'Du '.$this->fromLabel().' au '.$this->toLabel();
    }

    /** @return array{periode: string, du: string, au: string} paramètres d'URL des exports */
    public function toQuery(): array
    {
        return ['periode' => $this->preset, 'du' => $this->from->toDateString(), 'au' => $this->to->toDateString()];
    }
}
