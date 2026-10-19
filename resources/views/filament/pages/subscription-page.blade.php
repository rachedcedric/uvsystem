<x-filament-panels::page>
    <div class="acct space-y-6">
        @if (! $subscription)
            <x-filament::section icon="heroicon-o-information-circle">
                <x-slot name="heading">Aucun abonnement enregistré</x-slot>
                <p class="acct-body">
                    Votre entreprise fonctionne actuellement sans limite d'offre. Contactez l'administrateur de la plateforme pour activer un abonnement.
                </p>
            </x-filament::section>
        @else
            @if ($restricted || $warnExpiry)
                <div @class([
                    'acct-alert',
                    'acct-alert--danger' => $restricted,
                    'acct-alert--warning' => ! $restricted,
                ]) role="status">
                    <strong>{{ $restricted ? 'Compte en consultation seule.' : 'Échéance proche.' }}</strong>
                    {{ $restricted
                        ? 'Vos données et exports restent disponibles, mais les modifications sont bloquées jusqu’à la réactivation.'
                        : 'Votre abonnement expire dans '.$days.' jour'.($days > 1 ? 's' : '').'.' }}
                </div>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ $plan->name }}</x-slot>
                <x-slot name="description">
                    {{ $subscription->cycle->label() }} · {{ number_format($subscription->applied_price, 0, ',', ' ') }} FCFA
                </x-slot>
                <x-slot name="headerEnd">
                    <x-filament::badge :color="$status->color()">{{ $status->label() }}</x-filament::badge>
                </x-slot>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="acct-tile">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Prochaine échéance</p>
                        <p class="mt-1 font-semibold text-gray-950 dark:text-white">
                            {{ $subscription->endsAt()?->format('d/m/Y') ?? 'Sans échéance' }}
                        </p>
                    </div>
                    <div class="acct-tile">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Jours restants</p>
                        <p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $days ?? 'Illimité' }}</p>
                    </div>
                    <div class="acct-tile">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Renouvellement automatique</p>
                        <div class="mt-2">
                            <x-filament::button
                                size="sm"
                                :color="$subscription->auto_renew ? 'success' : 'gray'"
                                wire:click="toggleAutoRenew"
                                wire:loading.attr="disabled"
                                wire:target="toggleAutoRenew"
                            >
                                {{ $subscription->auto_renew ? 'Activé' : 'Désactivé' }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    @if (config('subscriptions.default_gateway') === 'jeko')
                        <div class="mb-4 max-w-sm">
                            <label for="payment-method" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Moyen de paiement</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="payment-method" wire:model="paymentMethod">
                                    <option value="">Choisir un moyen de paiement</option>
                                    @foreach ($paymentMethods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif
                    <x-filament::button
                        icon="heroicon-o-banknotes"
                        wire:click="renew"
                        wire:loading.attr="disabled"
                        wire:target="renew"
                    >
                        {{ config('subscriptions.default_gateway') === 'jeko' ? 'Renouveler avec Jèko' : 'Demander le renouvellement' }}
                    </x-filament::button>
                </div>
            </x-filament::section>

            <x-filament::section heading="Utilisation de l’offre">
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach ([
                        ['label' => 'Utilisateurs', 'icon' => 'heroicon-o-users', 'usage' => $users],
                        ['label' => 'Agents', 'icon' => 'heroicon-o-user-group', 'usage' => $agents],
                        ['label' => 'Stockage', 'icon' => 'heroicon-o-circle-stack', 'usage' => $storage],
                    ] as $quota)
                        @php
                            $limit = $quota['usage']['limit'];
                            $used = $quota['usage']['used'];
                            $reached = $limit !== null && $used >= $limit;
                        @endphp
                        <div @class([
                            'acct-tile',
                            'acct-tile--danger' => $reached,
                        ])>
                            <div class="flex items-center gap-3">
                                <x-filament::icon :icon="$quota['icon']" class="h-5 w-5 text-primary-600" />
                                <p class="font-medium text-gray-950 dark:text-white">{{ $quota['label'] }}</p>
                            </div>
                            <p class="mt-3 text-2xl font-semibold text-gray-950 dark:text-white">
                                {{ number_format($used, is_float($used) ? 1 : 0, ',', ' ') }}
                                <span class="acct-muted acct-tile__limit">/ {{ $limit === null ? 'illimité' : number_format($limit, 0, ',', ' ') }}</span>
                            </p>
                            @if ($reached)
                                <p class="acct-tile__flag">Limite atteinte</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            @if ($pendingRequest || $pendingPayments->isNotEmpty())
                <x-filament::section heading="Actions en attente" icon="heroicon-o-clock">
                    <div class="space-y-3">
                        @if ($pendingRequest)
                            <div class="acct-alert acct-alert--warning acct-pending">
                                <div>
                                    <p class="acct-pending__title">Passage à l’offre {{ $pendingRequest->plan->name }}</p>
                                    <p class="acct-pending__text">La demande sera appliquée après confirmation du paiement.</p>
                                </div>
                                <x-filament::button color="gray" wire:click="cancelRequest" wire:confirm="Annuler cette demande de changement ?">
                                    Annuler la demande
                                </x-filament::button>
                            </div>
                        @endif
                        @foreach ($pendingPayments as $payment)
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="acct-body">
                                    Paiement en attente : <strong>{{ number_format($payment->amount, 0, ',', ' ') }} FCFA</strong>.
                                </p>
                                @if ($payment->gateway === 'jeko')
                                    <x-filament::button size="sm" icon="heroicon-o-arrow-top-right-on-square" wire:click="pay({{ $payment->id }})">
                                        Payer avec Jèko
                                    </x-filament::button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section heading="Fonctionnalités incluses">
                <div class="acct-features">
                    @forelse ($features as $feature)
                        <div class="acct-feature">
                            <x-filament::icon icon="heroicon-o-check-circle" class="acct-check" />
                            <span>{{ $feature }}</span>
                        </div>
                    @empty
                        <p class="acct-muted">Aucune fonctionnalité spécifique listée.</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Comparer les offres">
            <x-slot name="description">Le passage à l'annuel est facturé au tarif annuel complet. Les changements mensuels tiennent compte de la période déjà payée.</x-slot>

            <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Cycle de facturation">
                @foreach (\App\Enums\SubscriptionCycle::cases() as $option)
                    <x-filament::button
                        :color="$viewCycle === $option ? 'primary' : 'gray'"
                        wire:click="setCycle('{{ $option->value }}')"
                        wire:loading.attr="disabled"
                        wire:target="setCycle"
                    >
                        {{ $option->label() }}
                    </x-filament::button>
                @endforeach
            </div>

            <div class="acct-offers">
                @foreach ($catalog as $offer)
                    @php
                        $isCurrent = $subscription && $offer->id === $plan?->id && $subscription->cycle === $viewCycle;
                        $quote = $quotes[$offer->id] ?? null;
                    @endphp
                    <div class="acct-offer">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-gray-950 dark:text-white">{{ $offer->name }}</p>
                                @if ($isCurrent)
                                    <x-filament::badge color="success">Offre actuelle</x-filament::badge>
                                @endif
                            </div>
                            <p class="acct-body acct-offer__desc">{{ $offer->description }}</p>
                            <p class="acct-muted acct-offer__limits">
                                {{ $offer->max_users === null ? 'Utilisateurs illimités' : $offer->max_users.' utilisateurs' }}
                                ·
                                {{ $offer->max_agents === null ? 'Agents illimités' : $offer->max_agents.' agents' }}
                            </p>
                        </div>
                        <div class="acct-offer__buy">
                            <div class="text-right">
                                <p class="font-semibold text-gray-950 dark:text-white">
                                    {{ number_format($offer->priceFor($viewCycle), 0, ',', ' ') }} FCFA
                                </p>
                                @if ($quote && ! $isCurrent)
                                    <p class="acct-muted acct-offer__due">À régler maintenant : {{ number_format($quote['amount_due'], 0, ',', ' ') }} FCFA</p>
                                @endif
                            </div>
                            @if (! $isCurrent && $subscription)
                                <x-filament::button
                                    wire:click="requestChange({{ $offer->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="requestChange({{ $offer->id }})"
                                >
                                    Choisir
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
