<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Mise en forme des cellules d'une catégorie : écran / PDF (texte lisible, montants complets « 1 250 000 », dates
 * JJ/MM/AAAA) et Excel (montants numériques exploitables, dates lisibles). Le signe « − » d'un crédit ou d'un
 * découvert est toujours conservé.
 */
class ReportPresenter
{
    public static function text(mixed $value, string $type): string
    {
        return match ($type) {
            'datetime' => self::date($value, 'd/m/Y H:i'),
            'date' => self::date($value, 'd/m/Y'),
            'money', 'signed' => self::money($value),
            default => $value === null || $value === '' ? '—' : (string) $value,
        };
    }

    public static function excel(mixed $value, string $type): mixed
    {
        return match ($type) {
            'datetime' => self::date($value, 'd/m/Y H:i'),
            'date' => self::date($value, 'd/m/Y'),
            'money', 'signed' => round((float) $value, 2),
            default => $value === null ? '' : (string) $value,
        };
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 0, ',', ' ');
    }

    public static function fcfa(mixed $value): string
    {
        return self::money($value).' FCFA';
    }

    /** Colonnes « texte » jamais utilisées comme titre d'une ligne mobile (elles vont dans le sous-titre). */
    private const NOT_TITLE = ['Référence', 'Type', 'Téléphone', 'Ventes', 'Mode', 'Impact', 'Saisi par', 'Catégorie'];

    /**
     * Ligne compacte pour un écran de téléphone : un titre (nom, libellé…), un sous-titre (date, référence…), le
     * montant principal (dernière colonne montant) et le statut.
     *
     * @param  array{cells: list<mixed>, cancelled?: bool}  $row
     * @param  list<array{label: string, type: string}>  $columns
     * @return array{title: string, sub: string, amount: ?float, signed: bool, status: ?string, cancelled: bool}
     */
    public static function mobile(array $row, array $columns, string $fallbackTitle): array
    {
        $title = null;
        $sub = [];
        $amount = null;
        $signed = false;
        $status = null;

        foreach ($columns as $i => $column) {
            $value = $row['cells'][$i] ?? null;

            match ($column['type']) {
                'datetime', 'date' => $sub[] = self::text($value, $column['type']),
                'money', 'signed' => [$amount = (float) $value, $signed = $column['type'] === 'signed'],
                'status' => $status = (string) $value,
                default => $title === null && ! in_array($column['label'], self::NOT_TITLE, true) && filled($value) && $value !== '—'
                    ? $title = (string) $value
                    : (filled($value) && $value !== '—' ? $sub[] = ($column['label'] === 'Ventes' ? $value.' vente(s)' : (string) $value) : null),
            };
        }

        return [
            'title' => $title ?? $fallbackTitle,
            'sub' => implode(' · ', $sub),
            'amount' => $amount,
            'signed' => $signed,
            'status' => $status,
            'cancelled' => (bool) ($row['cancelled'] ?? false),
        ];
    }

    /** Ton d'un montant signé : dette (rouge) / crédit-découvert (vert) / équilibré (neutre). */
    public static function tone(float $value): string
    {
        return $value > 0.004 ? 'danger' : ($value < -0.004 ? 'ok' : 'muted');
    }

    private static function date(mixed $value, string $format): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format($format);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->format($format);
            } catch (\Throwable) {
                return $value;
            }
        }

        return '—';
    }
}
