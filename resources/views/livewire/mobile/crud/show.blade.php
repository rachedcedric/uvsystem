@php($row = $r::row($record))
<div>
    <x-m.appbar :title="$r::title($record)" :sub="$r::label()" :back="route('m.crud.index', $resource)">
        @if ($canEdit)
            <a href="{{ route('m.crud.edit', [$resource, $recordId]) }}" wire:navigate class="m-iconbtn" aria-label="Modifier" data-haptic>
                <x-m.icon name="pencil-square" />
            </a>
        @endif
    </x-m.appbar>

    <div class="m-profile" style="padding-top:10px">
        <div class="m-profile__avatar"><x-m.icon :name="$row['icon'] ?? $r::icon()" /></div>
        <div>
            <div class="m-profile__name">{{ $row['title'] }}</div>
            @if (! empty($row['sub'])) <div class="m-profile__sub">{{ $row['sub'] }}</div> @endif
            @if (! empty($row['badge'])) <span class="m-badge {{ ! empty($row['badgeTone']) ? 'm-badge--'.$row['badgeTone'] : '' }}" style="margin-top:6px">{{ $row['badge'] }}</span> @endif
        </div>
    </div>

    <div class="m-section">
        <dl class="m-group" style="margin-bottom:0">
            @foreach ($details as $label => $value)
                <div class="m-kv"><dt>{{ $label }}</dt><dd>{{ $value !== '' && $value !== null ? $value : '—' }}</dd></div>
            @endforeach
        </dl>
    </div>

    @if ($canDelete)
        <button type="button" class="m-danger-link" data-sheet-open="delete-sheet">
            <x-m.icon name="trash" class="m-ico-sm" /> Supprimer
        </button>

        <div id="delete-sheet" class="m-sheet" role="dialog" aria-label="Confirmer la suppression">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Supprimer « {{ $r::title($record) }} » ?</div>
            <p style="margin:0 20px 16px;color:var(--fg-2)">{{ $r::deleteWarning($record) }}</p>
            <div style="padding:0 16px;display:grid;gap:10px">
                <button type="button" class="m-btn" style="background:var(--danger)" wire:click="delete" @click="MobileUI.closeSheets()">Oui, supprimer</button>
                <button type="button" class="m-btn m-btn--ghost" data-sheet-close>Annuler</button>
            </div>
        </div>
    @endif
</div>
