<?php

namespace App\Support\Subscriptions;

use Maatwebsite\Excel\Excel;

/** Excel/CSV dont la génération est soumise aux fonctionnalités de l'offre (voir ExportGate). */
class GatedExcel extends Excel
{
    public function download($export, string $fileName, ?string $writerType = null, array $headers = [])
    {
        ExportGate::checkExcel($export);

        return parent::download($export, $fileName, $writerType, $headers);
    }

    public function store($export, string $filePath, ?string $diskName = null, ?string $writerType = null, $diskOptions = [], ?string $disk = null)
    {
        ExportGate::checkExcel($export);

        return parent::store($export, $filePath, $diskName, $writerType, $diskOptions, $disk);
    }

    public function raw($export, string $writerType)
    {
        ExportGate::checkExcel($export);

        return parent::raw($export, $writerType);
    }
}
