<div>
    <x-m.appbar title="Import de données" :back="route('m.more')" />

    <x-m.chips model="type" :current="$type" :options="\App\Livewire\Mobile\Admin\Import::TYPES" />

    <form wire:submit="import" class="m-form" novalidate style="padding-top:14px">
        <div class="m-callout"><x-m.icon name="information-circle" class="m-ico-sm" /><span style="font-size:13.5px">{{ \App\Livewire\Mobile\Admin\Import::FORMATS[$type] }} <br><b>*</b> = obligatoire. Première ligne = noms des colonnes.</span></div>

        <x-m.field label="Fichier" :error="$errors->first('file')">
            @if ($file)
                <div class="m-select" style="cursor:default">
                    <span class="m-row__lead m-row__lead--ok"><x-m.icon name="document-text" class="m-ico-sm" /></span>
                    <span class="m-row__body"><span class="m-row__title" style="display:block">{{ $file->getClientOriginalName() }}</span>
                        <span class="m-row__sub" style="display:block">{{ number_format($file->getSize() / 1024, 0, ',', ' ') }} Ko</span></span>
                    <button type="button" class="m-iconbtn" wire:click="removeFile" aria-label="Retirer"><x-m.icon name="x-mark" /></button>
                </div>
            @else
                <label class="m-select" style="cursor:pointer">
                    <span class="m-row__lead"><x-m.icon name="arrow-up-tray" class="m-ico-sm" /></span>
                    <span class="m-select__placeholder">Choisir un fichier CSV ou Excel</span>
                    <input type="file" wire:model="file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
                </label>
            @endif
            <div wire:loading wire:target="file" class="m-row__sub" style="margin:6px 4px 0">Envoi du fichier…</div>
        </x-m.field>

        @if ($results)
            <div class="m-callout {{ ! empty($results['fatal']) || ($results['errors'] ?? 0) > 0 ? 'm-callout--warn' : '' }}" style="margin-top:14px;display:block">
                @if (! empty($results['fatal']))
                    <b>Import impossible</b><br>{{ $results['fatal'] }}
                @else
                    <b>{{ $results['success'] }}</b> importée(s) sur {{ $results['rows'] }} ligne(s)@if ($results['skipped']) — {{ $results['skipped'] }} doublon(s) ignoré(s)@endif — {{ $results['errors'] }} refusée(s)
                    @foreach ($results['failures'] as $f)
                        <div class="m-row__sub" style="white-space:normal;margin-top:4px">Ligne {{ $f['row'] }} : {{ $f['message'] }}</div>
                    @endforeach
                @endif
            </div>
        @endif

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="import,file" @disabled(! $file)>
                <span wire:loading.remove wire:target="import">Lancer l'import</span>
                <span wire:loading wire:target="import" class="m-spin"></span>
            </button>
        </div>
    </form>
</div>
