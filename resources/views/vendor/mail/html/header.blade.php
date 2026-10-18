@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- Logo de la marque (config/branding.php). L'URL est absolue : un e-mail s'ouvre hors du site. Si le client de messagerie
     bloque les images, le texte alternatif (le nom de la marque) s'affiche à la place. --}}
<img src="{{ rtrim((string) config('branding.url'), '/') }}/images/logo-wide.png{{ \App\Support\BrandAsset::version('images/logo-wide.png') }}" class="logo" alt="{{ trim($slot) }}" width="200">
</a>
</td>
</tr>
