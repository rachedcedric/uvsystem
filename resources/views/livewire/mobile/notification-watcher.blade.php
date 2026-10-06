<span wire:poll.30s.visible="check" class="m-tab__badge" data-unread="{{ $count }}" @if ($count < 1) hidden @endif>{{ $count > 99 ? '99+' : $count }}</span>
