<?php

namespace App\Support\Mobile;

/** Taille de fichier lisible, en français (octets, Ko, Mo). */
class FileSize
{
    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' o';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', ' ').' Ko';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', ' ').' Mo';
    }
}
