@php
    $fcfa = fn ($n) => \App\Support\Mobile\Fcfa::number($n);
    // Rouge : dette à payer ; vert : découvert (avance) ; neutre : équilibré. Le signe « − » du découvert est toujours affiché.
    $tone = fn ($solde) => match (\App\Services\FournisseurDebtService::statutPour((float) $solde)) { 'dette' => 'danger', 'decouvert' => 'ok', default => 'muted' };
    $color = fn ($solde) => match ($tone($solde)) { 'danger' => 'var(--danger)', 'ok' => 'var(--ok)', default => 'var(--fg)' };
@endphp
<div>
    <x-m.appbar :title="$fournisseur->name" sub="Fiche fournisseur" :back="route('m.dettes', ['v' => 'fournisseurs'])" />

    @unless ($situation['credit'])
        <x-m.callout tone="info" icon="information-circle">Fournisseur payé comptant : ses approvisionnements ne créent aucune dette.</x-m.callout>
    @endunless

    <div class="m-grid">
        <div class="m-stat" style="grid-column: span 2">
            <div class="m-stat__label"><x-m.icon name="scale" class="m-ico-sm" /><span>Solde net</span></div>
            <div class="m-stat__value m-num" style="color:{{ $color($situation['solde']) }}">{{ $fcfa($situation['solde']) }}<span class="m-stat__unit"> FCFA</span></div>
            <div class="m-stat__sub"><x-m.status-badge :tone="$tone($situation['solde'])" :label="$situation['statut_label']" /></div>
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="truck" class="m-ico-sm" /><span>Approvisionnements</span></div>
            <div class="m-stat__value m-num">{{ $fcfa($situation['total_approvisionne']) }}<span class="m-stat__unit"> FCFA</span></div>
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="arrow-uturn-left" class="m-ico-sm" /><span>Remboursements</span></div>
            <div class="m-stat__value m-num">{{ $fcfa($situation['total_rembourse']) }}<span class="m-stat__unit"> FCFA</span></div>
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>Dette actuelle</span></div>
            <div class="m-stat__value m-num" style="color:{{ $situation['dette'] > 0 ? 'var(--danger)' : 'var(--fg)' }}">{{ $fcfa($situation['dette']) }}<span class="m-stat__unit"> FCFA</span></div>
        </div>
        <div class="m-stat">
            <div class="m-stat__label"><x-m.icon name="banknotes" class="m-ico-sm" /><span>Découvert actuel</span></div>
            <div class="m-stat__value m-num" style="color:{{ $situation['decouvert'] < 0 ? 'var(--ok)' : 'var(--fg)' }}">{{ $fcfa($situation['decouvert']) }}<span class="m-stat__unit"> FCFA</span></div>
        </div>
    </div>

    @if ($situation['derniere_operation'])
        <p class="m-note" style="margin-top:10px">Dernière opération : {{ $situation['derniere_operation']['libelle'] }} de {{ \App\Support\Mobile\Fcfa::format($situation['derniere_operation']['montant']) }} le {{ $situation['derniere_operation']['date']->format('d/m/Y à H:i') }}</p>
    @endif

    @if ($canPay)
        <div class="m-actions" style="margin-top:12px">
            <a href="{{ route('m.dettes.fournisseur', $fournisseur) }}" wire:navigate class="m-action m-action--wide" data-haptic><x-m.icon name="banknotes" /> {{ $situation['dette'] > 0 ? 'Payer ce fournisseur' : 'Verser une avance' }}</a>
        </div>
    @endif

    <x-m.section title="Historique des mouvements">
        @forelse ($groups as $date => $rows)
            <x-m.day :date="$date" />
            <div class="m-group">
                @foreach ($rows as $row)
                    @if ($row['type'] === 'remboursement')
                        @include('livewire.mobile.partials.supplier-payment', ['r' => $row['model'], 'showSupplier' => false])
                        <p class="m-row__sub m-row__sub--detail" style="padding:0 16px 10px">Solde après : <strong style="color:{{ $color($row['solde_apres']) }}">{{ \App\Support\Mobile\Fcfa::format($row['solde_apres']) }}</strong>@if ($row['note']) · {{ $row['note'] }}@endif</p>
                    @else
                        <x-m.row wire:key="fa{{ $row['model']->id }}" icon="truck" :tone="$situation['credit'] ? 'warn' : 'muted'" :chevron="false"
                                 :title="$row['libelle']" :sub="$row['reference'].' · '.$row['date']->format('H:i').($row['auteur'] ? ' · '.$row['auteur'] : '')">
                            <x-slot:body><span class="m-row__sub m-row__sub--detail">Solde après : <strong style="color:{{ $color($row['solde_apres']) }}">{{ \App\Support\Mobile\Fcfa::format($row['solde_apres']) }}</strong>@if ($row['note']) · {{ $row['note'] }}@endif</span></x-slot:body>
                            <x-slot:end><x-m.amount :value="$row['montant']" :sign="$row['impact'] > 0 ? '+' : null" class="m-row__amount" /></x-slot:end>
                        </x-m.row>
                    @endif
                @endforeach
            </div>
        @empty
            <div class="m-group"><x-m.empty icon="clock" title="Aucun mouvement" text="Aucun approvisionnement ni paiement pour ce fournisseur." /></div>
        @endforelse
    </x-m.section>

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif
</div>
