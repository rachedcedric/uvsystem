<div>
    <x-m.appbar :title="$isEdit ? 'Modifier '.mb_strtolower($r::label()) : $r::newTitle()"
                :back="$backUrl" />

    <form wire:submit="save" class="m-form" novalidate>
        @error('form')
            <div class="m-callout m-callout--danger"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>{{ $message }}</span></div>
        @enderror

        @foreach ($fields as $field)
            @php
                $path = 'data.'.$field->name;
                $fieldId = 'crud-'.preg_replace('/[^a-z0-9_-]+/i', '-', $field->name);
                $hasError = $errors->has($path);
                $describedBy = implode(' ', array_filter([
                    $field->hint ? $fieldId.'-hint' : null,
                    $hasError ? $fieldId.'-error' : null,
                ]));
                $isChoiceGroup = in_array($field->type, ['select', 'multi'], true);
            @endphp
            <x-m.field :label="$field->label" :error="$errors->first($path)" :for="$fieldId" :hint="$field->hint" :group="$isChoiceGroup">
                @switch($field->type)
                    @case('textarea')
                        <textarea id="{{ $fieldId }}" wire:model="{{ $path }}" rows="3" class="m-input @if ($hasError) m-input--error @endif" style="height:auto;padding:12px 14px" placeholder="{{ $field->placeholder }}"
                                  @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif></textarea>
                        @break

                    @case('money')
                        <x-m.money :model="$path" :id="$fieldId" :described-by="$describedBy" :invalid="$hasError" />
                        @break

                    @case('toggle')
                        <button id="{{ $fieldId }}" type="button" role="switch" aria-checked="{{ ! empty($data[$field->name]) ? 'true' : 'false' }}"
                                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif
                                class="m-switch {{ ! empty($data[$field->name]) ? 'is-on' : '' }}" wire:click="$toggle('{{ $path }}')" data-haptic>
                            <span class="m-switch__knob"></span>
                            <span>{{ ! empty($data[$field->name]) ? 'Activé' : 'Désactivé' }}</span>
                        </button>
                        @break

                    @case('select')
                        @php($options = $field->resolveOptions())
                        @if (count($options) > 8)
                            <x-m.option-picker :options="$options" :model="$path" :selected="$data[$field->name] ?? null" :label="$field->label"
                                               :id="$fieldId" :labelled-by="$fieldId.'-label'" :described-by="$describedBy" :invalid="$hasError" />
                        @else
                            <div id="{{ $fieldId }}" class="m-chips" style="padding:0;flex-wrap:wrap" role="group" aria-labelledby="{{ $fieldId }}-label"
                                 @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif>
                                @foreach ($options as $value => $label)
                                    <button type="button" class="m-chip {{ (string) ($data[$field->name] ?? '') === (string) $value ? 'is-on' : '' }}"
                                            aria-pressed="{{ (string) ($data[$field->name] ?? '') === (string) $value ? 'true' : 'false' }}"
                                            wire:click="$set('{{ $path }}', '{{ $value }}')" data-haptic>{{ $label }}</button>
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('multi')
                        <div id="{{ $fieldId }}" class="m-chips" style="padding:0;flex-wrap:wrap" role="group" aria-labelledby="{{ $fieldId }}-label"
                             @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif>
                            @foreach ($field->resolveOptions() as $value => $label)
                                <button type="button" class="m-chip {{ in_array((string) $value, array_map('strval', (array) ($data[$field->name] ?? [])), true) ? 'is-on' : '' }}"
                                    aria-pressed="{{ in_array((string) $value, array_map('strval', (array) ($data[$field->name] ?? [])), true) ? 'true' : 'false' }}"
                                        wire:click="toggleChoice('{{ $field->name }}', '{{ $value }}')" data-haptic>{{ $label }}</button>
                            @endforeach
                        </div>
                        @break

                    @case('date')
                           <input id="{{ $fieldId }}" type="date" wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror"
                               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif>
                        @break

                    @case('datetime')
                           <input id="{{ $fieldId }}" type="datetime-local" wire:model="{{ $path }}" max="{{ now()->format('Y-m-d\TH:i') }}" class="m-input @error($path) m-input--error @enderror"
                               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif>
                        @break

                    @case('time')
                           <input id="{{ $fieldId }}" type="time" wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror"
                               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif>
                        @break

                    @case('color')
                           <input id="{{ $fieldId }}" type="color" wire:model="{{ $path }}" class="m-input @if ($hasError) m-input--error @endif" style="padding:6px;width:96px"
                               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif>
                        @break

                    @case('password')
                           <input id="{{ $fieldId }}" type="password" wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror" autocomplete="new-password"
                               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif
                               placeholder="{{ $isEdit ? 'Laisser vide pour ne pas changer' : '' }}">
                        @break

                    @default
                           <input id="{{ $fieldId }}" type="{{ in_array($field->type, ['email', 'tel']) ? $field->type : 'text' }}"
                               @if ($field->type === 'number') inputmode="decimal" @endif
                               wire:model="{{ $path }}" class="m-input @error($path) m-input--error @enderror"
                               placeholder="{{ $field->placeholder }}" @if ($field->maxLength) maxlength="{{ $field->maxLength }}" @endif
                               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($hasError) aria-invalid="true" @endif
                               autocomplete="off">
                @endswitch
            </x-m.field>
        @endforeach

        <div class="m-submit">
            <button type="submit" class="m-btn" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Enregistrer les modifications' : 'Enregistrer' }}</span>
                <span wire:loading wire:target="save" class="m-spin" role="status" aria-label="Chargement en cours"></span>
            </button>
        </div>
    </form>
</div>
