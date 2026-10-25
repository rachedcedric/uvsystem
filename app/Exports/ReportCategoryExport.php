<?php

namespace App\Exports;

use App\Services\Reports\DateRange;
use App\Services\Reports\ReportCategory;
use App\Services\Reports\ReportPresenter;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel d'une catégorie de rapport : exactement les lignes de l'écran pour la période et les filtres choisis,
 * montants numériques (format « 1 250 000 »), une ligne de total, et la période en tête du titre de l'onglet.
 */
class ReportCategoryExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    private ?array $data = null;

    public function __construct(
        private readonly ReportCategory $category,
        private readonly DateRange $range,
        private readonly array $filters = [],
    ) {}

    public function headings(): array
    {
        return array_map(fn (array $c) => $c['label'], $this->category->columns());
    }

    public function array(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $columns = $this->category->columns();
        $rows = $this->category->rows($this->range, $this->filters)
            ->map(fn (array $row) => array_map(fn ($cell, $i) => ReportPresenter::excel($cell, $columns[$i]['type']), $row['cells'], array_keys($row['cells'])))
            ->all();

        $summary = $this->category->summary($this->range, $this->filters);
        $total = array_fill(0, count($columns), '');
        $total[0] = 'Total ('.$summary['count'].' ligne'.($summary['count'] > 1 ? 's' : '').')';
        $footer = $summary['footer'] ?? [array_key_last($columns) => $summary['total']];
        foreach ($footer as $i => $value) {
            $total[$i] = round((float) $value, 2);
        }
        $rows[] = $total;

        return $this->data = $rows;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->category->columns() as $i => $column) {
            if (in_array($column['type'], ['money', 'signed'], true)) {
                $formats[Coordinate::stringFromColumnIndex($i + 1)] = '#,##0;-#,##0;0';
            }
        }

        return $formats;
    }

    public function styles(Worksheet $sheet)
    {
        $last = count($this->array()) + 1;

        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0B6B3A']]],
            $last => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return Str::limit($this->category->label().' '.$this->range->from->format('d-m').'_'.$this->range->to->format('d-m-y'), 31, '');
    }
}
