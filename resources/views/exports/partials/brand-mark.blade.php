{{-- Logo UVMASTER pour les en-têtes de PDF : pastille blanche (le logo a un texte sombre) sur le bandeau de couleur. --}}
@php($brandLogo = \App\Support\BrandAsset::dataUri('images/logo-wide.png'))
@if ($brandLogo)
    <span style="display: inline-block; vertical-align: middle; background: #fff; border-radius: 6px; padding: 5px 9px; margin-right: 8px;"><img src="{{ $brandLogo }}" alt="{{ config('branding.name') }}" style="height: 24px;"></span>
@endif
