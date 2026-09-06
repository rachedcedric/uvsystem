<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    /**
     * Download a backup file.
     */
    public function download(string $path): StreamedResponse
    {
        $decodedPath = base64_decode($path);
        $disk = Storage::disk('local');

        if (! $disk->exists($decodedPath)) {
            abort(404, 'Backup not found');
        }

        $filename = basename($decodedPath);

        return $disk->download($decodedPath, $filename);
    }
}
