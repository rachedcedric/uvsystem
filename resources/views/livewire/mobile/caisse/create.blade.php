@php($overdrawn = $this->totalSorties > $this->solde + $this->totalEntrees)
<div>
    <x-m.appbar title="Mouvements de caisse" :sub="\Carbon\Carbon::parse($date)->translatedFormat('l j F')" :back="route('m.caisse')" />

    <form wire:submit="save" class="m-form" novalidate>
        <div class="m-callout">
            <dl><dt>Solde actuel de la caisse</dt><dd>{{ \App\Support\Mobile\Fcfa::format($this->solde) }}</dd></dl>
        </div>

        <x-m.operation-date-time />

        @error('lines')
            <div class="m-callout m-callout--danger">
                <x-m.icon name="exclamation-triangle" class="m-ico-sm" />
                <span>{{ $message }}</span>
            </div>
        @enderror

        @foreach ($lines as $i => $line)
            <section class="m-line" wire:key="line-{{ $i }}-{{ count($lines) }}">
                <header class="m-line__head">
                    <strong>Ligne {{ $i + 1 }}</strong>
                    @if (count($lines) > 1)
                        <button type="button" class="m-line__remove" wire:click="removeLine({{ $i }})" data-haptic aria-label="Supprimer la ligne {{ $i + 1 }}">
                            <x-m.icon name="trash" class="m-ico-sm" /> Supprimer
                        </button>
                    @endif
                </header>

                <div class="m-chips" style="padding:0 0 12px;gap:10px">
                    <button type="button" class="m-chip {{ $line['type'] === 'entree' ? 'is-on' : '' }}" aria-pressed="{{ $line['type'] === 'entree' ? 'true' : 'false' }}" style="flex:1;height:44px" wire:click="$set('lines.{{ $i }}.type', 'entree')" data-haptic>Entrée</button>
                    <button type="button" class="m-chip {{ $line['type'] === 'sortie' ? 'is-on' : '' }}" aria-pressed="{{ $line['type'] === 'sortie' ? 'true' : 'false' }}" style="flex:1;height:44px" wire:click="$set('lines.{{ $i }}.type', 'sortie')" data-haptic>Sortie</button>
                </div>

                <x-m.field label="Montant" :error="$errors->first('lines.'.$i.'.amount')">
                    <x-m.money :model="'lines.'.$i.'.amount'" />
                </x-m.field>

                <x-m.field label="Libellé" :error="$errors->first('lines.'.$i.'.libelle')">
                    <input type="text" wire:model="lines.{{ $i }}.libelle" class="m-input @error('lines.'.$i.'.libelle') m-input--error @enderror" maxlength="150" placeholder="Ex. : Versement agent, achat carburant">
                </x-m.field>

                <x-m.field label="Catégorie" :error="$errors->first('lines.'.$i.'.categorieId')">
                    <div class="m-chips" style="padding:0;flex-wrap:wrap">
                        @foreach ($this->categories as $categorie)
                            <button type="button" class="m-chip {{ $line['categorieId'] === $categorie->id ? 'is-on' : '' }}"
                                    aria-pressed="{{ $line['categorieId'] === $categorie->id ? 'true' : 'false' }}"
                                    wire:click="$set('lines.{{ $i }}.categorieId', {{ $line['categorieId'] === $categorie->id ? 'null' : $categorie->id }})" data-haptic>{{ $categorie->name }}</button>
                        @endforeach
                    </div>
                </x-m.field>

                <x-m.field label="Notes (facultatif)" :error="$errors->first('lines.'.$i.'.notes')">
                    <input type="text" wire:model="lines.{{ $i }}.notes" class="m-input" maxlength="500">
                </x-m.field>
            </section>
        @endforeach

        @if (count($lines) < 50)
            <button type="button" class="m-btn m-btn--ghost m-line__add" wire:click="addLine" data-haptic>
                <x-m.icon name="plus" class="m-ico-sm" /> Ajouter une ligne
            </button>
        @endif

        <div class="m-callout {{ $overdrawn ? 'm-callout--danger' : '' }}">
            <dl>
                <dt>Total de la saisie</dt>
                <dd>
                    <span style="color:var(--ok)">+{{ \App\Support\Mobile\Fcfa::number($this->totalEntrees) }}</span>
                    &nbsp;<span style="color:var(--danger)">−{{ \App\Support\Mobile\Fcfa::number($this->totalSorties) }}</span>
                    &nbsp;= {{ \App\Support\Mobile\Fcfa::format($this->totalEntrees - $this->totalSorties) }}
                </dd>
            </dl>
            @if ($overdrawn)
                <span><x-m.icon name="exclamation-triangle" class="m-ico-sm" /> Les sorties dépassent le solde de la caisse.</span>
            @endif
        </div>

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save" @disabled($overdrawn)>
                <span wire:loading.remove wire:target="save">Enregistrer {{ count($lines) > 1 ? count($lines).' mouvements' : '' }}</span>
                <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
    </form>
</div>
