<div>
    <x-m.appbar :title="$isEdit ? 'Modifier '.mb_strtolower($r::label()) : $r::newTitle()"
                :back="$backUrl" />

    <form wire:submit="save" class="m-form" novalidate>
        @error('form')
            <div class="m-callout m-callout--danger"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>{{ $message }}</span></div>
        @enderror

        @foreach ($fields as $field)
            @php($path = 'data.'.$field->name)
            <x-m.field :label="$field->label" :error="$errors->first($path)">
                @switch($field->type)
                    @case('textarea')
                        <textarea wire:model="{{ $path }}" rows="3" class="m-input" style="height:auto;padding:12px 14px" placeholder="{{ $field->placeholder }}"></textarea>
                        @break

                    @case('money')
                        <x-m.money :model="$path" />
                        @break

                    @case('toggle')
                        <button type="button" role="switch" aria-checked="{{ ! empty($data[$field->name]) ? 'true' : 'false' }}"
                                class="m-switch {{ ! empty($data[$field->name]) ? 'is-on' : '' }}" wire:click="$toggle('{{ $path }}')" data-haptic>
                            <span class="m-switch__knob"></span>
                            <span>{{ ! empty($data[$field->name]) ? 'Activé' : 'Désactivé' }}</span>
                        </button>
                        @break

                    @case('select')
                        @php($options = $field->resolveOptions())
                        @if (count($options) > 8)
                            <x-m.option-picker :options="$options" :model="$path" :selected="$data[$field->name] ?? null" :label="$field->label" />
                        @else
                            <div class="m-chips" style="padding:0;flex-wrap:wrap">
                                @foreach ($options as $value => $label)
                                    <button type="button" class="m-chip {{ (string) ($data[$field->name] ?? '') === (string) $value ? 'is-on' : '' }}"
                                            wire:click="$set('{{ $path }}', '{{ $value }}')" data-haptic>{{ $label }}</button>
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('multi')
                        <div class="m-chips" style="padding:0;flex-wrap:wrap">
                            @foreach ($field->resolveOptions() as $value => $label)
                                <button type="button" class="m-chip {{ in_array((string) $value, array_map('strval', (array) ($data[$field->name] ?? [])), true) ? 'is-on' : '' }}"
                                        wire:click="toggleChoice('{{ $field->name }}', '{{ $value }}')" data-haptic>{{ $label }}</button>
                            @endforeach
                        </div>
                        @break

                    @case('date')
                        <input type="date" wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror">
                        @break

                    @case('datetime')
                        <input type="datetime-local" wire:model="{{ $path }}" max="{{ now()->format('Y-m-d\TH:i') }}" class="m-input @error($path) m-input--error @enderror">
                        @break

                    @case('time')
                        <input type="time" wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror">
                        @break

                    @case('color')
                        <input type="color" wire:model="{{ $path }}" class="m-input" style="padding:6px;width:96px">
                        @break

                    @case('password')
                        <input type="password" wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror" autocomplete="new-password"
                               placeholder="{{ $isEdit ? 'Laisser vide pour ne pas changer' : '' }}">
                        @break

                    @default
                        <input type="{{ in_array($field->type, ['email', 'tel']) ? $field->type : 'text' }}"
                               @if ($field->type === 'number') inputmode="decimal" @endif
                               wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror"
                               placeholder="{{ $field->placeholder }}" @if ($field->maxLength) maxlength="{{ $field->maxLength }}" @endif
                               autocomplete="off">
                @endswitch
                @if ($field->hint) <div class="m-row__sub" style="margin:6px 4px 0">{{ $field->hint }}</div> @endif
            </x-m.field>
        @endforeach

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Enregistrer les modifications' : 'Enregistrer' }}</span>
                <span wire:loading wire:target="save" class="m-spin"></span>
            </button>
        </div>
    </form>
</div>
