<div>
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

    <x-m.section title="Changer d'offre">
        <div class="m-group">
            @foreach ($catalog as $offer)
                <x-m.row :icon="$plan && $offer->id === $plan->id ? 'check-circle' : 'rectangle-stack'" :chevron="false" :title="$offer->name"
                         :sub="number_format($offer->monthly_price, 0, ',', ' ').' FCFA / mois — '.number_format($offer->yearly_price, 0, ',', ' ').' FCFA / an — '.($offer->max_users ?? 'utilisateurs illimités').($offer->max_users ? ' utilisateurs' : '').', '.($offer->max_agents ?? 'agents illimités').($offer->max_agents ? ' agents' : '')" />
            @endforeach
        </div>
        <p class="m-row__sub" style="padding:8px 14px;white-space:normal">Le changement d'offre est effectué par l'administrateur de la plateforme : contactez-le pour passer à une autre offre. Vos données existantes sont toujours conservées.</p>
    </x-m.section>
</div>
