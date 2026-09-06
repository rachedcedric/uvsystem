<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportDownloadController extends Controller
{
    /**
     * Télécharger un fichier d'export
     */
    public function download(string $filename): BinaryFileResponse
    {
        $path = 'exports/'.$filename;

        if (! Storage::disk('public')->exists($path)) {
            abort(404, 'Fichier non trouvé');
        }

        $fullPath = Storage::disk('public')->path($path);

        return response()->download($fullPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ])->deleteFileAfterSend(false);
    }
}
