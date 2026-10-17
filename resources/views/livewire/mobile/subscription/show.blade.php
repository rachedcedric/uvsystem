<div x-data @uvmaster-browser-finished.window="$wire.$refresh()"
     @if ($pendingPayments->contains('gateway', 'jeko')) wire:poll.10s @endif>
    <x-m.appbar title="Mon abonnement" :sub="$plan ? 'Offre '.$plan->name : null" :back="route('m.more')" />

    @if (! $subscription)
        <div class="m-group" style="margin-top:6px">
            <x-m.row icon="information-circle" tone="warn" :chevron="false" title="Aucun abonnement enregistré"
                     sub="Votre société n'a pas encore d'abonnement : aucune limite ne s'applique. Contactez la plateforme pour en définir un." />
        </div>
    @else
        @if ($restricted)
            <div class="m-group" style="margin-top:6px">
                <x-m.row icon="exclamation-triangle" tone="danger" :chevron="false" title="Consultation seule"
                         sub="Votre abonnement n'est plus actif : vos données et vos exports restent accessibles, mais aucune modification n'est possible. Contactez l'administrateur de la plateforme pour le réactiver." />
            </div>
        @elseif ($warnExpiry)
            <div class="m-group" style="margin-top:6px">
                <x-m.row icon="clock" tone="warn" :chevron="false"
                         :title="'Expire dans '.$days.' jour'.($days > 1 ? 's' : '')"
                         sub="Pensez à renouveler pour éviter le passage en consultation seule." />
            </div>
        @endif

        <x-m.section title="Offre actuelle">
            <div class="m-group">
                <x-m.row icon="credit-card" :chevron="false" :title="$plan->name" :sub="$subscription->cycle->label().' — '.number_format($subscription->applied_price, 0, ',', ' ').' FCFA'">
                    <x-slot:end><span class="m-badge m-badge--{{ match ($status->color()) { 'success' => 'ok', 'danger' => 'danger', 'warning', 'info' => 'warn', default => 'brand' } }}">{{ $status->label() }}</span></x-slot:end>
                </x-m.row>
                @if ($subscription->isTrial() || $subscription->trial_ends_at)
                    <x-m.row icon="beaker" :chevron="false" title="Période d'essai"
                             :sub="$subscription->trial_ends_at ? 'Jusqu\'au '.$subscription->trial_ends_at->format('d/m/Y') : '—'" />
                @endif
                <x-m.row icon="arrow-path" :chevron="false" title="Renouvellement"
                         :sub="$subscription->current_period_ends_at ? 'Prochaine échéance le '.$subscription->current_period_ends_at->format('d/m/Y') : ($subscription->isTrial() ? 'Au terme de l\'essai' : 'Sans échéance')" />
                @if ($days !== null)
                    <x-m.row icon="clock" :chevron="false" title="Jours restants" :sub="$days.' jour'.($days > 1 ? 's' : '')" />
                @endif
            </div>
        </x-m.section>

        <x-m.section title="Utilisation">
            <div class="m-group">
                <x-m.row icon="users" :chevron="false" title="Utilisateurs"
                         :sub="$users['used'].' / '.($users['limit'] === null ? 'illimité' : $users['limit'])" />
                <x-m.row icon="user-group" :chevron="false" title="Agents"
                         :sub="$agents['used'].' / '.($agents['limit'] === null ? 'illimité' : $agents['limit'])" />
                @if (($users['limit'] !== null && $users['used'] >= $users['limit']) || ($agents['limit'] !== null && $agents['used'] >= $agents['limit']))
                    <x-m.row icon="exclamation-triangle" tone="warn" :chevron="false" title="Limite atteinte"
                             sub="Vos données sont conservées ; il n'est plus possible d'en ajouter tant que vous n'avez pas changé d'offre." />
                @endif
            </div>
        </x-m.section>

        <x-m.section title="Fonctionnalités incluses">
            <div class="m-group">
                @forelse ($features as $feature)
                    <x-m.row icon="check" tone="ok" :chevron="false" :title="$feature" />
                @empty
                    <x-m.row icon="information-circle" :chevron="false" title="Aucune fonctionnalité listée" />
                @endforelse
            </div>
        </x-m.section>
    @endif

    @if ($message)<x-m.callout icon="check-circle" style="margin:6px 12px 0" role="status">{{ $message }}</x-m.callout>@endif
    @if ($error)<x-m.callout tone="danger" icon="exclamation-triangle" style="margin:6px 12px 0">{{ $error }}</x-m.callout>@endif

    @if ($subscription)
        <x-m.section title="Renouvellement">
            <div class="m-group">
                <x-m.row icon="arrow-path" :chevron="false" title="Renouvellement automatique"
                         :sub="$subscription->auto_renew ? 'Activée : une facture est émise à l\'échéance.' : 'Désactivée.'">
                    <x-slot:end><button type="button" class="m-btn m-btn--ghost" style="min-height:44px;min-width:44px" wire:click="toggleAutoRenew">{{ $subscription->auto_renew ? 'Désactiver' : 'Activer' }}</button></x-slot:end>
                </x-m.row>
                <x-m.row icon="banknotes" :chevron="false" title="Renouveler maintenant" sub="Paiement sécurisé via Jèko.">
                    <x-slot:end><button type="button" class="m-btn" style="min-height:44px" wire:click="renew">Payer</button></x-slot:end>
                </x-m.row>
            </div>
        </x-m.section>
    @endif

    @if ($pendingPayments->isNotEmpty())
        <x-m.section title="Paiements en attente">
            <div class="m-group">
                @foreach ($pendingPayments as $payment)
                    <x-m.row icon="banknotes" tone="warn" :chevron="false" :title="number_format($payment->amount, 0, ',', ' ').' FCFA'"
                             :sub="$payment->gateway === 'jeko' ? 'Jèko · valable jusqu\'au '.app(\App\Services\Subscriptions\SubscriptionBilling::class)->expiresAt($payment)->format('d/m à H:i') : (($payment->metadata['purpose'] ?? 'renewal') === 'plan_change' ? 'Changement d\'offre — communiquez votre règlement à la plateforme.' : 'Renouvellement — communiquez votre règlement à la plateforme.')">
                        @if ($payment->gateway === 'jeko')
                            <x-slot:end><button type="button" class="m-btn" style="min-height:44px" wire:click="pay({{ $payment->id }})">Payer</button></x-slot:end>
                        @endif
                    </x-m.row>
                @endforeach
            </div>
        </x-m.section>
    @endif

    <x-m.section title="Changer d'offre">
        <nav class="m-chips" aria-label="Cycle" style="padding:0 14px 8px">
            @foreach (\App\Enums\SubscriptionCycle::cases() as $option)
                <button type="button" class="m-chip {{ $viewCycle === $option ? 'is-on' : '' }}" aria-pressed="{{ $viewCycle === $option ? 'true' : 'false' }}" style="min-height:44px;min-width:44px" wire:click="$set('cycle', '{{ $option->value }}')">{{ $option->label() }}</button>
            @endforeach
        </nav>
        @if ($pendingRequest)
            <div class="m-group" style="margin-bottom:8px">
                <x-m.row icon="clock" tone="warn" :chevron="false" :title="'Demande en cours : '.$pendingRequest->plan->name"
                         sub="En attente de votre paiement.">
                    <x-slot:end><button type="button" class="m-btn m-btn--ghost" style="min-height:44px" wire:click="cancelRequest">Annuler</button></x-slot:end>
                </x-m.row>
            </div>
        @endif
        <div class="m-group">
            @foreach ($catalog as $offer)
                @php($isCurrent = $plan && $offer->id === $plan->id && $subscription->cycle === $viewCycle)
                @php($quote = $quotes[$offer->id] ?? null)
                <x-m.row :icon="$isCurrent ? 'check-circle' : 'rectangle-stack'" :chevron="false" :title="$offer->name"
                         :sub="number_format($offer->priceFor($viewCycle), 0, ',', ' ').' FCFA / '.($viewCycle === \App\Enums\SubscriptionCycle::Yearly ? 'an' : 'mois').' — '.($offer->max_users ?? 'utilisateurs illimités').($offer->max_users ? ' utilisateurs' : '').', '.($offer->max_agents ?? 'agents illimités').($offer->max_agents ? ' agents' : '').($quote && ! $isCurrent && $subscription && $subscription->current_period_ends_at ? ' — à régler : '.number_format($quote['amount_due'], 0, ',', ' ').' FCFA (prorata déduit)' : '')">
                    @unless ($isCurrent || ! $subscription)
                        <x-slot:end><button type="button" class="m-btn" style="min-height:44px;min-width:44px" wire:click="requestChange({{ $offer->id }}, '{{ $viewCycle->value }}')">Choisir</button></x-slot:end>
                    @endunless
                </x-m.row>
            @endforeach
        </div>
        <p class="m-row__sub" style="padding:8px 14px;white-space:normal">Le montant à régler tient compte de la part non consommée de votre période en cours. Si rien n'est à payer, le changement est appliqué immédiatement ; sinon il s'applique à la confirmation de votre paiement. Vos données existantes sont toujours conservées.</p>
    </x-m.section>

    @if (count($backups))
        <x-m.section title="Sauvegardes dédiées">
            <div class="m-group">
                @foreach ($backups as $file)
                    <a href="{{ route('exports.tenant-backup', $file) }}" class="m-row">
                        <span class="m-row__lead"><x-m.icon name="cloud-arrow-down" class="m-ico-sm" /></span>
                        <span class="m-row__body"><span class="m-row__title" style="display:block">{{ $file }}</span></span>
                    </a>
                @endforeach
            </div>
        </x-m.section>
    @endif
</div>
