{{-- Rappel affiché aux comptes en lecture seule : l'écran est consultable, aucune action de modification n'est proposée. --}}
@if (auth()->user()?->isReadOnly())
    <div class="m-note" role="note"><x-m.icon name="eye" class="m-ico-sm" /> <span>Consultation seule : vous pouvez tout voir, mais rien modifier.</span></div>
@endif
