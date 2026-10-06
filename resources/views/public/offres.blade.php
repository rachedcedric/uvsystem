@extends('layouts.public')
@section('title', 'Offres')
@section('content')
    <h1>Choisissez votre offre</h1>
    <p>Essai gratuit, sans engagement. Vos données vous appartiennent et sont toujours conservées.</p>

    <nav class="seg" aria-label="Périodicité">
        @foreach (\App\Enums\SubscriptionCycle::cases() as $option)
            <a href="{{ route('offres', ['cycle' => $option->value]) }}" class="{{ $cycle === $option ? 'on' : '' }}" @if ($cycle === $option) aria-current="true" @endif>{{ $option->label() }}</a>
        @endforeach
    </nav>

    <div class="grid">
        @forelse ($plans as $item)
            @php($plan = $item['plan'])
            <article class="plan {{ $plan->is_featured ? 'featured' : '' }} {{ $plan->visual_variant === 'accent' ? 'accent' : '' }}">
                @if ($plan->is_featured)<span class="tag">Recommandée</span>@endif
                <h2>{{ $plan->name }}</h2>
                @if ($plan->description)<p>{{ $plan->description }}</p>@endif
                <div class="price">{{ number_format($item['price'], 0, ',', ' ') }} FCFA <small>/ {{ $cycle === \App\Enums\SubscriptionCycle::Yearly ? 'an' : 'mois' }}</small></div>
                <ul>
                    <li><x-m.icon name="check" />{{ $item['users'] }}</li>
                    <li><x-m.icon name="check" />{{ $item['agents'] }}</li>
                    @foreach ($item['features'] as $feature)
                        <li><x-m.icon name="check" />{{ $feature }}</li>
                    @endforeach
                </ul>
                <a class="btn" href="{{ route('souscrire', ['plan' => $plan->slug, 'cycle' => $cycle->value]) }}">
                    {{ $plan->trial_days > 0 ? 'Essayer '.$plan->trial_days.' jours' : 'Choisir cette offre' }}
                </a>
            </article>
        @empty
            <p>Aucune offre n'est disponible pour le moment.</p>
        @endforelse
    </div>
@endsection
