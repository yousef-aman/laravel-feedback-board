<div class="fb-scope fb-reports" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <h2 class="fb-reports__title">{{ __('feedback-board::feedback-board.board.my_reports') }}</h2>

    <div class="fb-reports__layout">
        <section class="fb-panel">
            <h3 class="fb-panel__title">{{ __('feedback-board::feedback-board.board.new_report') }}</h3>
            @livewire('feedback-board::feedback-form')
        </section>

        <section>
            @if ($items->isEmpty())
                <p class="fb-empty">{{ __('feedback-board::feedback-board.board.empty_reports') }}</p>
            @else
                <ul class="fb-list">
                    @foreach ($items as $item)
                        <li wire:key="item-{{ $item->id }}">
                            <button type="button" class="fb-list__item" wire:click="open({{ $item->id }})">
                                <span class="fb-list__title" dir="auto">{{ $item->title }}</span>
                                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                                @if ($item->created_at)
                                    <time class="fb-list__time" datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
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
                    @livewire('feedback-board::feedback-item-modal', ['item' => $selected], key('modal-'.$selected->id))
                </div>
            </div>
        </div>
    @endif
</div>
