<div class="fb-scope fb-kanban" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:dragover.prevent="">
    @include('feedback-board::partials.assets')
    <div class="fb-kanban__columns">
        @foreach ($columns as $status => $items)
            @php($columnStatus = \YousefAman\FeedbackBoard\Enums\FeedbackStatus::from($status))
            <section class="fb-col" data-status="{{ $status }}" wire:key="col-{{ $status }}"
                     x-data="{ over: 0 }"
                     x-on:dragenter="$event.dataTransfer.types.includes('id') && over++"
                     x-on:dragleave="$event.dataTransfer.types.includes('id') && (over = Math.max(0, over - 1))"
                     x-on:drop.prevent="over = 0; $event.dataTransfer.getData('id') && $wire.moveTo($event.dataTransfer.getData('id'), '{{ $status }}')"
                     x-bind:class="{ 'fb-col--over': over > 0 }">
                <header class="fb-col__header">
                    <span class="fb-dot fb-dot--{{ $columnStatus->color() }}" aria-hidden="true"></span>
                    <h3 class="fb-col__title">{{ $columnStatus->label() }}</h3>
                    <span class="fb-col__count">{{ count($items) }}</span>
                </header>
                <div class="fb-col__body">
                    @forelse ($items as $item)
                        <div class="fb-card" wire:key="k-{{ $item->id }}"
                             role="button" tabindex="0"
                             wire:click="open({{ $item->id }})"
                             x-on:keydown.enter.prevent="$wire.open({{ $item->id }})"
                             x-on:keydown.space.prevent="$wire.open({{ $item->id }})"
                             draggable="true"
                             x-data="{ dragging: false }"
                             x-on:dragstart="dragging = true; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('id', '{{ $item->id }}')"
                             x-on:dragend="dragging = false"
                             x-bind:class="{ 'fb-card--dragging': dragging }">
                            <p class="fb-card__title" dir="auto">{{ $item->title }}</p>
                            <div class="fb-card__meta">
                                @if ($item->type)
                                    <span class="fb-chip">{{ $item->type->label() }}</span>
                                @endif
                                @if ($item->priority)
                                    <span class="fb-badge fb-badge--{{ $item->priority->color() }}">{{ $item->priority->label() }}</span>
                                @endif
                                @if ($item->created_at)
                                    <time class="fb-card__time" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="fb-col__empty">{{ __('feedback-board::feedback-board.board.empty_column') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    @if ($selected)
        <div class="fb-drawer" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="fb-drawer__backdrop" wire:click="close"></div>
            <div class="fb-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="fb-drawer-title" x-trap.noscroll="true">
                <div class="fb-drawer__bar">
                    <button type="button" class="fb-icon-btn" wire:click="close" aria-label="{{ __('feedback-board::feedback-board.close') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="fb-drawer__body">
                    @livewire('feedback-board::feedback-detail', ['item' => $selected], key('detail-'.$selected->id))
                </div>
            </div>
        </div>
    @endif
</div>
