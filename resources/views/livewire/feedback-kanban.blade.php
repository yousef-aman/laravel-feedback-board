{{-- resources/views/livewire/feedback-kanban.blade.php --}}
<div class="fb-kanban" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:dragover.prevent="">
    @foreach ($columns as $status => $items)
        <div class="fb-col" data-status="{{ $status }}"
             x-on:drop="$wire.moveTo($event.dataTransfer.getData('id'), '{{ $status }}')">
            <h4>{{ \YousefAman\FeedbackBoard\Enums\FeedbackStatus::from($status)->label() }}</h4>
            @foreach ($items as $item)
                <div class="fb-card" wire:key="k-{{ $item->id }}"
                     wire:click="open({{ $item->id }})"
                     draggable="true" x-on:dragstart="$event.dataTransfer.setData('id', '{{ $item->id }}')">
                    {{ $item->title }}
                    @if ($item->priority)<span class="fb-prio">{{ $item->priority->label() }}</span>@endif
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($selected)
        <div class="fb-detail-pane">
            @livewire('feedback-board::feedback-detail', ['item' => $selected], key('detail-'.$selected->id))
        </div>
    @endif
</div>
