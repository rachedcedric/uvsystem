<?php

use App\Cms\Content;
use Illuminate\Support\HtmlString;

if (! function_exists('cms')) {
    /** Contenu éditable (texte brut ; à afficher avec {{ }} qui l'échappe). */
    function cms(string $key, array $vars = []): string
    {
        return Content::text($key, $vars);
    }
}

if (! function_exists('cms_html')) {
    /** Contenu éditable multiligne : échappé, puis chaque saut de ligne devient un <br> (aucun HTML saisi n'est interprété). */
    function cms_html(string $key, array $vars = []): HtmlString
    {
        return new HtmlString(str_replace(["\r\n", "\n", "\r"], '<br>', e(Content::text($key, $vars))));
    }
}

if (! function_exists('cms_lines')) {
    /** @return list<string> */
    function cms_lines(string $key, array $vars = []): array
    {
        return Content::lines($key, $vars);
    }
}
