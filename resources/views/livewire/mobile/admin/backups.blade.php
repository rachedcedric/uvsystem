<div>
    <x-m.appbar title="Sauvegardes" :back="route('m.more')" />

    <div class="m-actions">
        <button type="button" class="m-action" wire:click="run('full')" wire:loading.attr="disabled" data-haptic><x-m.icon name="cloud-arrow-up" /> Complète</button>
        <button type="button" class="m-action" wire:click="run('db')" wire:loading.attr="disabled" data-haptic><x-m.icon name="circle-stack" /> Base de données</button>
        <button type="button" class="m-action" wire:click="clean" wire:loading.attr="disabled" data-haptic><x-m.icon name="sparkles" /> Nettoyer</button>
    </div>
    <div wire:loading wire:target="run,clean" class="m-callout" style="margin:12px 12px 0"><span class="m-spin" style="width:16px;height:16px"></span><span>Opération en cours, patientez…</span></div>

    <x-m.section title="Sauvegardes disponibles">
        <div class="m-group">
            @forelse ($backups as $b)
                <x-m.row icon="archive-box" tone="muted" :title="$b['name']" :sub="$b['date']->translatedFormat('j M Y, H:i').' · '.$b['size']" :chevron="false" wire:key="b{{ $b['name'] }}">
                    <x-slot:end>
                        <a href="{{ route('m.sauvegardes.telecharger', ['name' => $b['name']]) }}" target="_blank" class="m-iconbtn" aria-label="Télécharger" data-haptic><x-m.icon name="arrow-down-tray" /></a>
                        <button type="button" class="m-iconbtn" aria-label="Supprimer" style="color:var(--danger)"
                                x-data @click="if (confirm('Supprimer cette sauvegarde ?')) $wire.delete('{{ $b['name'] }}')"><x-m.icon name="trash" /></button>
                    </x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="archive-box" title="Aucune sauvegarde" text="Lancez une sauvegarde complète ou de la base de données." />
            @endforelse
        </div>
    </x-m.section>
</div>
