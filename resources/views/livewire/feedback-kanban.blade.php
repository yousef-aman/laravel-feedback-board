<div class="fb-scope fb-kanban" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data x-on:dragover.prevent="">
    @include('feedback-board::partials.assets')

    <div class="fb-filters" role="group" aria-label="{{ __('feedback-board::feedback-board.kanban.filter_label') }}">
        @foreach ($filters as $filter)
            <button type="button" @class(['fb-filter', 'fb-tint' => $filter['active']]) aria-pressed="{{ $filter['active'] ? 'true' : 'false' }}" wire:click="filterType('{{ $filter['value'] }}')">
                @if ($filter['type'])
                    <x-feedback-board::icon :name="$filter['type']->icon()" />
                @endif
                {{ $filter['label'] }}
                <span class="fb-count">{{ $filter['count'] }}</span>
            </button>
        @endforeach
    </div>

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
                        @php($card = $cards[$item->id])
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
                            @if ($card['cover'])
                                {{-- Decorative (the title follows). A broken image is removed; x-init also catches one that failed before Alpine started. --}}
                                <img class="fb-card__cover" src="{{ $card['cover']->url() }}" alt="" loading="lazy" decoding="async" draggable="false"
                                     x-init="$el.complete && $el.naturalWidth === 0 && $el.remove()" x-on:error="$el.remove()">
                            @endif
                            <div class="fb-card__body">
                                <div class="fb-card__chips">
                                    @if ($item->priority)
                                        <span class="fb-badge fb-badge--{{ $item->priority->color() }} fb-card__prio">{{ $item->priority->label() }}</span>
                                    @endif
                                    <span class="fb-card__type"><x-feedback-board::icon :name="$item->type->icon()" />{{ $item->type->label() }}</span>
                                </div>
                                <p class="fb-card__title" dir="auto">{{ $item->title }}</p>
                                <div class="fb-card__foot">
                                    <span class="fb-card__ref">#{{ $item->id }}</span>
                                    @if ($item->replies_count > 0)
                                        <span class="fb-card__stat"><x-feedback-board::icon name="chat-bubble-oval-left-ellipsis" /><span aria-hidden="true">{{ $item->replies_count }}</span><span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.comments', ['count' => $item->replies_count]) }}</span></span>
                                    @endif
                                    @if ($item->attachments_count > 0)
                                        <span class="fb-card__stat"><x-feedback-board::icon name="paper-clip" /><span aria-hidden="true">{{ $item->attachments_count }}</span><span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.attachments', ['count' => $item->attachments_count]) }}</span></span>
                                    @endif
                                    @if ($item->internal_count > 0)
                                        <span class="fb-card__stat fb-card__stat--internal"><x-feedback-board::icon name="lock-closed" /><span aria-hidden="true">{{ $item->internal_count }}</span><span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.internal', ['count' => $item->internal_count]) }}</span></span>
                                    @endif
                                    <span class="fb-card__people">
                                        @if ($card['reporter'] !== null)
                                            <x-feedback-board::avatar :name="$card['reporter']" />
                                            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.reporter', ['name' => $card['reporter']]) }}</span>
                                        @endif
                                        @if ($card['assignee'] !== null)
                                            <x-feedback-board::avatar :name="$card['assignee']" />
                                            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.kanban.assignee', ['name' => $card['assignee']]) }}</span>
                                        @endif
                                    </span>
                                </div>
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
