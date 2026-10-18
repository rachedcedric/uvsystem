<?php

namespace App\Support;

/**
 * Adresse d'une image de marque (icône, logo) avec un numéro de version : la date de modification du fichier.
 *
 * Le serveur web sert les images avec « Cache-Control: public, immutable » pendant 30 jours : un navigateur qui a déjà chargé
 * /favicon-32.png ne le redemande plus, même après un rechargement. Une adresse qui change avec le fichier (?v=…) force tous
 * les navigateurs à charger la nouvelle image dès qu'elle est remplacée.
 */
final class BrandAsset
{
    public static function url(string $path): string
    {
        return asset($path).self::version($path);
    }

    /** Suffixe « ?v=… » seul, pour les adresses construites ailleurs (e-mails : URL absolue de config/branding.php). */
    public static function version(string $path): string
    {
        $file = public_path($path);

        return is_file($file) ? '?v='.filemtime($file) : '';
    }

    /** Image de marque intégrée en base64 : les PDF (DomPDF) et les pages hors ligne n'ont pas à retourner la chercher. */
    public static function dataUri(string $path): ?string
    {
        $file = public_path($path);

        if (! is_file($file)) {
            return null;
        }

        $mime = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : (mime_content_type($file) ?: 'application/octet-stream');

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
    }
}
